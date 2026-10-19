<?php

namespace App\Enums;

/** 伝票番号の種類（SO-YYYYMMDD-NNN／PO-YYYYMMDD-NNN） */
enum DocumentKind: string
{
    case SalesOrder = 'SO';
    case PurchaseOrder = 'PO';
}
