<script setup lang="ts">
import { computed } from 'vue';
import { formatNumber } from '@/lib/products';
import type { MonthlySales } from '@/types/dashboard';

/**
 * 月別受注額の棒グラフ（依存を増やさず CSS だけで描く）。今月の棒を濃くする。
 * 各棒にマウスを乗せると金額を表示し、数値は表でも確認できる
 */
const props = defineProps<{ months: MonthlySales[] }>();

const max = computed(() => Math.max(0, ...props.months.map((m) => m.total)));
const lastIndex = computed(() => props.months.length - 1);

const label = (m: MonthlySales) =>
    `${m.year}年${m.month}月: ${formatNumber(m.total)} 円`;

/** 棒の高さ（%）。0 でない月は最低でも 2% を出す（見えなくならないように） */
function height(m: MonthlySales): number {
    if (max.value === 0) return 0;
    return Math.max((m.total / max.value) * 100, m.total > 0 ? 2 : 0);
}
</script>

<template>
    <div class="space-y-2">
        <p v-if="max === 0" class="text-sm text-muted-foreground">
            直近6か月の受注はありません
        </p>
        <ol
            class="flex h-36 items-end gap-2 border-b border-border sm:gap-3"
            aria-label="月別の受注額"
            data-test="sales-trend-chart"
        >
            <li
                v-for="(m, i) in months"
                :key="m.key"
                class="group flex h-full flex-1 items-end"
                :title="label(m)"
            >
                <span class="sr-only">{{ label(m) }}</span>
                <span
                    aria-hidden="true"
                    :class="
                        i === lastIndex
                            ? 'w-full rounded-t-[4px] bg-chart-4'
                            : 'w-full rounded-t-[4px] bg-chart-2 group-hover:bg-chart-3'
                    "
                    :style="{ height: `${height(m)}%` }"
                />
            </li>
        </ol>
        <div
            aria-hidden="true"
            class="flex gap-2 text-center text-xs text-muted-foreground sm:gap-3"
        >
            <span
                v-for="(m, i) in months"
                :key="m.key"
                :class="
                    i === lastIndex
                        ? 'flex-1 font-medium text-foreground'
                        : 'flex-1'
                "
            >
                {{ m.month }}月
            </span>
        </div>
        <details class="text-sm">
            <summary class="cursor-pointer text-xs text-muted-foreground">
                数値を表で見る
            </summary>
            <table class="mt-2 w-full text-sm">
                <thead>
                    <tr class="text-xs text-muted-foreground">
                        <th class="text-left font-normal">月</th>
                        <th class="text-right font-normal">受注額（円）</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="m in months" :key="m.key">
                        <td>{{ m.year }}年{{ m.month }}月</td>
                        <td class="text-right tabular-nums">
                            {{ formatNumber(m.total) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </details>
    </div>
</template>
