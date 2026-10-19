<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import SalesOrderForm from '@/components/sales-orders/SalesOrderForm.vue';
import { edit, index, show, update } from '@/routes/sales-orders';
import type { LineProductOption, OrderLimits } from '@/types/orders';
import type { SalesOrderDetail, CustomerOption } from '@/types/sales-orders';

defineOptions({
    layout: {
        breadcrumbs: [{ title: '受注', href: index() }],
    },
});

defineProps<{
    order: SalesOrderDetail;
    customers: CustomerOption[];
    products: LineProductOption[];
    limits: OrderLimits;
}>();
</script>

<template>
    <Head :title="`受注 ${order.number} の編集`" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <h1 class="text-xl font-semibold">
            受注の編集
            <span
                class="ml-2 font-mono text-base font-normal text-muted-foreground"
            >
                {{ order.number }}
            </span>
        </h1>
        <SalesOrderForm
            :order="order"
            :customers="customers"
            :products="products"
            :limits="limits"
            :action="update(order.id)"
            submit-label="保存する"
            :cancel-href="show(order.id)"
            :reload-href="edit(order.id)"
        />
    </div>
</template>
