<?php

namespace App\Enums;

/** 取引先の役割（受注の顧客・発注の仕入先）。docs/spec.md §3 */
enum PartnerRole: string
{
    case Customer = 'customer';
    case Supplier = 'supplier';

    /**
     * この役割を持つ種別（顧客は customer か both、仕入先は supplier か both）。
     * 一覧の絞り込みと、受注・発注（#7・#8）の取引先の選択肢で使う（Partner::scopeWithRole）
     *
     * @return list<PartnerKind>
     */
    public function kinds(): array
    {
        return match ($this) {
            self::Customer => [PartnerKind::Customer, PartnerKind::Both],
            self::Supplier => [PartnerKind::Supplier, PartnerKind::Both],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Customer => '顧客',
            self::Supplier => '仕入先',
        };
    }
}
