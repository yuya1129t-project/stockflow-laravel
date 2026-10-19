<?php

namespace App\Enums;

/** 取引先の種類。受注の顧客は customer か both、発注の仕入先は supplier か both（docs/spec.md §3） */
enum PartnerKind: string
{
    case Customer = 'customer';
    case Supplier = 'supplier';
    case Both = 'both';
}
