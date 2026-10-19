<?php

namespace App\Enums;

/** 受注ステータス（docs/spec.md §4）: draft → confirmed → shipped、draft／confirmed → cancelled */
enum SalesOrderStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case Shipped = 'shipped';
    case Cancelled = 'cancelled';
}
