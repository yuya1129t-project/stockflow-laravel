<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';

// サーバーのエラー（bootstrap/app.php の Inertia::handleExceptionsUsing）。今は権限外（403）だけを表示する
const props = defineProps<{
    status: number;
}>();

const messages: Record<number, { title: string; description: string }> = {
    403: {
        title: 'この画面を表示する権限がありません',
        description:
            'ご利用のアカウントのロールでは、この画面・操作は使えません。必要な場合は管理者に連絡してください。',
    },
};

const message = computed(
    () =>
        messages[props.status] ?? {
            title: 'エラーが発生しました',
            description: '時間をおいてもう一度お試しください。',
        },
);
</script>

<template>
    <Head :title="message.title" />

    <div class="flex flex-1 flex-col items-start gap-4 p-6">
        <p class="text-sm font-medium text-muted-foreground">
            {{ status }}
        </p>
        <h1 class="text-xl font-semibold">{{ message.title }}</h1>
        <p class="text-sm text-muted-foreground">{{ message.description }}</p>
        <Button variant="outline" as-child>
            <Link :href="dashboard()">ダッシュボードへ戻る</Link>
        </Button>
    </div>
</template>
