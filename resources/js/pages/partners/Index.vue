<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { KIND_FILTER_LABELS, STATUS_LABELS, toListQuery } from '@/lib/partners';
import type { KindOption, Partner, PartnerListFilters } from '@/lib/partners';
import { create, index, show } from '@/routes/partners';

defineOptions({
    layout: {
        breadcrumbs: [{ title: '取引先', href: index() }],
    },
});

const props = defineProps<{
    partners: {
        data: Partner[];
        meta: {
            page: number;
            perPage: number;
            total: number;
            lastPage: number;
        };
    };
    filters: PartnerListFilters;
    kindOptions: KindOption[];
    can: { create: boolean; update: boolean; setActive: boolean };
}>();

// 検索欄の入力（「検索」を押したときに URL に反映する）
const draft = reactive({
    q: props.filters.q,
    kind: props.filters.kind,
    status: props.filters.status,
});

function visit(filters: PartnerListFilters, page = 1) {
    router.get(
        index.url({ query: toListQuery(filters, page) }),
        {},
        { preserveScroll: true, preserveState: true },
    );
}

function search() {
    visit({ ...props.filters, ...draft });
}

function reset() {
    draft.q = '';
    draft.kind = '';
    draft.status = 'active';
    visit({ ...props.filters, ...draft, sort: 'code', order: 'asc' });
}

type SortKey = PartnerListFilters['sort'];

function sortBy(sort: SortKey) {
    const order =
        props.filters.sort === sort && props.filters.order === 'asc'
            ? 'desc'
            : 'asc';
    visit({ ...props.filters, sort, order });
}

function sortMark(sort: SortKey): string {
    if (props.filters.sort !== sort) return '';
    return props.filters.order === 'asc' ? '▲' : '▼';
}

function ariaSort(sort: SortKey): 'ascending' | 'descending' | 'none' {
    if (props.filters.sort !== sort) return 'none';
    return props.filters.order === 'asc' ? 'ascending' : 'descending';
}

const columns: { key: SortKey; label: string }[] = [
    { key: 'code', label: 'コード' },
    { key: 'name', label: '取引先名' },
    { key: 'kind', label: '種別' },
];

const range = computed(() => {
    const { page, perPage, total } = props.partners.meta;
    if (total === 0) return '0 件';
    const from = (page - 1) * perPage + 1;
    const to = Math.min(page * perPage, total);
    return `${total} 件中 ${from}〜${to} 件`;
});

const selectClass =
    'border-input h-9 rounded-md border bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm dark:bg-input/30';
</script>

<template>
    <Head title="取引先" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="text-xl font-semibold">取引先</h1>
            <Button v-if="can.create" as-child>
                <Link :href="create()" data-test="partner-create"
                    >取引先を登録</Link
                >
            </Button>
        </div>

        <form
            class="flex flex-wrap items-end gap-3"
            role="search"
            @submit.prevent="search"
        >
            <div class="grid gap-1.5">
                <Label for="partner-q">コード・名前</Label>
                <Input
                    id="partner-q"
                    v-model="draft.q"
                    class="w-56"
                    maxlength="100"
                    placeholder="例 C-0001・商事"
                />
            </div>
            <div class="grid gap-1.5">
                <Label for="partner-kind-filter">種別</Label>
                <select
                    id="partner-kind-filter"
                    v-model="draft.kind"
                    :class="selectClass"
                >
                    <option value="">すべて</option>
                    <option
                        v-for="option in kindOptions"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ KIND_FILTER_LABELS[option.value] }}
                    </option>
                </select>
            </div>
            <div class="grid gap-1.5">
                <Label for="partner-status-filter">状態</Label>
                <select
                    id="partner-status-filter"
                    v-model="draft.status"
                    :class="selectClass"
                >
                    <option
                        v-for="(label, value) in STATUS_LABELS"
                        :key="value"
                        :value="value"
                    >
                        {{ label }}
                    </option>
                </select>
            </div>
            <Button type="submit">検索</Button>
            <Button type="button" variant="ghost" @click="reset"
                >条件をクリア</Button
            >
        </form>

        <p class="text-sm text-muted-foreground" data-test="partner-range">
            {{ range }}
        </p>

        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full min-w-[40rem] text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th
                            v-for="column in columns"
                            :key="column.key"
                            scope="col"
                            class="px-3 py-2 font-medium"
                            :aria-sort="ariaSort(column.key)"
                        >
                            <button
                                type="button"
                                class="inline-flex items-center gap-1 hover:underline"
                                @click="sortBy(column.key)"
                            >
                                {{ column.label }}
                                <span class="text-xs" aria-hidden="true">{{
                                    sortMark(column.key)
                                }}</span>
                            </button>
                        </th>
                        <th scope="col" class="px-3 py-2 font-medium">
                            電話番号
                        </th>
                        <th scope="col" class="px-3 py-2 font-medium">住所</th>
                        <th scope="col" class="px-3 py-2 font-medium">状態</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="partner in partners.data"
                        :key="partner.id"
                        class="border-t"
                        data-test="partner-row"
                    >
                        <td class="px-3 py-2 font-mono">
                            <Link
                                :href="show(partner.id)"
                                class="text-primary hover:underline"
                                >{{ partner.code }}</Link
                            >
                        </td>
                        <td class="px-3 py-2">{{ partner.name }}</td>
                        <td class="px-3 py-2">{{ partner.kindLabel }}</td>
                        <td class="px-3 py-2 whitespace-nowrap">
                            {{ partner.phone || '—' }}
                        </td>
                        <td class="max-w-[20rem] truncate px-3 py-2">
                            {{ partner.address || '—' }}
                        </td>
                        <td class="px-3 py-2">
                            <Badge v-if="!partner.isActive" variant="secondary"
                                >無効</Badge
                            >
                            <span v-else class="text-muted-foreground"
                                >有効</span
                            >
                        </td>
                    </tr>
                    <tr v-if="partners.data.length === 0">
                        <td
                            colspan="6"
                            class="px-3 py-8 text-center text-muted-foreground"
                        >
                            条件に合う取引先はありません
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav
            v-if="partners.meta.lastPage > 1"
            class="flex items-center gap-2"
            aria-label="ページ送り"
        >
            <Button
                variant="outline"
                size="sm"
                :disabled="partners.meta.page <= 1"
                @click="visit(filters, partners.meta.page - 1)"
            >
                前へ
            </Button>
            <span class="text-sm text-muted-foreground">
                {{ partners.meta.page }} / {{ partners.meta.lastPage }} ページ
            </span>
            <Button
                variant="outline"
                size="sm"
                :disabled="partners.meta.page >= partners.meta.lastPage"
                @click="visit(filters, partners.meta.page + 1)"
            >
                次へ
            </Button>
        </nav>
    </div>
</template>
