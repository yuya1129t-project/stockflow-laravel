<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatNumber } from '@/lib/products';
import {
    formatSignedNumber,
    formatStockRef,
    STOCK_MOVEMENT_TYPE_LABELS,
    stockRefHref,
} from '@/lib/stock';
import { cn } from '@/lib/utils';
import { show as productShow } from '@/routes/products';
import { adjust, index, show } from '@/routes/stock';
import type { Pagination, Product } from '@/types/products';
import type { StockAdjustLimits, StockMovement } from '@/types/stock';

defineOptions({
    layout: {
        breadcrumbs: [{ title: '在庫', href: index() }],
    },
});

const props = defineProps<{
    product: Product;
    movements: StockMovement[];
    pagination: Pagination;
    limits: StockAdjustLimits;
}>();

const page = usePage();
const can = (permission: string) =>
    page.props.auth.permissions.includes(permission);

/** 棚卸調整（実数と理由。差分はサーバーがロック後の現在庫から求める） */
const form = useForm({ actual_quantity: '', reason: '' });

function submitAdjust() {
    form.submit(adjust(props.product.id), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

const pageHref = (n: number) =>
    show(props.product.id, { query: n > 1 ? { page: String(n) } : {} });
</script>

<template>
    <Head :title="`${product.name} の在庫`" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div class="space-y-1">
            <Link
                :href="index()"
                class="text-sm text-muted-foreground underline-offset-4 hover:underline"
            >
                ← 在庫一覧
            </Link>
            <h1 class="text-xl font-semibold break-words">
                {{ product.name }}
                <span
                    class="ml-2 font-mono text-base font-normal text-muted-foreground"
                >
                    {{ product.code }}
                </span>
            </h1>
        </div>

        <dl
            class="grid max-w-xl grid-cols-[7rem_1fr] gap-x-4 gap-y-2 rounded-xl border p-4 text-sm"
        >
            <dt class="text-muted-foreground">現在庫</dt>
            <dd
                :class="
                    product.is_low_stock
                        ? 'inline-flex items-center gap-1.5 font-semibold text-destructive'
                        : undefined
                "
                data-test="stock-current"
            >
                {{ formatNumber(product.stock) }} {{ product.unit }}
                <Badge v-if="product.is_low_stock" variant="destructive">
                    発注点割れ
                </Badge>
            </dd>
            <dt class="text-muted-foreground">発注点</dt>
            <dd>
                {{ formatNumber(product.reorder_point) }} {{ product.unit }}
            </dd>
            <dt class="text-muted-foreground">状態</dt>
            <dd>
                <template v-if="product.is_active">有効</template>
                <Badge v-else variant="outline">無効</Badge>
            </dd>
            <dt class="text-muted-foreground">商品</dt>
            <dd>
                <Link
                    :href="productShow(product.id)"
                    class="underline-offset-4 hover:underline"
                >
                    商品の詳細を見る
                </Link>
            </dd>
        </dl>

        <section v-if="can('stock.adjust')" class="space-y-2">
            <h2 class="text-base font-semibold">棚卸調整</h2>
            <p class="text-sm text-muted-foreground">
                数えた実数を入力すると、現在庫との差分を「棚卸調整」として記録します（差分が
                0 なら記録しません）。
            </p>
            <form
                class="grid max-w-xl gap-4 rounded-xl border p-4"
                novalidate
                @submit.prevent="submitAdjust"
            >
                <div class="grid gap-2">
                    <Label for="actual_quantity">実数</Label>
                    <div class="flex items-center gap-2">
                        <Input
                            id="actual_quantity"
                            v-model="form.actual_quantity"
                            name="actual_quantity"
                            inputmode="numeric"
                            class="w-40 text-right tabular-nums"
                            :aria-invalid="
                                form.errors.actual_quantity ? true : undefined
                            "
                            aria-describedby="actual_quantity-error"
                            required
                        />
                        <span class="text-sm">{{ product.unit }}</span>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        0 以上の整数（{{ formatNumber(limits.actualQuantity) }}
                        まで）
                    </p>
                    <InputError
                        id="actual_quantity-error"
                        :message="form.errors.actual_quantity"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="reason">理由</Label>
                    <Input
                        id="reason"
                        v-model="form.reason"
                        name="reason"
                        :maxlength="limits.reason"
                        placeholder="例: 月末棚卸・破損"
                        :aria-invalid="form.errors.reason ? true : undefined"
                        aria-describedby="reason-error"
                        required
                    />
                    <InputError
                        id="reason-error"
                        :message="form.errors.reason"
                    />
                </div>
                <div>
                    <Button
                        type="submit"
                        :disabled="form.processing"
                        data-test="stock-adjust-submit"
                    >
                        <Spinner v-if="form.processing" />
                        調整を記録する
                    </Button>
                </div>
            </form>
        </section>

        <section class="space-y-2">
            <h2 class="text-base font-semibold">入出庫履歴</h2>
            <p class="text-sm text-muted-foreground">
                <template v-if="pagination.total === 0">
                    入出庫の履歴はありません
                </template>
                <template v-else>
                    全 {{ formatNumber(pagination.total) }} 件（新しい順）
                </template>
            </p>
            <div
                v-if="pagination.total > 0"
                class="overflow-x-auto rounded-xl border"
            >
                <table class="w-full text-sm">
                    <thead class="border-b bg-muted/50 text-left">
                        <tr>
                            <th scope="col" class="px-3 py-2 font-medium">
                                日時
                            </th>
                            <th scope="col" class="px-3 py-2 font-medium">
                                種別
                            </th>
                            <th
                                scope="col"
                                class="px-3 py-2 text-right font-medium"
                            >
                                数量
                            </th>
                            <th
                                scope="col"
                                class="hidden px-3 py-2 font-medium sm:table-cell"
                            >
                                理由
                            </th>
                            <th
                                scope="col"
                                class="hidden px-3 py-2 font-medium md:table-cell"
                            >
                                参照
                            </th>
                            <th
                                scope="col"
                                class="hidden px-3 py-2 font-medium md:table-cell"
                            >
                                担当者
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="m in movements"
                            :key="m.id"
                            class="border-b last:border-0"
                            data-test="stock-movement-row"
                        >
                            <td
                                class="px-3 py-2 whitespace-nowrap tabular-nums"
                            >
                                {{ m.created_at ?? '—' }}
                            </td>
                            <td class="px-3 py-2">
                                {{ STOCK_MOVEMENT_TYPE_LABELS[m.type] }}
                                <!-- スマホ幅では理由の列を出さないので、種別の下に表示する -->
                                <span
                                    v-if="m.reason"
                                    class="block max-w-40 text-xs text-muted-foreground sm:hidden"
                                >
                                    {{ m.reason }}
                                </span>
                            </td>
                            <td
                                :class="
                                    cn(
                                        'px-3 py-2 text-right tabular-nums',
                                        m.quantity < 0 && 'text-destructive',
                                    )
                                "
                            >
                                {{ formatSignedNumber(m.quantity) }}
                            </td>
                            <td
                                class="hidden max-w-64 px-3 py-2 break-words sm:table-cell"
                            >
                                {{ m.reason ?? '—' }}
                            </td>
                            <td
                                class="hidden px-3 py-2 font-mono whitespace-nowrap md:table-cell"
                            >
                                <Link
                                    v-if="stockRefHref(m.ref)"
                                    :href="stockRefHref(m.ref) ?? ''"
                                    class="underline-offset-4 hover:underline"
                                    data-test="stock-movement-ref"
                                >
                                    {{ formatStockRef(m.ref) }}
                                </Link>
                                <template v-else>
                                    {{ formatStockRef(m.ref) }}
                                </template>
                            </td>
                            <td class="hidden px-3 py-2 md:table-cell">
                                {{ m.user_name ?? '—' }}
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
                    <Link :href="pageHref(pagination.page - 1)">前へ</Link>
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
                    <Link :href="pageHref(pagination.page + 1)">次へ</Link>
                </Button>
                <span v-else />
            </nav>
        </section>
    </div>
</template>
