<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    formatNumber,
    PRODUCT_STATUS_LABELS,
    toggleSort,
} from '@/lib/products';
import { STOCK_LEVEL_LABELS, stockListQuery } from '@/lib/stock';
import { cn } from '@/lib/utils';
import { index, show } from '@/routes/stock';
import type {
    Pagination,
    Product,
    ProductSortKey,
    ProductStatusFilter,
} from '@/types/products';
import type { StockFilters, StockLevelFilter } from '@/types/stock';

defineOptions({
    layout: {
        breadcrumbs: [{ title: '在庫', href: index() }],
    },
});

const props = defineProps<{
    products: Product[];
    pagination: Pagination;
    filters: StockFilters;
    categories: string[];
}>();

// 検索フォームの入力（送信すると URL のクエリにする。並べ替えは保ち、ページは 1 に戻す）
const search = reactive({
    q: props.filters.q,
    category: props.filters.category,
    status: props.filters.status,
    level: props.filters.level,
});

function submitSearch() {
    router.get(
        index.url({
            query: stockListQuery({
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
    sort?: ProductSortKey;
    align?: 'right';
    class?: string;
}[] = [
    { label: '商品コード', sort: 'code' },
    { label: '商品名', sort: 'name' },
    { label: 'カテゴリ', sort: 'category', class: 'hidden sm:table-cell' },
    { label: '単位', class: 'hidden md:table-cell' },
    {
        label: '発注点',
        sort: 'reorderPoint',
        align: 'right',
        class: 'hidden sm:table-cell',
    },
    { label: '現在庫', sort: 'stock', align: 'right' },
];

const listHref = (change: Partial<StockFilters>) =>
    index({ query: stockListQuery({ ...props.filters, ...change }) });

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

const statuses = Object.entries(PRODUCT_STATUS_LABELS) as [
    ProductStatusFilter,
    string,
][];
const levels = Object.entries(STOCK_LEVEL_LABELS) as [
    StockLevelFilter,
    string,
][];

const selectClass =
    'h-9 rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';
</script>

<template>
    <Head title="在庫" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <h1 class="text-xl font-semibold">在庫</h1>

        <form
            class="flex flex-wrap items-end gap-2 rounded-xl border p-3"
            role="search"
            @submit.prevent="submitSearch"
        >
            <div class="grid w-full gap-1 sm:w-64">
                <Label for="q" class="text-xs text-muted-foreground">
                    キーワード
                </Label>
                <Input
                    id="q"
                    v-model="search.q"
                    type="search"
                    placeholder="商品コード・商品名・カテゴリ"
                />
            </div>
            <div class="grid gap-1">
                <Label for="category" class="text-xs text-muted-foreground">
                    カテゴリ
                </Label>
                <select
                    id="category"
                    v-model="search.category"
                    :class="selectClass"
                >
                    <option value="">すべて</option>
                    <option v-for="c in categories" :key="c" :value="c">
                        {{ c }}
                    </option>
                </select>
            </div>
            <div class="grid gap-1">
                <Label for="level" class="text-xs text-muted-foreground">
                    在庫
                </Label>
                <select id="level" v-model="search.level" :class="selectClass">
                    <option
                        v-for="[value, label] in levels"
                        :key="value"
                        :value="value"
                    >
                        {{ label }}
                    </option>
                </select>
            </div>
            <div class="grid gap-1">
                <Label for="status" class="text-xs text-muted-foreground">
                    状態
                </Label>
                <select
                    id="status"
                    v-model="search.status"
                    :class="selectClass"
                >
                    <option
                        v-for="[value, label] in statuses"
                        :key="value"
                        :value="value"
                    >
                        {{ label }}
                    </option>
                </select>
            </div>
            <div class="flex gap-2">
                <Button type="submit">検索</Button>
                <Button variant="ghost" as-child>
                    <Link :href="index()">条件をクリア</Link>
                </Button>
            </div>
        </form>

        <p class="text-sm text-muted-foreground" aria-live="polite">
            <template v-if="pagination.total === 0">
                該当する商品はありません
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
                                    listHref(toggleSort(filters, column.sort))
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
                        v-for="p in products"
                        :key="p.id"
                        :class="
                            cn(
                                'border-b last:border-0',
                                p.is_low_stock && 'bg-destructive/5',
                                !p.is_active && 'text-muted-foreground',
                            )
                        "
                        data-test="stock-row"
                    >
                        <td class="px-3 py-2 font-mono whitespace-nowrap">
                            <Link
                                :href="show(p.id)"
                                class="underline-offset-4 hover:underline"
                            >
                                {{ p.code }}
                            </Link>
                        </td>
                        <td class="min-w-32 px-3 py-2">
                            <span class="mr-1">{{ p.name }}</span>
                            <Badge v-if="!p.is_active" variant="outline">
                                無効
                            </Badge>
                        </td>
                        <td class="hidden px-3 py-2 sm:table-cell">
                            {{ p.category }}
                        </td>
                        <td class="hidden px-3 py-2 md:table-cell">
                            {{ p.unit }}
                        </td>
                        <td
                            class="hidden px-3 py-2 text-right tabular-nums sm:table-cell"
                        >
                            {{ formatNumber(p.reorder_point) }}
                        </td>
                        <td
                            :class="
                                cn(
                                    'px-3 py-2 text-right tabular-nums',
                                    p.is_low_stock &&
                                        'font-semibold text-destructive',
                                )
                            "
                        >
                            <span
                                class="inline-flex items-center justify-end gap-1.5"
                            >
                                <!-- スマホ幅では行の色と赤字で示す（バッジは読み上げ用にだけ残す） -->
                                <Badge
                                    v-if="p.is_low_stock"
                                    variant="destructive"
                                    class="max-sm:sr-only"
                                >
                                    発注点割れ
                                </Badge>
                                {{ formatNumber(p.stock) }}
                            </span>
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
