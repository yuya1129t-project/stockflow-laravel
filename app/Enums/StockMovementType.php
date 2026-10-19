<?php

namespace App\Enums;

/** 入出庫の種類。数量は in が正、out が負、adjust（棚卸調整）が 0 以外（CHECK 制約） */
enum StockMovementType: string
{
    case In = 'in';
    case Out = 'out';
    case Adjust = 'adjust';
}
