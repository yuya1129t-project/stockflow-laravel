/** ダッシュボードの表示用の整形 */

/** 増減率（小数 1 桁）。丸めると 0 になる値は「±0%」（「+0.0%」「-0.0%」と出さない） */
export function formatRate(rate: number): string {
    const rounded = Number(rate.toFixed(1));
    if (rounded === 0) return '±0%';
    return `${rounded > 0 ? '+' : ''}${rounded.toFixed(1)}%`;
}
