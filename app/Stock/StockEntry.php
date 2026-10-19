<?php

namespace App\Stock;

use App\Enums\StockMovementType;
use App\Enums\StockRefType;
use InvalidArgumentException;

/**
 * 記録する入出庫 1 件（docs/spec.md §3「StockMovement」）。quantity は符号付き。
 *
 * 数量の符号が種別に合わない（in は正・out は負・adjust は 0 以外）、参照元（refType・refId）の片方だけを指定した、は
 * プログラムの誤りなので、作るときに InvalidArgumentException を投げる（DB の CHECK 制約と同じ規則。先に分かりやすく止める）
 */
final readonly class StockEntry
{
    /** stock_movements.quantity（INT）の範囲 */
    private const int MAX_ABS_QUANTITY = 2_147_483_647;

    public function __construct(
        public int $productId,
        public StockMovementType $type,
        public int $quantity,
        public ?string $reason = null,
        public ?StockRefType $refType = null,
        public ?int $refId = null,
    ) {
        if (! self::isValidQuantity($type, $quantity)) {
            throw new InvalidArgumentException("入出庫の数量が種別（{$type->value}）に合いません: {$quantity}");
        }
        if (($refType === null) !== ($refId === null)) {
            throw new InvalidArgumentException(sprintf(
                '入出庫の参照元は refType と refId を両方指定してください: %s / %s',
                $refType->value ?? 'null',
                $refId ?? 'null',
            ));
        }
    }

    /** 数量が種別の符号の規則に合うか（in > 0・out < 0・adjust ≠ 0。INT の範囲内） */
    public static function isValidQuantity(StockMovementType $type, int $quantity): bool
    {
        if (abs($quantity) > self::MAX_ABS_QUANTITY) {
            return false;
        }

        return match ($type) {
            StockMovementType::In => $quantity > 0,
            StockMovementType::Out => $quantity < 0,
            StockMovementType::Adjust => $quantity !== 0,
        };
    }
}
