<?php

namespace App\Users;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;

/**
 * 外部向け REST API（/api/v1。#10）の Bearer トークン認証（docs/spec.md §6-2）。Sanctum のガード（sanctum）に、
 * トークンの取り出し方と有効性の判定を差し込む（AppServiceProvider::boot で register を呼ぶ）。
 *
 * API のルートでの使い方（#10）:
 *
 *     Route::middleware(['api', ApiTokenAuth::MIDDLEWARE])->prefix('api/v1')->group(...);
 *     // 権限はトークンの持ち主のロール（$request->user()）で、Web と同じ Gate（->can(Permission::...)）で検査する
 *
 * - Bearer のトークンだけで認証する。Cookie のセッションでは通らない（config/sanctum.php の guard・stateful が空）
 * - トークンの形式（Sanctum の標準の <id>|sf_<40 文字の英数字><CRC32 の 16 進 8 文字>）でない値は DB を引かずに失敗にする
 * - 失効（revoked_at）・持ち主の無効化を毎回確かめる。持ち主（ロール・有効）は毎回 DB から読むので、
 *   ロール変更・無効化は次のリクエストから効く
 * - 失敗の理由（ヘッダーなし・形式違い・存在しない・失効済み・持ち主が無効）は区別しない。どれも Authenticate ミドルウェアの
 *   AuthenticationException になり、同じ 401 を返す（bootstrap/app.php。トークンの存在や状態を推測させない）
 * - 最終使用日時（last_used_at）は Sanctum の標準どおり、認証に成功したリクエストごとに更新する
 */
final class ApiTokenAuth
{
    /** API のルートに付けるミドルウェア（Sanctum のガードで認証し、Gate もそのユーザーで判定する） */
    public const string MIDDLEWARE = 'auth:sanctum';

    public static function register(): void
    {
        Sanctum::usePersonalAccessTokenModel(ApiToken::class);
        // 戻り値が空文字ならトークンなしとして扱われる（Laravel\Sanctum\Guard）
        Sanctum::getAccessTokenFromRequestUsing(fn (Request $request): string => self::tokenFromRequest($request) ?? '');
        Sanctum::authenticateAccessTokensUsing(fn (mixed $token, bool $isValid): bool => $isValid && self::isUsable($token));
    }

    /**
     * Authorization ヘッダーの Bearer トークン（RFC 6750。スキーム名は大文字・小文字を区別しない）。
     * ヘッダーがない・Bearer でない・トークンの形式でないときは null
     */
    public static function tokenFromRequest(Request $request): ?string
    {
        $header = $request->headers->get('Authorization');
        if (! is_string($header) || preg_match('/\ABearer +(\S+) *\z/i', $header, $matches) !== 1) {
            return null;
        }

        return self::isTokenFormat($matches[1]) ? $matches[1] : null;
    }

    /** Sanctum の標準の形式か（id は 1 以上の 19 桁まで。DB の範囲外の id で問い合わせない） */
    public static function isTokenFormat(string $value): bool
    {
        $prefix = config('sanctum.token_prefix', '');

        return preg_match('/\A[1-9][0-9]{0,18}\|'.preg_quote(is_string($prefix) ? $prefix : '', '/').'[A-Za-z0-9]{40}[0-9a-f]{8}\z/', $value) === 1;
    }

    /** 失効しておらず、持ち主が有効なユーザーか */
    private static function isUsable(mixed $token): bool
    {
        if (! $token instanceof ApiToken || $token->revoked_at !== null) {
            return false;
        }
        $owner = $token->tokenable;

        return $owner instanceof User && $owner->is_active;
    }
}
