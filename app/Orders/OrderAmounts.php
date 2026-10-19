<?php

namespace App\Orders;

use App\Support\Tax;
use InvalidArgumentException;

/**
 * 受注・発注の金額（docs/spec.md §1・§3）。
 *
 * - 小計 = Σ 数量 × 単価、税 = Tax::of(小計)（10%・1 件ごとに 1 円未満切り捨て）、合計 = 小計 + 税
 * - 金額は必ずサーバーで明細から計算して保存する（画面から送られた小計・合計は使わない）。
 *   画面の表示（resources/js/lib/orders.ts の calcOrderAmounts）も同じ式で計算する
 * - 合計の上限は MAX_TOTAL（DB の INT の範囲に収める）。超えたら calculate は null
 */
final readonly class OrderAmounts
{
    /** 伝票 1 件の合計の上限（10 億円未満。DB の列は INT） */
    public const int MAX_TOTAL = 999_999_999;

    public function __construct(
        public int $subtotal,
        public int $tax,
        public int $total,
    ) {}

    /**
     * 明細から小計・税・合計を求める。数量・単価は 0 以上の整数（検証済みの値）。合計が MAX_TOTAL を超えるときは null
     *
     * @param  iterable<array{quantity: int, unit_price: int}>  $lines
     */
    public static function calculate(iterable $lines): ?self
    {
        $subtotal = 0;
        foreach ($lines as $line) {
            if ($line['quantity'] < 0 || $line['unit_price'] < 0) {
                throw new InvalidArgumentException("数量・単価は 0 以上の整数で指定してください: {$line['quantity']} × {$line['unit_price']}");
            }
            // 1 行ずつ上限を確かめる（数量・単価の上限は 1 千万・1 億なので、1 行の積と途中の和は int の範囲に収まる）
            $subtotal += $line['quantity'] * $line['unit_price'];
            if ($subtotal > self::MAX_TOTAL) {
                return null;
            }
        }
        $tax = Tax::of($subtotal);
        $total = $subtotal + $tax;

        return $total > self::MAX_TOTAL ? null : new self($subtotal, $tax, $total);
    }

    /** @return array{subtotal: int, tax: int, total: int} */
    public function toArray(): array
    {
        return ['subtotal' => $this->subtotal, 'tax' => $this->tax, 'total' => $this->total];
    }
}
