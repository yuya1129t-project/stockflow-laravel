<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import Heading from '@/components/Heading.vue';
import { edit } from '@/routes/appearance';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: '表示設定',
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <Head title="表示設定" />

    <h1 class="sr-only">表示設定</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="表示設定"
            description="画面の明るさ（ライト・ダーク）を選びます"
        />
        <AppearanceTabs />
    </div>
</template>
