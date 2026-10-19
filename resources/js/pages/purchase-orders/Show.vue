<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { formatNumber } from '@/lib/products';
import {
    displayDate,
    PURCHASE_ORDER_TRANSITION_LABELS,
} from '@/lib/purchase-orders';
import { show as productShow } from '@/routes/products';
import { edit, index, show, status } from '@/routes/purchase-orders';
import type {
    PurchaseOrderDetail,
    PurchaseOrderStatus,
} from '@/types/purchase-orders';

defineOptions({
    layout: {
        breadcrumbs: [{ title: '発注', href: index() }],
    },
});

const props = defineProps<{
    order: PurchaseOrderDetail;
    /** 次に進めるステータス（最終状態は空） */
    transitions: Exclude<PurchaseOrderStatus, 'draft'>[];
}>();

const page = usePage();
const canEdit = computed(() =>
    page.props.auth.permissions.includes('order.edit'),
);

/**
 * ステータス変更（確認ダイアログを出してから送る）。詳細を表示した時点の version を一緒に送り、
 * 他の人が先に更新していたら変更されない（競合）
 */
const target = ref<Exclude<PurchaseOrderStatus, 'draft'> | null>(null);
const dialogOpen = ref(false);
const form = useForm({ to: '' });
const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);
const errorMessage = computed(
    () => errors.value.status ?? errors.value.to ?? errors.value.form,
);

function openDialog(to: Exclude<PurchaseOrderStatus, 'draft'>) {
    target.value = to;
    form.clearErrors();
    dialogOpen.value = true;
}

function submitStatus() {
    if (target.value === null) return;
    form.to = target.value;
    form.transform((data) => ({
        ...data,
        version: props.order.version,
    })).submit(status(props.order.id), {
        preserveScroll: true,
        onSuccess: () => {
            dialogOpen.value = false;
        },
    });
}

const statusVariant = computed(() => {
    switch (props.order.status) {
        case 'draft':
            return 'outline' as const;
        case 'cancelled':
            return 'secondary' as const;
        default:
            return 'default' as const;
    }
});
</script>

<template>
    <Head :title="`発注 ${order.number}`" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <div class="space-y-1">
            <Link
                :href="index()"
                class="text-sm text-muted-foreground underline-offset-4 hover:underline"
            >
                ← 発注一覧
            </Link>
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="font-mono text-xl font-semibold">
                    {{ order.number }}
                </h1>
                <Badge
                    :variant="statusVariant"
                    data-test="purchase-order-status"
                >
                    {{ order.status_label }}
                </Badge>
            </div>
        </div>

        <dl
            class="grid max-w-xl grid-cols-[7rem_1fr] gap-x-4 gap-y-2 rounded-xl border p-4 text-sm"
        >
            <dt class="text-muted-foreground">仕入先</dt>
            <dd class="min-w-0 break-words">
                <span class="font-mono">{{ order.supplier.code }}</span>
                {{ order.supplier.name }}
                <Badge v-if="!order.supplier.is_active" variant="outline">
                    無効
                </Badge>
            </dd>
            <dt class="text-muted-foreground">発注日</dt>
            <dd>{{ displayDate(order.order_date) }}</dd>
            <dt class="text-muted-foreground">入荷予定日</dt>
            <dd>{{ displayDate(order.expected_date) }}</dd>
            <dt class="text-muted-foreground">備考</dt>
            <dd class="min-w-0 break-words whitespace-pre-line">
                {{ order.note ?? '—' }}
            </dd>
            <dt class="text-muted-foreground">最終更新</dt>
            <dd class="tabular-nums">{{ order.updated_at ?? '—' }}</dd>
        </dl>

        <section class="space-y-2">
            <h2 class="text-base font-semibold">明細</h2>
            <p
                v-if="order.lines.length === 0"
                class="text-sm text-muted-foreground"
            >
                明細はありません（発注済にするには明細が 1 行以上必要です）
            </p>
            <div v-else class="overflow-x-auto rounded-xl border">
                <table class="w-full text-sm">
                    <thead class="border-b bg-muted/50 text-left">
                        <tr>
                            <th scope="col" class="px-3 py-2 font-medium">
                                商品
                            </th>
                            <th
                                scope="col"
                                class="px-3 py-2 text-right font-medium"
                            >
                                数量
                            </th>
                            <th
                                scope="col"
                                class="px-3 py-2 text-right font-medium"
                            >
                                単価（円）
                            </th>
                            <th
                                scope="col"
                                class="px-3 py-2 text-right font-medium"
                            >
                                金額（円）
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="line in order.lines"
                            :key="line.id"
                            class="border-b last:border-0"
                            data-test="purchase-order-line"
                        >
                            <td class="min-w-40 px-3 py-2">
                                <Link
                                    :href="productShow(line.product_id)"
                                    class="font-mono underline-offset-4 hover:underline"
                                >
                                    {{ line.product_code }}
                                </Link>
                                <span class="ml-2">{{
                                    line.product_name
                                }}</span>
                                <Badge
                                    v-if="!line.product_is_active"
                                    variant="outline"
                                    class="ml-1"
                                >
                                    無効
                                </Badge>
                            </td>
                            <td
                                class="px-3 py-2 text-right whitespace-nowrap tabular-nums"
                            >
                                {{ formatNumber(line.quantity) }}
                                {{ line.unit }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ formatNumber(line.unit_price) }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ formatNumber(line.amount) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <dl
                class="ml-auto grid w-full max-w-xs grid-cols-[1fr_auto] gap-x-4 gap-y-1 text-sm"
            >
                <dt class="text-muted-foreground">小計</dt>
                <dd class="text-right tabular-nums">
                    {{ formatNumber(order.subtotal) }} 円
                </dd>
                <dt class="text-muted-foreground">消費税</dt>
                <dd class="text-right tabular-nums">
                    {{ formatNumber(order.tax) }} 円
                </dd>
                <dt class="font-medium">合計</dt>
                <dd
                    class="text-right font-semibold tabular-nums"
                    data-test="purchase-order-total"
                >
                    {{ formatNumber(order.total) }} 円
                </dd>
            </dl>
        </section>

        <div v-if="canEdit" class="flex flex-wrap gap-2">
            <Button v-if="order.status === 'draft'" variant="outline" as-child>
                <Link :href="edit(order.id)" data-test="purchase-order-edit">
                    編集する
                </Link>
            </Button>
            <Button
                v-for="to in transitions"
                :key="to"
                :variant="to === 'cancelled' ? 'destructive' : 'default'"
                :data-test="`purchase-order-to-${to}`"
                @click="openDialog(to)"
            >
                {{ PURCHASE_ORDER_TRANSITION_LABELS[to].action }}
            </Button>
        </div>
        <p
            v-if="transitions.length === 0"
            class="text-sm text-muted-foreground"
        >
            「{{
                order.status_label
            }}」は最終状態のため、ステータスは変更できません。
        </p>

        <Dialog v-model:open="dialogOpen">
            <DialogContent v-if="target !== null">
                <DialogHeader>
                    <DialogTitle>
                        {{ PURCHASE_ORDER_TRANSITION_LABELS[target].question }}
                    </DialogTitle>
                    <DialogDescription>
                        発注 {{ order.number }}（合計
                        {{ formatNumber(order.total) }} 円）。
                        {{
                            PURCHASE_ORDER_TRANSITION_LABELS[target].description
                        }}
                    </DialogDescription>
                </DialogHeader>
                <InputError :message="errorMessage" />
                <div
                    v-if="errors.conflict"
                    class="space-y-1 text-sm text-destructive"
                    data-test="status-conflict"
                >
                    <p>{{ errors.conflict }}</p>
                    <Link
                        :href="show(order.id)"
                        class="font-medium underline underline-offset-4"
                        @click="dialogOpen = false"
                    >
                        最新の内容を表示する
                    </Link>
                </div>
                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="outline">キャンセル</Button>
                    </DialogClose>
                    <Button
                        :variant="
                            target === 'cancelled' ? 'destructive' : 'default'
                        "
                        :disabled="form.processing"
                        data-test="purchase-order-status-confirm"
                        @click="submitStatus"
                    >
                        <Spinner v-if="form.processing" />
                        {{ PURCHASE_ORDER_TRANSITION_LABELS[target].action }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
