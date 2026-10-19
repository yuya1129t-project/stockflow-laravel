<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button, buttonVariants } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatNumber } from '@/lib/products';
import {
    displayDate,
    purchaseOrderListQuery,
    togglePurchaseOrderSort,
} from '@/lib/purchase-orders';
import { cn } from '@/lib/utils';
import { create, exportMethod, index, show } from '@/routes/purchase-orders';
import type { Pagination } from '@/types/products';
import type {
    PurchaseOrderFilters,
    PurchaseOrderListItem,
    PurchaseOrderSortKey,
    PurchaseOrderStatus,
    SupplierOption,
} from '@/types/purchase-orders';

defineOptions({
    layout: {
        breadcrumbs: [{ title: '発注', href: index() }],
    },
});

const props = defineProps<{
    orders: PurchaseOrderListItem[];
    pagination: Pagination;
    filters: PurchaseOrderFilters;
    suppliers: SupplierOption[];
    statuses: Record<PurchaseOrderStatus, string>;
    exportMaxRows: number;
}>();

const page = usePage();
const can = (permission: string) =>
    page.props.auth.permissions.includes(permission);

// 検索フォームの入力（送信すると URL のクエリにする。並べ替えは保ち、ページは 1 に戻す）
const search = reactive({
    q: props.filters.q,
    supplier: props.filters.supplier,
    status: props.filters.status,
    from: props.filters.from,
    to: props.filters.to,
});

function submitSearch() {
    router.get(
        index.url({
            query: purchaseOrderListQuery({
                ...search,
                sort: props.filters.sort,
                order: props.filters.order,
                page: 1,
            }),
        }),
    );
}

const columns: {
    label: string;
    sort?: PurchaseOrderSortKey;
    align?: 'right';
    class?: string;
}[] = [
    { label: '発注番号', sort: 'number' },
    { label: '発注日', sort: 'orderDate', class: 'hidden sm:table-cell' },
    { label: '仕入先', sort: 'supplier' },
    {
        label: '入荷予定日',
        sort: 'expectedDate',
        class: 'hidden md:table-cell',
    },
    { label: 'ステータス' },
    { label: '明細', align: 'right', class: 'hidden lg:table-cell' },
    { label: '合計（円）', sort: 'total', align: 'right' },
];

const listHref = (change: Partial<PurchaseOrderFilters>) =>
    index({ query: purchaseOrderListQuery({ ...props.filters, ...change }) });

const from = computed(() =>
    props.pagination.total === 0
        ? 0
        : (props.pagination.page - 1) * props.pagination.perPage + 1,
);
const to = computed(() =>
    Math.min(
        props.pagination.page * props.pagination.perPage,
        props.pagination.total,
    ),
);

const exportHref = computed(
    () =>
        exportMethod({
            query: purchaseOrderListQuery({ ...props.filters, page: 1 }),
        }).url,
);
// 上限を超えると出力は 413 になるので、押せないようにして理由を示す
const exportTooMany = computed(
    () => props.pagination.total > props.exportMaxRows,
);
const exportTooManyMessage = computed(
    () =>
        `CSV 出力は ${formatNumber(props.exportMaxRows)} 件までです。絞り込んでから出力してください`,
);

const statusVariant = (status: PurchaseOrderStatus) =>
    status === 'draft'
        ? ('outline' as const)
        : status === 'cancelled'
          ? ('secondary' as const)
          : ('default' as const);

const selectClass =
    'h-9 rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';
</script>

<template>
    <Head title="発注" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <div class="flex flex-wrap items-center gap-2">
            <h1 class="mr-auto text-xl font-semibold">発注</h1>
            <template v-if="can('csv.export')">
                <span
                    v-if="exportTooMany"
                    aria-disabled="true"
                    :title="exportTooManyMessage"
                    :class="
                        cn(
                            buttonVariants({ variant: 'outline', size: 'sm' }),
                            'pointer-events-none opacity-50',
                        )
                    "
                >
                    CSV 出力
                </span>
                <a
                    v-else
                    :href="exportHref"
                    download
                    :class="buttonVariants({ variant: 'outline', size: 'sm' })"
                    data-test="purchase-order-export"
                >
                    CSV 出力
                </a>
            </template>
            <Button v-if="can('order.edit')" size="sm" as-child>
                <Link :href="create()">新規作成</Link>
            </Button>
        </div>

        <form
            class="flex flex-wrap items-end gap-2 rounded-xl border p-3"
            role="search"
            @submit.prevent="submitSearch"
        >
            <div class="grid w-full gap-1 sm:w-48">
                <Label for="q" class="text-xs text-muted-foreground">
                    発注番号
                </Label>
                <Input
                    id="q"
                    v-model="search.q"
                    type="search"
                    placeholder="例: PO-20261019"
                />
            </div>
            <div class="grid gap-1">
                <Label for="supplier" class="text-xs text-muted-foreground">
                    仕入先
                </Label>
                <select
                    id="supplier"
                    v-model="search.supplier"
                    :class="cn(selectClass, 'max-w-60')"
                >
                    <option value="">すべて</option>
                    <option
                        v-for="s in suppliers"
                        :key="s.id"
                        :value="String(s.id)"
                    >
                        {{ s.code }} {{ s.name
                        }}{{ s.is_active ? '' : '（無効）' }}
                    </option>
                </select>
            </div>
            <div class="grid gap-1">
                <Label for="status" class="text-xs text-muted-foreground">
                    ステータス
                </Label>
                <select
                    id="status"
                    v-model="search.status"
                    :class="selectClass"
                >
                    <option value="">すべて</option>
                    <option
                        v-for="(label, value) in statuses"
                        :key="value"
                        :value="value"
                    >
                        {{ label }}
                    </option>
                </select>
            </div>
            <div class="grid gap-1">
                <Label for="from" class="text-xs text-muted-foreground">
                    発注日（から）
                </Label>
                <Input id="from" v-model="search.from" type="date" />
            </div>
            <div class="grid gap-1">
                <Label for="to" class="text-xs text-muted-foreground">
                    発注日（まで）
                </Label>
                <Input id="to" v-model="search.to" type="date" />
            </div>
            <div class="flex gap-2">
                <Button type="submit">検索</Button>
                <Button variant="ghost" as-child>
                    <Link :href="index()">条件をクリア</Link>
                </Button>
            </div>
        </form>

        <p v-if="exportTooMany" class="text-sm text-destructive">
            {{ exportTooManyMessage }}
        </p>

        <p class="text-sm text-muted-foreground" aria-live="polite">
            <template v-if="pagination.total === 0">
                該当する発注はありません
            </template>
            <template v-else>
                全 {{ formatNumber(pagination.total) }} 件中
                {{ formatNumber(from) }}〜{{ formatNumber(to) }} 件
            </template>
        </p>

        <div
            v-if="pagination.total > 0"
            class="overflow-x-auto rounded-xl border"
        >
            <table class="w-full text-sm">
                <thead class="border-b bg-muted/50 text-left">
                    <tr>
                        <th
                            v-for="column in columns"
                            :key="column.label"
                            scope="col"
                            :class="
                                cn(
                                    'px-3 py-2 font-medium whitespace-nowrap',
                                    column.align === 'right' && 'text-right',
                                    column.class,
                                )
                            "
                            :aria-sort="
                                column.sort === filters.sort
                                    ? filters.order === 'asc'
                                        ? 'ascending'
                                        : 'descending'
                                    : undefined
                            "
                        >
                            <Link
                                v-if="column.sort"
                                :href="
                                    listHref(
                                        togglePurchaseOrderSort(
                                            filters,
                                            column.sort,
                                        ),
                                    )
                                "
                                class="inline-flex items-center gap-1 hover:underline"
                            >
                                {{ column.label }}
                                <span aria-hidden="true" class="text-xs">
                                    {{
                                        column.sort === filters.sort
                                            ? filters.order === 'asc'
                                                ? '▲'
                                                : '▼'
                                            : '↕'
                                    }}
                                </span>
                            </Link>
                            <template v-else>{{ column.label }}</template>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="o in orders"
                        :key="o.id"
                        :class="
                            cn(
                                'border-b last:border-0',
                                o.status === 'cancelled' &&
                                    'text-muted-foreground',
                            )
                        "
                        data-test="purchase-order-row"
                    >
                        <td class="px-3 py-2 font-mono whitespace-nowrap">
                            <Link
                                :href="show(o.id)"
                                class="underline-offset-4 hover:underline"
                            >
                                {{ o.number }}
                            </Link>
                        </td>
                        <td
                            class="hidden px-3 py-2 whitespace-nowrap tabular-nums sm:table-cell"
                        >
                            {{ displayDate(o.order_date) }}
                        </td>
                        <td class="min-w-32 px-3 py-2">
                            <span class="font-mono text-xs">{{
                                o.supplier.code
                            }}</span>
                            {{ o.supplier.name }}
                        </td>
                        <td
                            class="hidden px-3 py-2 whitespace-nowrap tabular-nums md:table-cell"
                        >
                            {{ displayDate(o.expected_date) }}
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap">
                            <Badge :variant="statusVariant(o.status)">
                                {{ o.status_label }}
                            </Badge>
                        </td>
                        <td
                            class="hidden px-3 py-2 text-right tabular-nums lg:table-cell"
                        >
                            {{ formatNumber(o.line_count) }} 行
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">
                            {{ formatNumber(o.total) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav
            v-if="pagination.totalPages > 1"
            class="flex items-center justify-between gap-2"
            aria-label="ページ送り"
        >
            <Button
                v-if="pagination.page > 1"
                variant="outline"
                size="sm"
                as-child
            >
                <Link :href="listHref({ page: pagination.page - 1 })">
                    前へ
                </Link>
            </Button>
            <span v-else />
            <span class="text-sm text-muted-foreground">
                {{ pagination.page }} / {{ pagination.totalPages }} ページ
            </span>
            <Button
                v-if="pagination.page < pagination.totalPages"
                variant="outline"
                size="sm"
                as-child
            >
                <Link :href="listHref({ page: pagination.page + 1 })">
                    次へ
                </Link>
            </Button>
            <span v-else />
        </nav>
    </div>
</template>
