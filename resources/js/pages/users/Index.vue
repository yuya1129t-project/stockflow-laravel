<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { create, index, show } from '@/routes/users';
import type { Pagination } from '@/types/products';
import type { ManagedUser } from '@/types/users';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'ユーザー管理', href: index() }],
    },
});

const props = defineProps<{
    users: ManagedUser[];
    pagination: Pagination;
}>();

function visit(page: number) {
    router.get(
        index.url({ query: page > 1 ? { page: String(page) } : {} }),
        {},
        { preserveScroll: true },
    );
}

const range = computed(() => {
    const { page, perPage, total } = props.pagination;
    if (total === 0) return '0 件';
    const from = (page - 1) * perPage + 1;
    const to = Math.min(page * perPage, total);
    return `${total} 件中 ${from}〜${to} 件`;
});
</script>

<template>
    <Head title="ユーザー管理" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="text-xl font-semibold">ユーザー管理</h1>
            <Button as-child>
                <Link :href="create()" data-test="user-create"
                    >ユーザーを登録</Link
                >
            </Button>
        </div>

        <p class="text-sm text-muted-foreground" data-test="user-range">
            {{ range }}
        </p>

        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full min-w-[40rem] text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th scope="col" class="px-3 py-2 font-medium">名前</th>
                        <th scope="col" class="px-3 py-2 font-medium">
                            メールアドレス
                        </th>
                        <th scope="col" class="px-3 py-2 font-medium">
                            ロール
                        </th>
                        <th scope="col" class="px-3 py-2 font-medium">状態</th>
                        <th scope="col" class="px-3 py-2 font-medium">
                            登録日時
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="user in users"
                        :key="user.id"
                        class="border-t"
                        data-test="user-row"
                    >
                        <td class="px-3 py-2">
                            <Link
                                :href="show(user.id)"
                                class="text-primary hover:underline"
                                >{{ user.name }}</Link
                            >
                            <Badge
                                v-if="user.isDemo"
                                variant="outline"
                                class="ml-2"
                                >デモ</Badge
                            >
                            <span
                                v-if="user.isSelf"
                                class="ml-2 text-xs text-muted-foreground"
                                >（自分）</span
                            >
                        </td>
                        <td class="px-3 py-2 break-all">{{ user.email }}</td>
                        <td class="px-3 py-2">{{ user.roleLabel }}</td>
                        <td class="px-3 py-2">
                            <Badge v-if="!user.isActive" variant="secondary"
                                >無効</Badge
                            >
                            <span v-else class="text-muted-foreground"
                                >有効</span
                            >
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap">
                            {{ user.createdAt }}
                        </td>
                    </tr>
                    <tr v-if="users.length === 0">
                        <td
                            colspan="5"
                            class="px-3 py-8 text-center text-muted-foreground"
                        >
                            ユーザーはいません
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav
            v-if="pagination.totalPages > 1"
            class="flex items-center gap-2"
            aria-label="ページ送り"
        >
            <Button
                variant="outline"
                size="sm"
                :disabled="pagination.page <= 1"
                @click="visit(pagination.page - 1)"
            >
                前へ
            </Button>
            <span class="text-sm text-muted-foreground">
                {{ pagination.page }} / {{ pagination.totalPages }} ページ
            </span>
            <Button
                variant="outline"
                size="sm"
                :disabled="pagination.page >= pagination.totalPages"
                @click="visit(pagination.page + 1)"
            >
                次へ
            </Button>
        </nav>
    </div>
</template>
