<?php

namespace App\Users;

use App\Auth\Permission;
use App\Enums\Role;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;

/**
 * ユーザー管理・API トークンの更新系（docs/spec.md §2・§3・§5-8・§6-2）。すべて admin のみ。
 *
 * - 権限はルート（can:）と Policy（Form Request・コントローラー）で検査済みの前提。入力は検証済み（UserValidation）
 * - 自分自身の無効化・ロール変更は不可（spec §3）。デモ用アカウントは無効化・ロール変更・パスワード再設定を拒否する（DemoAccounts）
 * - 更新と操作履歴（audit_logs）は同じトランザクションで書く。トランザクションの中で、操作する admin（actor）と対象の行を
 *   id の順に FOR UPDATE でロックし（同じユーザーへの操作（無効化とトークン発行など）を直列にする。id の順にそろえるのは、
 *   2 人の admin が互いを操作したときのデッドロックを避けるため）、actor がいまも有効な admin かを確かめ直す
 *   （認可の後に降格・無効化されていたら 403）
 * - 有効な admin が 0 人になる変更は拒否する。admin の顔ぶれを変えうる操作（ロール変更・無効化／再有効化）は、
 *   トランザクションを始める前に MySQL のユーザーロック（GET_LOCK）で直列にしてから数える。
 *   行ロックだけの操作（パスワード再設定・トークン）はこのロックを待たないので、待ちが循環しない
 * - 無効化・再有効化・パスワード再設定では、対象のセッション（sessions テーブル。SESSION_DRIVER=database の前提）を削除し、
 *   「ログインしたままにする」の Cookie（remember_token）も作り直す
 */
final class UserService
{
    /** 操作履歴の target_type（ログインの記録と同じ） */
    public const string TARGET_TYPE = 'user';

    public const string TOKEN_TARGET_TYPE = 'api_token';

    /** 1 ユーザーが持てる有効な（失効していない）トークンの数の上限 */
    public const int MAX_ACTIVE_TOKENS_PER_USER = 10;

    /** admin の顔ぶれを変えうる操作を直列にするユーザーロックの名前（DB 名を付ける。GET_LOCK はサーバー全体で共有のため） */
    public const string ADMIN_SET_LOCK = 'stockflow:admin-set';

    /** そのロックを待つ上限（秒） */
    public const int ADMIN_SET_LOCK_TIMEOUT_SECONDS = 10;

    public const string SELF_ROLE_MESSAGE = '自分自身のロールは変更できません';

    public const string SELF_DEACTIVATE_MESSAGE = '自分自身を無効にすることはできません';

    public const string LAST_ADMIN_MESSAGE = '有効な管理者が 1 人もいなくなるため、この変更はできません';

    public const string INACTIVE_TOKEN_MESSAGE = '無効なユーザーには API トークンを発行できません';

    public const string TOKEN_NOT_FOUND_MESSAGE = 'API トークンが見つかりません';

    /**
     * 登録
     *
     * @param  array{name: string, email: string, role: Role, password: string}  $input
     *
     * @throws ValidationException メールアドレスが登録済み（検証の後に別の操作で登録された場合）
     */
    public function create(User $actor, array $input): User
    {
        // ハッシュ化は遅いので、トランザクション（ロック）の外で行う
        $hash = Hash::make($input['password']);

        try {
            return DB::transaction(function () use ($actor, $input, $hash) {
                $this->lockActorAndTarget($actor, null);
                $user = new User;
                $user->name = $input['name'];
                $user->email = $input['email'];
                $user->password = $hash;
                $user->role = $input['role'];
                $user->is_active = true;
                // admin が登録したアドレスとして扱う（確認メールの仕組みは持たない）
                $user->forceFill(['email_verified_at' => now()])->save();
                $this->log($actor, 'create', self::TARGET_TYPE, $user->id, "ユーザー{$this->label($user)}を{$user->role->label()}として登録しました");

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => "メールアドレス「{$input['email']}」はすでに登録されています"]);
        }
    }

    /**
     * ロール変更（自分自身・デモ用アカウントは不可）。同じロールなら何もしない
     *
     * @return bool 変更したか
     */
    public function changeRole(User $actor, User $target, Role $role): bool
    {
        if ($target->id === $actor->id) {
            throw self::invalid(self::SELF_ROLE_MESSAGE);
        }
        self::rejectDemo($target);

        return $this->withAdminSetLock(fn () => DB::transaction(function () use ($actor, $target, $role) {
            $current = $this->lockActorAndTarget($actor, $target->id);
            if ($current->role === $role) {
                return false;
            }
            if ($this->removesLastAdmin($current, $role, $current->is_active)) {
                throw self::invalid(self::LAST_ADMIN_MESSAGE);
            }
            $before = $current->role;
            $current->role = $role;
            $current->save();
            $this->log($actor, 'role_change', self::TARGET_TYPE, $current->id, "ユーザー{$this->label($current)}のロールを変更しました（{$before->label()} → {$role->label()}）");

            return true;
        }));
    }

    /**
     * 無効化（is_active = false）・再有効化（自分自身・デモ用アカウントは不可）。すでにその状態なら何もしない。
     *
     * 無効化では、対象のセッションをすべて削除し、有効な API トークンをすべて失効させる（再有効化してもトークンは戻らない）。
     * 再有効化でもセッションを削除する: 無効化と同時に進んでいたログインが無効化の後にセッションを作っていた場合
     * （パスワードの照合とセッションの保存の間は止められない）、そのセッションが再有効化で使えるようにならないようにする
     *
     * @return array{changed: bool, sessions: int, tokens: int} 変更したか・削除したセッション・失効させたトークンの数
     */
    public function setActive(User $actor, User $target, bool $active): array
    {
        if ($target->id === $actor->id) {
            throw self::invalid(self::SELF_DEACTIVATE_MESSAGE);
        }
        self::rejectDemo($target);

        return $this->withAdminSetLock(fn () => DB::transaction(function () use ($actor, $target, $active) {
            $current = $this->lockActorAndTarget($actor, $target->id);
            if ($current->is_active === $active) {
                return ['changed' => false, 'sessions' => 0, 'tokens' => 0];
            }
            if ($this->removesLastAdmin($current, $current->role, $active)) {
                throw self::invalid(self::LAST_ADMIN_MESSAGE);
            }
            $current->is_active = $active;
            $current->setRememberToken(Str::random(60));
            $current->save();
            $sessions = $this->deleteSessions($current);
            $tokens = $active ? 0 : $this->revokeAllTokens($current);
            $detail = $active
                ? "（セッション {$sessions} 件を削除）"
                : "（ログイン中のセッション {$sessions} 件を終了、API トークン {$tokens} 件を失効）";
            $this->log(
                $actor,
                $active ? 'reactivate' : 'deactivate',
                self::TARGET_TYPE,
                $current->id,
                "ユーザー{$this->label($current)}を".($active ? '再度有効に' : '無効に')."しました{$detail}",
            );

            return ['changed' => true, 'sessions' => $sessions, 'tokens' => $tokens];
        }));
    }

    /**
     * パスワード再設定（admin が新しいパスワードを設定する。デモ用アカウントは不可。自分自身は可）。
     * 対象のセッションは、自分自身の再設定でもすべて削除する（古いパスワードで入った人・漏れた Cookie を締め出す。
     * 自分の場合は操作したセッションも終わるので、コントローラーがログアウトさせてログイン画面へ移す）。
     * revokeTokens なら、対象の有効な API トークンも同じトランザクションで失効させる
     *
     * @return array{sessions: int, tokens: int} 削除したセッション・失効させたトークンの数
     */
    public function resetPassword(User $actor, User $target, string $password, bool $revokeTokens): array
    {
        self::rejectDemo($target);
        $hash = Hash::make($password);

        return DB::transaction(function () use ($actor, $target, $hash, $revokeTokens) {
            $current = $this->lockActorAndTarget($actor, $target->id);
            $current->password = $hash;
            $current->setRememberToken(Str::random(60));
            $current->save();
            $sessions = $this->deleteSessions($current);
            $tokens = $revokeTokens ? $this->revokeAllTokens($current) : 0;
            $detail = "（ログイン中のセッション {$sessions} 件を終了".($revokeTokens ? "、API トークン {$tokens} 件を失効" : '、API トークンは失効させず').'）';
            // パスワード（平文・ハッシュ）は履歴に残さない
            $this->log($actor, 'password_reset', self::TARGET_TYPE, $current->id, "ユーザー{$this->label($current)}のパスワードを再設定しました{$detail}");

            return ['sessions' => $sessions, 'tokens' => $tokens];
        });
    }

    /**
     * API トークンの発行。平文は戻り値（plainTextToken）でだけ返し、DB には SHA-256 のハッシュだけを保存する（Sanctum の標準）。
     * 無効なユーザーには発行しない。デモ用アカウントにも発行できる（REST API を試せるように）
     *
     * @throws ValidationException 無効なユーザー・有効なトークンが上限に達している
     */
    public function issueToken(User $actor, User $target, string $name): NewAccessToken
    {
        return DB::transaction(function () use ($actor, $target, $name) {
            $current = $this->lockActorAndTarget($actor, $target->id);
            if (! $current->is_active) {
                throw self::invalid(self::INACTIVE_TOKEN_MESSAGE);
            }
            if ($current->tokens()->whereNull('revoked_at')->count() >= self::MAX_ACTIVE_TOKENS_PER_USER) {
                throw self::invalid(sprintf(
                    '有効な API トークンは 1 ユーザーにつき %d 件までです。使っていないトークンを失効させてから発行してください',
                    self::MAX_ACTIVE_TOKENS_PER_USER,
                ));
            }
            $token = $current->createToken($name);
            $this->log($actor, 'token_create', self::TOKEN_TARGET_TYPE, $token->accessToken->getKey(), "ユーザー{$this->label($current)}の API トークン「{$name}」を発行しました");

            return $token;
        });
    }

    /**
     * API トークンの失効（revoked_at を設定する。行は消さない）。すでに失効していれば何もしない
     *
     * @param  int  $tokenId  対象のユーザーのトークンであること（ほかのユーザーのトークンは「見つかりません」）
     * @return bool 失効させたか
     *
     * @throws ValidationException トークンが見つからない
     */
    public function revokeToken(User $actor, User $target, int $tokenId): bool
    {
        return DB::transaction(function () use ($actor, $target, $tokenId) {
            $current = $this->lockActorAndTarget($actor, $target->id);
            $token = $current->tokens()->whereKey($tokenId)->lockForUpdate()->first();
            if (! $token instanceof ApiToken) {
                throw self::invalid(self::TOKEN_NOT_FOUND_MESSAGE);
            }
            if ($token->revoked_at !== null) {
                return false;
            }
            $token->revoked_at = now();
            $token->save();
            $this->log($actor, 'token_revoke', self::TOKEN_TARGET_TYPE, $token->id, "ユーザー{$this->label($current)}の API トークン「{$token->name}」を失効させました");

            return true;
        });
    }

    /**
     * 変更後に有効な admin が 0 人になるか（対象の行をロックし、admin の顔ぶれのロック（withAdminSetLock）を持った状態で呼ぶ）。
     * 操作する admin は自分自身を変更できず、ロックの後に有効な admin であることを確かめているので、通常の操作ではここで拒否されない
     * （多重の防御）
     */
    public function removesLastAdmin(User $target, Role $nextRole, bool $nextActive): bool
    {
        $wasAdmin = $target->is_active && $target->role === Role::Admin;
        $staysAdmin = $nextActive && $nextRole === Role::Admin;
        if (! $wasAdmin || $staysAdmin) {
            return false;
        }

        return ! User::query()->where('role', Role::Admin)->where('is_active', true)->whereKeyNot($target->id)->exists();
    }

    /**
     * admin の顔ぶれを変えうる操作を直列にする（MySQL の GET_LOCK。接続ごとのロックなので、終わったら必ず外す）。
     * トランザクションの外で呼ぶ: ロックを取ってからトランザクションを始めるので、前の操作の確定後の状態を読める
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function withAdminSetLock(Closure $callback): mixed
    {
        $connection = DB::connection();
        $name = self::ADMIN_SET_LOCK.':'.$connection->getDatabaseName();
        // 1: 取れた／0: 待ちの上限を過ぎた／NULL: エラー
        $acquired = $connection->scalar('SELECT GET_LOCK(?, ?)', [$name, self::ADMIN_SET_LOCK_TIMEOUT_SECONDS]);
        if ((int) $acquired !== 1) {
            throw self::invalid('ほかの管理者の操作と重なったため、変更できませんでした。もう一度お試しください');
        }

        try {
            return $callback();
        } finally {
            $connection->select('SELECT RELEASE_LOCK(?)', [$name]);
        }
    }

    /**
     * actor と対象（targetId。null なら actor だけ）の行を id の順に FOR UPDATE でロックしてから読み、
     * actor がいまも有効な admin かを確かめ直す
     *
     * @return ($targetId is null ? null : User) ロックした対象の行
     *
     * @throws AuthorizationException actor がもう有効な admin ではない
     */
    private function lockActorAndTarget(User $actor, ?int $targetId): ?User
    {
        $ids = array_values(array_unique(array_filter([$actor->id, $targetId], fn (?int $id) => $id !== null)));
        sort($ids);
        $rows = [];
        foreach ($ids as $id) {
            $row = User::query()->whereKey($id)->lockForUpdate()->first();
            if ($row instanceof User) {
                $rows[$id] = $row;
            }
        }

        $current = $rows[$actor->id] ?? null;
        if (! $current instanceof User || ! $current->is_active || ! Permission::UserManage->allows($current->role)) {
            throw new AuthorizationException('この操作を行う権限がありません');
        }
        if ($targetId === null) {
            return null;
        }

        return $rows[$targetId] ?? throw (new ModelNotFoundException)->setModel(User::class, [$targetId]);
    }

    private function deleteSessions(User $user): int
    {
        return DB::table('sessions')->where('user_id', $user->id)->delete();
    }

    private function revokeAllTokens(User $user): int
    {
        return $user->tokens()->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    private static function rejectDemo(User $target): void
    {
        if (DemoAccounts::isDemo($target)) {
            throw self::invalid(DemoAccounts::PROTECTED_MESSAGE);
        }
    }

    private static function invalid(string $message): ValidationException
    {
        return ValidationException::withMessages(['form' => $message]);
    }

    private function label(User $user): string
    {
        return "「{$user->name}」（{$user->email}）";
    }

    private function log(User $actor, string $action, string $targetType, int|string $targetId, string $summary): void
    {
        AuditLog::create([
            'user_id' => $actor->id,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => (string) $targetId,
            'summary' => $summary,
        ]);
    }
}
