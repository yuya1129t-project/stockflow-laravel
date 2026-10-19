<?php

namespace App\Enums;

/**
 * 受注ステータス（docs/spec.md §4）: draft → confirmed → shipped、draft／confirmed → cancelled。
 * shipped と cancelled は最終状態。明細の編集は draft のときだけ。confirmed には明細が 1 行以上必要
 */
enum SalesOrderStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case Shipped = 'shipped';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => '下書き',
            self::Confirmed => '確定',
            self::Shipped => '出荷済',
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
            self::Draft => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::Shipped, self::Cancelled],
            self::Shipped, self::Cancelled => [],
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
                ? "この受注は「{$this->label()}」のため、ステータスを変更できません"
                : "「{$this->label()}」から「{$to->label()}」には変更できません";
        }
        if ($to === self::Confirmed && $lineCount < 1) {
            return '明細が 1 行もないため、確定にできません';
        }

        return null;
    }
}
