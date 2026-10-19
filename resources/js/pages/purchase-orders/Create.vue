<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PurchaseOrderForm from '@/components/purchase-orders/PurchaseOrderForm.vue';
import { index, store } from '@/routes/purchase-orders';
import type { LineProductOption, OrderLimits } from '@/types/orders';
import type { SupplierOption } from '@/types/purchase-orders';

defineOptions({
    layout: {
        breadcrumbs: [{ title: '発注', href: index() }],
    },
});

defineProps<{
    suppliers: SupplierOption[];
    products: LineProductOption[];
    limits: OrderLimits;
}>();
</script>

<template>
    <Head title="発注の作成" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <h1 class="text-xl font-semibold">発注の作成</h1>
        <p class="text-sm text-muted-foreground">
            下書きとして保存します。発注番号は保存したときに採番します。
        </p>
        <PurchaseOrderForm
            :suppliers="suppliers"
            :products="products"
            :limits="limits"
            :action="store()"
            submit-label="下書きを保存する"
            :cancel-href="index()"
        />
    </div>
</template>
