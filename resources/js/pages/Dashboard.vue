<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { dashboard } from '@/routes';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'ダッシュボード',
                href: dashboard(),
            },
        ],
    },
});
</script>

<template>
    <Head title="ダッシュボード" />

    <!-- 仮表示。集計（今月の受注額・未出荷の受注数・発注点割れ・入荷待ち）は後の Issue で実装する -->
    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <h1 class="text-xl font-semibold">ダッシュボード</h1>
        <div
            class="rounded-xl border border-dashed border-sidebar-border/70 p-6 text-sm text-muted-foreground dark:border-sidebar-border"
            data-test="dashboard-placeholder"
        >
            ダッシュボードは準備中です。今月の受注額・未出荷の受注数・発注点を下回る商品・入荷待ちの発注をここに表示します。
        </div>
    </div>
</template>
