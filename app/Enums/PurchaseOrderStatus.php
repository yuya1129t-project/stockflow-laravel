<?php

namespace App\Enums;

/**
 * 発注ステータス（docs/spec.md §4）: draft → ordered → received、draft／ordered → cancelled。
 * received と cancelled は最終状態。明細の編集は draft のときだけ。ordered には明細が 1 行以上必要
 */
enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case Ordered = 'ordered';
    case Received = 'received';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => '下書き',
            self::Ordered => '発注済',
            self::Received => '入荷済',
            self::Cancelled => '取消',
        };
    }

    /**
     * 次に進めるステータス（最終状態は空）
     *
     * @return list<self>
     */
    public function transitions(): array
    {
        return match ($this) {
            self::Draft => [self::Ordered, self::Cancelled],
            self::Ordered => [self::Received, self::Cancelled],
            self::Received, self::Cancelled => [],
        };
    }

    public function isFinal(): bool
    {
        return $this->transitions() === [];
    }

    /** 明細の編集ができるのは draft のときだけ */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /**
     * to へ遷移できないときの理由（日本語）。遷移できるなら null。lineCount は明細の行数
     */
    public function transitionError(self $to, int $lineCount): ?string
    {
        if (! in_array($to, $this->transitions(), true)) {
            return $this->isFinal()
                ? "この発注は「{$this->label()}」のため、ステータスを変更できません"
                : "「{$this->label()}」から「{$to->label()}」には変更できません";
        }
        if ($to === self::Ordered && $lineCount < 1) {
            return '明細が 1 行もないため、発注済にできません';
        }

        return null;
    }
}
