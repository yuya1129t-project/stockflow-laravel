<?php

namespace App\Services\Partners;

use Illuminate\Support\Facades\RateLimiter;

/**
 * 住所検索の回数制限（ユーザーごとに 1 分 30 回まで）。外部 API（zipcloud）への中継に使われすぎないようにする。
 *
 * 判定と加算は 1 手順（RateLimiter::hit が加算後の回数を返す。キャッシュの increment は不可分）。
 * 「確認してから数える」の 2 手順にすると、上限の直前から同時に要求したときに全員が確認を通ってしまう。
 * 上限を超えた要求も数える（枠の期限は最初の要求から 1 分で、延びない）
 */
class AddressLookupRateLimiter
{
    public const int LIMIT = 30;

    public const int WINDOW_SECONDS = 60;

    public const string MESSAGE = '住所の検索回数が多すぎます。しばらくしてからもう一度お試しいただくか、住所を手入力してください';

    /**
     * 1 回分を数える
     *
     * @return int|null 上限を超えていれば、次に使えるまでの秒数（1 以上）。使えるなら null
     */
    public function attempt(int $userId): ?int
    {
        $key = "address-lookup:user:{$userId}";

        if (RateLimiter::hit($key, self::WINDOW_SECONDS) > self::LIMIT) {
            return max(1, RateLimiter::availableIn($key));
        }

        return null;
    }
}
