<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { auditLogListQuery } from '@/lib/audit-logs';
import { index } from '@/routes/audit-logs';
import type {
    AuditLogFilterOptions,
    AuditLogFilters,
    AuditLogItem,
} from '@/types/audit-logs';
import type { Pagination } from '@/types/products';

defineOptions({
    layout: {
        breadcrumbs: [{ title: '操作履歴', href: index() }],
    },
});

const props = defineProps<{
    logs: AuditLogItem[];
    pagination: Pagination;
    filters: AuditLogFilters;
    options: AuditLogFilterOptions;
}>();

// 絞り込みの入力（「絞り込む」を押したときに URL に反映する。ページは 1 に戻す）
const draft = reactive({
    user: props.filters.user,
    action: props.filters.action,
    targetType: props.filters.targetType,
    from: props.filters.from,
    to: props.filters.to,
});

function visit(filters: AuditLogFilters) {
    router.get(
        index.url({ query: auditLogListQuery(filters) }),
        {},
        { preserveScroll: true, preserveState: true },
    );
}

function search() {
    visit({ ...draft, page: 1 });
}

function reset() {
    draft.user = '';
    draft.action = '';
    draft.targetType = '';
    draft.from = '';
    draft.to = '';
    search();
}

const range = computed(() => {
    const { page, perPage, total } = props.pagination;
    if (total === 0) return '0 件';
    const from = (page - 1) * perPage + 1;
    const to = Math.min(page * perPage, total);
    return `${total} 件中 ${from}〜${to} 件`;
});

const selectClass =
    'border-input h-9 rounded-md border bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm dark:bg-input/30';
</script>

<template>
    <Head title="操作履歴" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <h1 class="text-xl font-semibold">操作履歴</h1>

        <form
            class="flex flex-wrap items-end gap-3"
            role="search"
            @submit.prevent="search"
        >
            <div class="grid gap-1.5">
                <Label for="audit-user">ユーザー</Label>
                <select
                    id="audit-user"
                    v-model="draft.user"
                    :class="selectClass"
                >
                    <option value="">すべて</option>
                    <option
                        v-for="user in options.users"
                        :key="user.id"
                        :value="String(user.id)"
                    >
                        {{ user.name }}（{{ user.email }}）
                    </option>
                </select>
            </div>
            <div class="grid gap-1.5">
                <Label for="audit-action">操作</Label>
                <select
                    id="audit-action"
                    v-model="draft.action"
                    :class="selectClass"
                >
                    <option value="">すべて</option>
                    <option
                        v-for="option in options.actions"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </option>
                </select>
            </div>
            <div class="grid gap-1.5">
                <Label for="audit-target-type">対象の種類</Label>
                <select
                    id="audit-target-type"
                    v-model="draft.targetType"
                    :class="selectClass"
                >
                    <option value="">すべて</option>
                    <option
                        v-for="option in options.targetTypes"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </option>
                </select>
            </div>
            <div class="grid gap-1.5">
                <Label for="audit-from">期間（から）</Label>
                <Input id="audit-from" v-model="draft.from" type="date" />
            </div>
            <div class="grid gap-1.5">
                <Label for="audit-to">期間（まで）</Label>
                <Input id="audit-to" v-model="draft.to" type="date" />
            </div>
            <Button type="submit">絞り込む</Button>
            <Button type="button" variant="ghost" @click="reset"
                >条件をクリア</Button
            >
        </form>

        <p class="text-sm text-muted-foreground" data-test="audit-range">
            {{ range }}
        </p>

        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full min-w-[48rem] text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th scope="col" class="px-3 py-2 font-medium">日時</th>
                        <th scope="col" class="px-3 py-2 font-medium">
                            ユーザー
                        </th>
                        <th scope="col" class="px-3 py-2 font-medium">操作</th>
                        <th scope="col" class="px-3 py-2 font-medium">対象</th>
                        <th scope="col" class="px-3 py-2 font-medium">内容</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="log in logs"
                        :key="log.id"
                        class="border-t align-top"
                        data-test="audit-row"
                    >
                        <td class="px-3 py-2 whitespace-nowrap">
                            {{ log.createdAt }}
                        </td>
                        <td class="px-3 py-2">{{ log.user?.name ?? '—' }}</td>
                        <td class="px-3 py-2 whitespace-nowrap">
                            {{ log.actionLabel }}
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap">
                            <template v-if="log.targetTypeLabel">
                                <Link
                                    v-if="log.targetUrl"
                                    :href="log.targetUrl"
                                    class="text-primary hover:underline"
                                    data-test="audit-target-link"
                                    >{{ log.targetTypeLabel }} #{{
                                        log.targetId
                                    }}</Link
                                >
                                <span v-else
                                    >{{ log.targetTypeLabel
                                    }}{{
                                        log.targetId ? ` #${log.targetId}` : ''
                                    }}</span
                                >
                            </template>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                        <td class="px-3 py-2 break-words">{{ log.summary }}</td>
                    </tr>
                    <tr v-if="logs.length === 0">
                        <td
                            colspan="5"
                            class="px-3 py-8 text-center text-muted-foreground"
                        >
                            条件に合う操作履歴はありません
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
                @click="visit({ ...filters, page: pagination.page - 1 })"
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
                @click="visit({ ...filters, page: pagination.page + 1 })"
            >
                次へ
            </Button>
        </nav>
    </div>
</template>
