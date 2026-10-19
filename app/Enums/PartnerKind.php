<?php

namespace App\Enums;

/** 取引先の種類。受注の顧客は customer か both、発注の仕入先は supplier か both（docs/spec.md §3） */
enum PartnerKind: string
{
    case Customer = 'customer';
    case Supplier = 'supplier';
    case Both = 'both';

    /** 画面に出す種別名 */
    public function label(): string
    {
        return match ($this) {
            self::Customer => '顧客',
            self::Supplier => '仕入先',
            self::Both => '顧客・仕入先',
        };
    }

    /** この種別がその役割（顧客・仕入先）を持つか */
    public function hasRole(PartnerRole $role): bool
    {
        return in_array($this, $role->kinds(), true);
    }

    /**
     * 一覧の種別の絞り込み: 「顧客」は customer と both、「仕入先」は supplier と both（spec §3 の顧客・仕入先の定義）。
     * 「顧客・仕入先」は both だけ
     *
     * @return list<self>
     */
    public function listFilter(): array
    {
        return match ($this) {
            self::Customer => PartnerRole::Customer->kinds(),
            self::Supplier => PartnerRole::Supplier->kinds(),
            self::Both => [self::Both],
        };
    }
}
