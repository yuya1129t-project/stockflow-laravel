import type { OrderAmounts, OrderLineForm } from '@/types/orders';

/**
 * 受注・発注の金額の表示用の計算（App\Orders\OrderAmounts と同じ式。保存する金額はサーバーで明細から計算する）。
 * 小計 = Σ 数量 × 単価、税 = 小計 × 税率 の 1 円未満切り捨て、合計 = 小計 + 税
 */

/** フォームの値を整数にする（全角数字・3 桁区切りも受け付ける。整数でなければ null） */
export function parseInteger(value: string): number | null {
    const halfWidth = value
        .trim()
        .replace(/[０-９]/g, (c) =>
            String.fromCharCode(c.charCodeAt(0) - 0xfee0),
        )
        .replace(/，/g, ',');
    const normalized = /^\d{1,3}(,\d{3})+$/.test(halfWidth)
        ? halfWidth.replaceAll(',', '')
        : halfWidth;
    if (!/^\d+$/.test(normalized)) return null;
    const number = Number(normalized);
    return Number.isSafeInteger(number) ? number : null;
}

/** 明細 1 行の金額（数量・単価が整数でなければ null） */
export function lineAmount(line: OrderLineForm): number | null {
    const quantity = parseInteger(line.quantity);
    const unitPrice = parseInteger(line.unit_price);
    return quantity === null || unitPrice === null
        ? null
        : quantity * unitPrice;
}

/** 明細から小計・税・合計を求める（金額が求められない行は 0 として数える） */
export function calcOrderAmounts(
    lines: readonly OrderLineForm[],
    taxRatePercent: number,
): OrderAmounts {
    const subtotal = lines.reduce(
        (sum, line) => sum + (lineAmount(line) ?? 0),
        0,
    );
    const tax = Math.floor((subtotal * taxRatePercent) / 100);
    return { subtotal, tax, total: subtotal + tax };
}

export const EMPTY_ORDER_LINE: OrderLineForm = {
    product_id: '',
    quantity: '1',
    unit_price: '',
};
