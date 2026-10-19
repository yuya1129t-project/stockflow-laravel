<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatNumber, PRODUCT_FIELD_LABELS } from '@/lib/products';
import { deactivate, edit, index, reactivate } from '@/routes/products';
import type { Product } from '@/types/products';

defineOptions({
    layout: {
        breadcrumbs: [{ title: '商品', href: index() }],
    },
});

const props = defineProps<{
    product: Product;
}>();

const page = usePage();
const can = (permission: string) =>
    page.props.auth.permissions.includes(permission);

const rows = computed(() => [
    { label: PRODUCT_FIELD_LABELS.code, value: props.product.code, mono: true },
    { label: PRODUCT_FIELD_LABELS.name, value: props.product.name },
    { label: PRODUCT_FIELD_LABELS.category, value: props.product.category },
    { label: PRODUCT_FIELD_LABELS.unit, value: props.product.unit },
    {
        label: PRODUCT_FIELD_LABELS.sales_price,
        value: `${formatNumber(props.product.sales_price)} 円`,
    },
    {
        label: PRODUCT_FIELD_LABELS.purchase_price,
        value: `${formatNumber(props.product.purchase_price)} 円`,
    },
    {
        label: PRODUCT_FIELD_LABELS.reorder_point,
        value: `${formatNumber(props.product.reorder_point)} ${props.product.unit}`,
    },
]);

/** 無効化・再有効化（admin のみ。確認してから送る） */
function toggleActive() {
    const p = props.product;
    const message = p.is_active
        ? `商品 ${p.code}「${p.name}」を無効にします。無効の商品は新規の受注・発注で選べなくなります。よろしいですか？`
        : `商品 ${p.code}「${p.name}」を再度有効にします。よろしいですか？`;
    if (!window.confirm(message)) return;
    router.visit(p.is_active ? deactivate(p.id) : reactivate(p.id));
}
</script>

<template>
    <Head :title="product.name" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <div class="space-y-1">
            <Link
                :href="index()"
                class="text-sm text-muted-foreground underline-offset-4 hover:underline"
            >
                ← 商品一覧
            </Link>
            <h1 class="text-xl font-semibold break-words">
                {{ product.name }}
            </h1>
        </div>

        <dl
            class="grid max-w-xl grid-cols-[7rem_1fr] gap-x-4 gap-y-2 rounded-xl border p-4 text-sm"
        >
            <div v-for="row in rows" :key="row.label" class="contents">
                <dt class="text-muted-foreground">{{ row.label }}</dt>
                <dd :class="['min-w-0 break-words', row.mono && 'font-mono']">
                    {{ row.value }}
                </dd>
            </div>
            <dt class="text-muted-foreground">現在庫</dt>
            <dd
                :class="
                    product.is_low_stock
                        ? 'inline-flex items-center gap-1.5 font-semibold text-destructive'
                        : undefined
                "
                data-test="product-stock"
            >
                {{ formatNumber(product.stock) }} {{ product.unit }}
                <Badge v-if="product.is_low_stock" variant="destructive">
                    発注点割れ
                </Badge>
            </dd>
            <dt class="text-muted-foreground">状態</dt>
            <dd>
                <template v-if="product.is_active">有効</template>
                <Badge v-else variant="outline">無効</Badge>
            </dd>
        </dl>

        <div class="flex flex-wrap gap-2">
            <Button v-if="can('master.edit')" as-child>
                <Link :href="edit(product.id)">編集する</Link>
            </Button>
            <Button
                v-if="can('master.deactivate')"
                :variant="product.is_active ? 'destructive' : 'outline'"
                data-test="product-toggle-active"
                @click="toggleActive"
            >
                {{ product.is_active ? '無効にする' : '再度有効にする' }}
            </Button>
        </div>
    </div>
</template>
