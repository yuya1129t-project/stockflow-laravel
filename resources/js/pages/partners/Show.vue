<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
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
import type { Partner } from '@/lib/partners';
import { deactivate, edit, index, reactivate } from '@/routes/partners';

defineOptions({
    layout: {
        breadcrumbs: [{ title: '取引先', href: index() }],
    },
});

const props = defineProps<{
    partner: Partner & {
        salesOrdersCount: number;
        purchaseOrdersCount: number;
    };
    can: { create: boolean; update: boolean; setActive: boolean };
}>();

const confirmOpen = ref(false);
const processing = ref(false);

function toggleActive() {
    const action = props.partner.isActive
        ? deactivate(props.partner.id)
        : reactivate(props.partner.id);
    router.visit(action.url, {
        method: action.method,
        onStart: () => (processing.value = true),
        onFinish: () => {
            processing.value = false;
            confirmOpen.value = false;
        },
    });
}
</script>

<template>
    <Head :title="`取引先 ${partner.code}`" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-xl font-semibold">{{ partner.name }}</h1>
            <Badge variant="outline">{{ partner.kindLabel }}</Badge>
            <Badge
                v-if="!partner.isActive"
                variant="secondary"
                data-test="partner-inactive"
            >
                無効
            </Badge>
        </div>

        <dl
            class="grid max-w-xl grid-cols-[8rem_1fr] gap-x-4 gap-y-2 rounded-xl border p-4 text-sm"
        >
            <dt class="text-muted-foreground">取引先コード</dt>
            <dd class="font-mono">{{ partner.code }}</dd>
            <dt class="text-muted-foreground">種別</dt>
            <dd>{{ partner.kindLabel }}</dd>
            <dt class="text-muted-foreground">郵便番号</dt>
            <dd>{{ partner.postalCode || '—' }}</dd>
            <dt class="text-muted-foreground">住所</dt>
            <dd class="break-words">{{ partner.address || '—' }}</dd>
            <dt class="text-muted-foreground">電話番号</dt>
            <dd>{{ partner.phone || '—' }}</dd>
            <dt class="text-muted-foreground">メールアドレス</dt>
            <dd class="break-all">{{ partner.email || '—' }}</dd>
            <dt class="text-muted-foreground">受注・発注</dt>
            <dd>
                受注 {{ partner.salesOrdersCount }} 件・発注
                {{ partner.purchaseOrdersCount }} 件
            </dd>
            <dt class="text-muted-foreground">状態</dt>
            <dd>{{ partner.isActive ? '有効' : '無効' }}</dd>
        </dl>

        <div class="flex flex-wrap gap-2">
            <Button v-if="can.update" as-child>
                <Link :href="edit(partner.id)" data-test="partner-edit"
                    >編集する</Link
                >
            </Button>
            <Button
                v-if="can.setActive"
                variant="outline"
                data-test="partner-toggle-active"
                @click="confirmOpen = true"
            >
                {{ partner.isActive ? '無効にする' : '再度有効にする' }}
            </Button>
            <Button variant="ghost" as-child>
                <Link :href="index()">一覧へ戻る</Link>
            </Button>
        </div>

        <Dialog v-model:open="confirmOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {{
                            partner.isActive
                                ? '取引先を無効にしますか？'
                                : '取引先を再度有効にしますか？'
                        }}
                    </DialogTitle>
                    <DialogDescription>
                        {{
                            partner.isActive
                                ? '無効にした取引先は、一覧の既定の表示（有効のみ）に出なくなり、新しい受注・発注で選べなくなります。あとで再度有効にできます。'
                                : '再度有効にすると、一覧に表示され、受注・発注で選べるようになります。'
                        }}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="outline">キャンセル</Button>
                    </DialogClose>
                    <Button
                        :variant="partner.isActive ? 'destructive' : 'default'"
                        :disabled="processing"
                        data-test="partner-toggle-active-confirm"
                        @click="toggleActive"
                    >
                        {{ partner.isActive ? '無効にする' : '再度有効にする' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
