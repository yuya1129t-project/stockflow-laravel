<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import ProductForm from '@/components/products/ProductForm.vue';
import { create, index, store } from '@/routes/products';
import type { ProductLimits } from '@/types/products';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: '商品', href: index() },
            { title: '新規登録', href: create() },
        ],
    },
});

defineProps<{
    categories: string[];
    limits: ProductLimits;
}>();
</script>

<template>
    <Head title="商品の新規登録" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <h1 class="text-xl font-semibold">商品の新規登録</h1>
        <ProductForm
            :categories="categories"
            :limits="limits"
            :action="store()"
            submit-label="登録する"
            :cancel-href="index()"
        />
    </div>
</template>
