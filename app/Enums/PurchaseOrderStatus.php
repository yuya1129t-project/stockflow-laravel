<?php

namespace App\Enums;

/** 発注ステータス（docs/spec.md §4）: draft → ordered → received、draft／ordered → cancelled */
enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case Ordered = 'ordered';
    case Received = 'received';
    case Cancelled = 'cancelled';
}
