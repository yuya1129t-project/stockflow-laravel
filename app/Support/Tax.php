<?php

namespace App\Support;

use InvalidArgumentException;

/** 消費税の計算（docs/spec.md §1）。受注・発注 1 件の小計ごとに計算し、1 円未満は切り捨てる */
final class Tax
{
    /** 消費税率（%） */
    public const int RATE_PERCENT = 10;

    public static function of(int $subtotal): int
    {
        if ($subtotal < 0) {
            throw new InvalidArgumentException("小計は 0 以上の整数で指定してください: {$subtotal}");
        }

        return intdiv($subtotal * self::RATE_PERCENT, 100);
    }
}
