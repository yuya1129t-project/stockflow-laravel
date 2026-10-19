<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PartnerForm from '@/components/partners/PartnerForm.vue';
import type { KindOption, Partner } from '@/lib/partners';
import { index } from '@/routes/partners';

defineOptions({
    layout: {
        breadcrumbs: [{ title: '取引先', href: index() }],
    },
});

defineProps<{
    partner: Partner;
    kindOptions: KindOption[];
}>();
</script>

<template>
    <Head :title="`取引先の編集（${partner.code}）`" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <h1 class="text-xl font-semibold">
            取引先の編集
            <span class="ml-1 font-mono text-base text-muted-foreground">{{
                partner.code
            }}</span>
        </h1>
        <PartnerForm :partner="partner" :kind-options="kindOptions" />
    </div>
</template>
