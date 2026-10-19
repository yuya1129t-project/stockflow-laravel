<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PurchaseOrderForm from '@/components/purchase-orders/PurchaseOrderForm.vue';
import { edit, index, show, update } from '@/routes/purchase-orders';
import type { LineProductOption, OrderLimits } from '@/types/orders';
import type {
    PurchaseOrderDetail,
    SupplierOption,
} from '@/types/purchase-orders';

defineOptions({
    layout: {
        breadcrumbs: [{ title: '発注', href: index() }],
    },
});

defineProps<{
    order: PurchaseOrderDetail;
    suppliers: SupplierOption[];
    products: LineProductOption[];
    limits: OrderLimits;
}>();
</script>

<template>
    <Head :title="`発注 ${order.number} の編集`" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <h1 class="text-xl font-semibold">
            発注の編集
            <span
                class="ml-2 font-mono text-base font-normal text-muted-foreground"
            >
                {{ order.number }}
            </span>
        </h1>
        <PurchaseOrderForm
            :order="order"
            :suppliers="suppliers"
            :products="products"
            :limits="limits"
            :action="update(order.id)"
            submit-label="保存する"
            :cancel-href="show(order.id)"
            :reload-href="edit(order.id)"
        />
    </div>
</template>
