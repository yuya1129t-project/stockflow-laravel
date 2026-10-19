<?php

namespace App\Enums;

/** 入出庫の発生元の伝票（ref_type＋ref_id で指す。棚卸調整は null） */
enum StockRefType: string
{
    case SalesOrder = 'sales_order';
    case PurchaseOrder = 'purchase_order';
}
