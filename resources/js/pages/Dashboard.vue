<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import SalesTrendChart from '@/components/dashboard/SalesTrendChart.vue';
import { Badge } from '@/components/ui/badge';
import { formatRate } from '@/lib/dashboard';
import { formatNumber } from '@/lib/products';
import { displayDate } from '@/lib/purchase-orders';
import { stockListQuery } from '@/lib/stock';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { show as purchaseOrderShow } from '@/routes/purchase-orders';
import { show as salesOrderShow } from '@/routes/sales-orders';
import { index as stockIndex, show as stockShow } from '@/routes/stock';
import type { DashboardProps, MonthlySales } from '@/types/dashboard';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'ダッシュボード',
                href: dashboard(),
            },
        ],
    },
});

/** ダッシュボード（ログイン後のトップ。全ロールが閲覧可）。docs/spec.md §5-2 */
const props = defineProps<DashboardProps>();

const lowStockHref = stockIndex({ query: stockListQuery({ level: 'low' }) });

const monthLabel = (m: MonthlySales) => `${m.year}年${m.month}月`;

const change = computed(() => props.sales.change);
const changeArrow = computed(() =>
    change.value.diff > 0 ? '↑' : change.value.diff < 0 ? '↓' : '→',
);
const changeSign = computed(() => (change.value.diff > 0 ? '+' : ''));

/** 一覧に出しきれなかった件数（「他 N 件」） */
const rest = (count: number, shown: number) => Math.max(0, count - shown);

const cardClass = 'flex min-w-0 flex-col gap-3 rounded-xl border p-4';
const cardTitleClass = 'text-sm font-medium text-muted-foreground';
const rowClass =
    'flex flex-wrap items-center justify-between gap-x-3 gap-y-0.5 py-1.5';
const linkClass = 'font-mono underline-offset-4 hover:underline';
</script>

<template>
    <Head title="ダッシュボード" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h1 class="text-xl font-semibold">ダッシュボード</h1>
            <p class="text-xs text-muted-foreground">
                {{ displayDate(today) }} 時点
            </p>
        </div>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <section :class="cardClass" data-test="dashboard-sales">
                <h2 :class="cardTitleClass">
                    今月の受注額（{{ monthLabel(sales.current) }}）
                </h2>
                <p class="text-2xl font-semibold tabular-nums">
                    {{ formatNumber(sales.current.total) }}
                    <span class="ml-1 text-sm font-normal">円</span>
                </p>
                <div class="space-y-0.5">
                    <p class="text-sm text-muted-foreground">
                        前月比
                        <span class="font-medium text-foreground tabular-nums">
                            <span aria-hidden="true">{{ changeArrow }} </span
                            >{{ changeSign
                            }}{{ formatNumber(change.diff) }} 円{{
                                change.rate === null
                                    ? '（前月の受注なし）'
                                    : `（${formatRate(change.rate)}）`
                            }}
                        </span>
                    </p>
                    <p class="text-xs text-muted-foreground">
                        ※今月は本日までの合計、前月は月全体の合計との比較です
                    </p>
                </div>
                <p class="text-xs text-muted-foreground">
                    確定・出荷済の受注の合計（税込・受注日基準）
                </p>
            </section>

            <section :class="cardClass" data-test="dashboard-unshipped">
                <h2 :class="cardTitleClass">未出荷の受注</h2>
                <p class="text-2xl font-semibold tabular-nums">
                    {{ formatNumber(unshipped.count) }}
                    <span class="ml-1 text-sm font-normal">件</span>
                </p>
                <p
                    :class="
                        cn(
                            'text-sm',
                            unshipped.overdue_count > 0
                                ? 'font-semibold text-destructive'
                                : 'text-muted-foreground',
                        )
                    "
                >
                    うち納期切れ {{ formatNumber(unshipped.overdue_count) }} 件
                </p>
                <p class="text-xs text-muted-foreground">
                    確定済で未出荷の受注
                </p>
            </section>

            <section :class="cardClass" data-test="dashboard-low-stock">
                <div class="flex items-baseline justify-between gap-2">
                    <h2 :class="cardTitleClass">発注点を下回る商品</h2>
                    <Link
                        :href="lowStockHref"
                        class="shrink-0 text-sm whitespace-nowrap underline-offset-4 hover:underline"
                    >
                        在庫を見る
                    </Link>
                </div>
                <p
                    :class="
                        cn(
                            'text-2xl font-semibold tabular-nums',
                            low_stock.count > 0 && 'text-destructive',
                        )
                    "
                >
                    {{ formatNumber(low_stock.count) }}
                    <span class="ml-1 text-sm font-normal">件</span>
                </p>
                <p class="text-xs text-muted-foreground">
                    有効な商品で 現在庫 &lt; 発注点
                </p>
            </section>

            <section :class="cardClass" data-test="dashboard-awaiting">
                <h2 :class="cardTitleClass">入荷待ちの発注</h2>
                <p class="text-2xl font-semibold tabular-nums">
                    {{ formatNumber(awaiting_receipt.count) }}
                    <span class="ml-1 text-sm font-normal">件</span>
                </p>
                <p
                    :class="
                        cn(
                            'text-sm',
                            awaiting_receipt.overdue_count > 0
                                ? 'font-semibold text-destructive'
                                : 'text-muted-foreground',
                        )
                    "
                >
                    うち予定日超過
                    {{ formatNumber(awaiting_receipt.overdue_count) }} 件
                </p>
                <p class="text-xs text-muted-foreground">
                    発注済で未入荷の発注
                </p>
            </section>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <section :class="cardClass">
                <h2 :class="cardTitleClass">月別の受注額（直近6か月）</h2>
                <SalesTrendChart :months="sales.trend" />
            </section>

            <section :class="cardClass">
                <div class="flex items-baseline justify-between gap-2">
                    <h2 :class="cardTitleClass">
                        発注点を下回る商品（不足の大きい順）
                    </h2>
                    <Link
                        :href="lowStockHref"
                        class="shrink-0 text-sm whitespace-nowrap underline-offset-4 hover:underline"
                    >
                        すべて見る
                    </Link>
                </div>
                <p
                    v-if="low_stock.items.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    発注点を下回る商品はありません
                </p>
                <template v-else>
                    <ul class="divide-y text-sm">
                        <li
                            v-for="p in low_stock.items"
                            :key="p.id"
                            :class="rowClass"
                        >
                            <span class="min-w-0">
                                <Link
                                    :href="stockShow(p.id)"
                                    :class="linkClass"
                                >
                                    {{ p.code }}
                                </Link>
                                <span class="ml-2">{{ p.name }}</span>
                            </span>
                            <span class="text-muted-foreground tabular-nums">
                                在庫 {{ formatNumber(p.stock) }} / 発注点
                                {{ formatNumber(p.reorder_point) }}
                                <span
                                    class="ml-2 font-semibold text-destructive"
                                >
                                    不足 {{ formatNumber(p.shortage)
                                    }}{{ p.unit }}
                                </span>
                            </span>
                        </li>
                    </ul>
                    <p
                        v-if="rest(low_stock.count, low_stock.items.length) > 0"
                        class="text-xs text-muted-foreground"
                    >
                        他
                        {{
                            formatNumber(
                                rest(low_stock.count, low_stock.items.length),
                            )
                        }}
                        件
                    </p>
                </template>
            </section>

            <section :class="cardClass" data-test="dashboard-overdue-orders">
                <h2 :class="cardTitleClass">
                    納期を過ぎた未出荷の受注（納期の古い順）
                </h2>
                <p
                    v-if="unshipped.overdue.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    納期を過ぎた受注はありません
                </p>
                <template v-else>
                    <ul class="divide-y text-sm">
                        <li
                            v-for="o in unshipped.overdue"
                            :key="o.id"
                            :class="rowClass"
                        >
                            <span class="min-w-0">
                                <Link
                                    :href="salesOrderShow(o.id)"
                                    :class="linkClass"
                                >
                                    {{ o.number }}
                                </Link>
                                <span class="ml-2 text-muted-foreground">
                                    {{ o.partner_name }}
                                </span>
                            </span>
                            <span
                                class="inline-flex items-center gap-1.5 font-semibold text-destructive tabular-nums"
                            >
                                <Badge variant="destructive">納期切れ</Badge>
                                納期 {{ displayDate(o.date) }}
                            </span>
                        </li>
                    </ul>
                    <p
                        v-if="
                            rest(
                                unshipped.overdue_count,
                                unshipped.overdue.length,
                            ) > 0
                        "
                        class="text-xs text-muted-foreground"
                    >
                        他
                        {{
                            formatNumber(
                                rest(
                                    unshipped.overdue_count,
                                    unshipped.overdue.length,
                                ),
                            )
                        }}
                        件
                    </p>
                </template>
            </section>

            <section :class="cardClass" data-test="dashboard-awaiting-orders">
                <h2 :class="cardTitleClass">
                    入荷待ちの発注（入荷予定日の近い順）
                </h2>
                <p
                    v-if="awaiting_receipt.items.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    入荷待ちの発注はありません
                </p>
                <template v-else>
                    <ul class="divide-y text-sm">
                        <li
                            v-for="o in awaiting_receipt.items"
                            :key="o.id"
                            :class="rowClass"
                        >
                            <span class="min-w-0">
                                <Link
                                    :href="purchaseOrderShow(o.id)"
                                    :class="linkClass"
                                >
                                    {{ o.number }}
                                </Link>
                                <span class="ml-2 text-muted-foreground">
                                    {{ o.partner_name }}
                                </span>
                            </span>
                            <span
                                :class="
                                    cn(
                                        'inline-flex items-center gap-1.5 tabular-nums',
                                        o.overdue &&
                                            'font-semibold text-destructive',
                                    )
                                "
                            >
                                <Badge v-if="o.overdue" variant="destructive">
                                    予定日超過
                                </Badge>
                                予定
                                {{ o.date ? displayDate(o.date) : '未設定' }}
                            </span>
                        </li>
                    </ul>
                    <p
                        v-if="
                            rest(
                                awaiting_receipt.count,
                                awaiting_receipt.items.length,
                            ) > 0
                        "
                        class="text-xs text-muted-foreground"
                    >
                        他
                        {{
                            formatNumber(
                                rest(
                                    awaiting_receipt.count,
                                    awaiting_receipt.items.length,
                                ),
                            )
                        }}
                        件
                    </p>
                </template>
            </section>
        </div>
    </div>
</template>
