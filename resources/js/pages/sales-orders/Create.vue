<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import SalesOrderForm from '@/components/sales-orders/SalesOrderForm.vue';
import { index, store } from '@/routes/sales-orders';
import type { LineProductOption, OrderLimits } from '@/types/orders';
import type { CustomerOption } from '@/types/sales-orders';

defineOptions({
    layout: {
        breadcrumbs: [{ title: '受注', href: index() }],
    },
});

defineProps<{
    customers: CustomerOption[];
    products: LineProductOption[];
    limits: OrderLimits;
}>();
</script>

<template>
    <Head title="受注の作成" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <h1 class="text-xl font-semibold">受注の作成</h1>
        <p class="text-sm text-muted-foreground">
            下書きとして保存します。受注番号は保存したときに採番します。
        </p>
        <SalesOrderForm
            :customers="customers"
            :products="products"
            :limits="limits"
            :action="store()"
            submit-label="下書きを保存する"
            :cancel-href="index()"
        />
    </div>
</template>
