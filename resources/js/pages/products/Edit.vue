<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import ProductForm from '@/components/products/ProductForm.vue';
import { index, show, update } from '@/routes/products';
import type { Product, ProductLimits } from '@/types/products';

defineOptions({
    layout: {
        breadcrumbs: [{ title: '商品', href: index() }],
    },
});

defineProps<{
    product: Product;
    categories: string[];
    limits: ProductLimits;
}>();
</script>

<template>
    <Head :title="`${product.name} の編集`" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <h1 class="text-xl font-semibold">商品の編集</h1>
        <ProductForm
            :product="product"
            :categories="categories"
            :limits="limits"
            :action="update(product.id)"
            submit-label="保存する"
            :cancel-href="show(product.id)"
        />
    </div>
</template>
