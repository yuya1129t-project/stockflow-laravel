<?php

namespace App\Services\Partners;

use App\Enums\PartnerKind;
use App\Enums\PartnerRole;
use App\Models\AuditLog;
use App\Models\Partner;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\User;
use App\Support\Partners\PostalCode;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 取引先の更新系（登録・編集・無効化／再有効化）。
 *
 * - 権限はルート（can:）と Policy（PartnerRequest・コントローラー）で検査済みのものとして呼ぶ
 * - 更新と操作履歴（audit_logs）は同じトランザクションで書く（spec §3。履歴の保存に失敗したら更新も巻き戻す）
 * - 変更のない編集・同じ状態への無効化／再有効化は何もしない（履歴も残さない）
 *
 * @phpstan-type PartnerInput array{code: string, name: string, kind: string, postal_code: ?string, address: ?string, phone: ?string, email: ?string}
 */
class PartnerService
{
    /** 操作履歴の target_type */
    public const string TARGET_TYPE = 'partner';

    /** 項目名（操作履歴の変更点の説明に使う） */
    public const array FIELD_LABELS = [
        'code' => '取引先コード',
        'name' => '取引先名',
        'kind' => '種別',
        'postal_code' => '郵便番号',
        'address' => '住所',
        'phone' => '電話番号',
        'email' => 'メールアドレス',
    ];

    /** @param  PartnerInput  $data */
    public function create(User $actor, array $data): Partner
    {
        return $this->guardUniqueCode($data['code'], fn () => DB::transaction(function () use ($actor, $data) {
            $partner = Partner::create($data);
            $this->record($actor, 'create', $partner, "取引先 {$partner->code}「{$partner->name}」を登録しました");

            return $partner;
        }));
    }

    /**
     * 編集する（取引先コードも変更できる。一意でなければ検証エラー）。
     * 受注・発注で使われている役割（顧客・仕入先）を外す種別の変更は、種別の検証エラーにする
     *
     * @param  PartnerInput  $data
     * @return bool 変更したか（変更がなければ false）
     */
    public function update(User $actor, Partner $partner, array $data): bool
    {
        return $this->guardUniqueCode($data['code'], fn () => DB::transaction(function () use ($actor, $partner, $data) {
            // 行をロックしてから受注・発注を数える（同時に走る種別の変更・伝票の作成と矛盾させない）
            $locked = Partner::query()->lockForUpdate()->findOrFail($partner->id);
            $changes = self::describeChanges($locked, $data);
            if ($changes === []) {
                return false;
            }

            $this->ensureKindChangeAllowed($locked, PartnerKind::from($data['kind']));

            $locked->fill($data)->save();
            $this->record($actor, 'update', $locked, "取引先 {$locked->code} を更新しました（".implode('、', $changes).'）');
            $partner->setRawAttributes($locked->getAttributes(), true);

            return true;
        }));
    }

    /**
     * 無効化（is_active = false）または再有効化する（admin のみ。spec §2）
     *
     * @return bool 変更したか（すでにその状態なら false）
     */
    public function setActive(User $actor, Partner $partner, bool $isActive): bool
    {
        return DB::transaction(function () use ($actor, $partner, $isActive) {
            $locked = Partner::query()->lockForUpdate()->findOrFail($partner->id);
            if ($locked->is_active === $isActive) {
                return false;
            }

            $locked->is_active = $isActive;
            $locked->save();
            $this->record(
                $actor,
                $isActive ? 'reactivate' : 'deactivate',
                $locked,
                "取引先 {$locked->code}「{$locked->name}」を".($isActive ? '再度有効に' : '無効に').'しました',
            );
            $partner->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }

    /**
     * 種別の変更で、使用中の受注・発注と矛盾しないか（spec §3: 受注の顧客は customer か both、発注の仕入先は supplier か both）。
     * その役割を外す変更で、その役割の受注・発注があれば種別の検証エラー。
     * 件数は共有ロック付きで数える（数え終わってから確定までの間に、この取引先の伝票が追加されないようにする）
     */
    private function ensureKindChangeAllowed(Partner $partner, PartnerKind $after): void
    {
        $messages = [];

        if ($partner->kind->hasRole(PartnerRole::Customer) && ! $after->hasRole(PartnerRole::Customer)) {
            $count = SalesOrder::query()->where('customer_id', $partner->id)->sharedLock()->count();
            if ($count > 0) {
                $messages[] = "受注（{$count} 件）で使われているため、顧客の役割を外せません（「顧客」か「顧客・仕入先」にしてください）";
            }
        }

        if ($partner->kind->hasRole(PartnerRole::Supplier) && ! $after->hasRole(PartnerRole::Supplier)) {
            $count = PurchaseOrder::query()->where('supplier_id', $partner->id)->sharedLock()->count();
            if ($count > 0) {
                $messages[] = "発注（{$count} 件）で使われているため、仕入先の役割を外せません（「仕入先」か「顧客・仕入先」にしてください）";
            }
        }

        if ($messages !== []) {
            throw ValidationException::withMessages(['kind' => $messages]);
        }
    }

    /**
     * 変更点の説明（操作履歴の summary 用）。変更がなければ空配列
     *
     * @param  PartnerInput  $after
     * @return list<string>
     */
    public static function describeChanges(Partner $before, array $after): array
    {
        $changes = [];
        foreach (self::FIELD_LABELS as $field => $label) {
            $old = $field === 'kind' ? $before->kind->value : $before->getAttribute($field);
            $new = $after[$field];
            if ($old !== $new) {
                $changes[] = "{$label}: ".self::display($field, $old).' → '.self::display($field, $new);
            }
        }

        return $changes;
    }

    private static function display(string $field, mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return '（なし）';
        }

        return match ($field) {
            'kind' => PartnerKind::tryFrom($value)?->label() ?? $value,
            'postal_code' => PostalCode::format($value),
            default => $value,
        };
    }

    private function record(User $actor, string $action, Partner $partner, string $summary): void
    {
        AuditLog::create([
            'user_id' => $actor->id,
            'action' => $action,
            'target_type' => self::TARGET_TYPE,
            'target_id' => (string) $partner->id,
            'summary' => $summary,
        ]);
    }

    /**
     * 取引先コードの一意制約の違反（検証の後に同時に登録された場合）を、コードの検証エラーにする
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function guardUniqueCode(string $code, Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => "取引先コード「{$code}」はすでに登録されています"]);
        }
    }
}
