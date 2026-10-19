<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PartnerForm from '@/components/partners/PartnerForm.vue';
import type { KindOption } from '@/lib/partners';
import { create, index } from '@/routes/partners';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: '取引先', href: index() },
            { title: '登録', href: create() },
        ],
    },
});

defineProps<{
    kindOptions: KindOption[];
}>();
</script>

<template>
    <Head title="取引先の登録" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <h1 class="text-xl font-semibold">取引先の登録</h1>
        <PartnerForm :kind-options="kindOptions" />
    </div>
</template>
