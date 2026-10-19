<script setup lang="ts">
import type { InertiaLinkProps } from '@inertiajs/vue3';
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import OrderLinesEditor from '@/components/orders/OrderLinesEditor.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { EMPTY_ORDER_LINE } from '@/lib/orders';
import type {
    LineProductOption,
    OrderLimits,
    OrderLineForm,
} from '@/types/orders';
import type {
    PurchaseOrderDetail,
    SupplierOption,
} from '@/types/purchase-orders';
import type { RouteDefinition } from '@/wayfinder';

/**
 * 発注の作成・編集フォーム。入力はすべて文字列で送り、検証・金額の計算はサーバー側で行う
 * （App\Http\Requests\PurchaseOrderRequest・App\PurchaseOrders\PurchaseOrderService）。
 * 編集では画面を開いた時点の version を一緒に送り、他の人が先に更新していたら保存されない（競合）
 */
const props = defineProps<{
    order?: PurchaseOrderDetail;
    suppliers: SupplierOption[];
    products: LineProductOption[];
    limits: OrderLimits;
    action: RouteDefinition<'post' | 'put'>;
    submitLabel: string;
    cancelHref: NonNullable<InertiaLinkProps['href']>;
    /** 競合のときに開き直す画面（編集画面） */
    reloadHref?: NonNullable<InertiaLinkProps['href']>;
}>();

const form = useForm({
    supplier_id: props.order ? String(props.order.supplier.id) : '',
    expected_date: props.order?.expected_date ?? '',
    note: props.order?.note ?? '',
    lines: props.order
        ? props.order.lines.map((line): OrderLineForm => ({
              product_id: String(line.product_id),
              quantity: String(line.quantity),
              unit_price: String(line.unit_price),
          }))
        : [{ ...EMPTY_ORDER_LINE }],
    version: props.order?.version ?? '',
});

const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);

function submit() {
    form.submit(props.action, { preserveScroll: true });
}

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive dark:bg-input/30';
</script>

<template>
    <form class="grid max-w-4xl gap-5" novalidate @submit.prevent="submit">
        <Alert
            v-if="errors.conflict"
            variant="destructive"
            data-test="conflict"
        >
            <AlertDescription>
                <p>{{ errors.conflict }}</p>
                <Link
                    v-if="reloadHref"
                    :href="reloadHref"
                    class="font-medium underline underline-offset-4"
                >
                    最新の内容を表示する（入力中の内容は破棄されます）
                </Link>
            </AlertDescription>
        </Alert>
        <Alert v-if="errors.form" variant="destructive">
            <AlertDescription>{{ errors.form }}</AlertDescription>
        </Alert>

        <div class="grid gap-5 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label for="supplier_id">仕入先</Label>
                <select
                    id="supplier_id"
                    v-model="form.supplier_id"
                    name="supplier_id"
                    :class="selectClass"
                    :aria-invalid="errors.supplier_id ? true : undefined"
                    aria-describedby="supplier_id-error"
                    required
                >
                    <option value="">仕入先を選択</option>
                    <option
                        v-for="s in suppliers"
                        :key="s.id"
                        :value="String(s.id)"
                    >
                        {{ s.code }} {{ s.name
                        }}{{ s.is_active ? '' : '（無効）' }}
                    </option>
                </select>
                <InputError
                    id="supplier_id-error"
                    :message="errors.supplier_id"
                />
            </div>
            <div class="grid gap-2">
                <Label for="expected_date">入荷予定日（任意）</Label>
                <Input
                    id="expected_date"
                    v-model="form.expected_date"
                    name="expected_date"
                    type="date"
                    :min="order?.order_date"
                    :aria-invalid="errors.expected_date ? true : undefined"
                    aria-describedby="expected_date-error"
                />
                <p class="text-xs text-muted-foreground">
                    発注日は{{
                        order ? ` ${order.order_date} ` : '作成した日'
                    }}です（発注番号の日付になります）
                </p>
                <InputError
                    id="expected_date-error"
                    :message="errors.expected_date"
                />
            </div>
        </div>

        <OrderLinesEditor
            v-model="form.lines"
            :products="products"
            :limits="limits"
            :errors="errors"
            price-label="商品の仕入単価"
        />

        <div class="grid gap-2">
            <Label for="note">備考（任意）</Label>
            <textarea
                id="note"
                v-model="form.note"
                name="note"
                rows="3"
                :maxlength="limits.note"
                class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive dark:bg-input/30"
                :aria-invalid="errors.note ? true : undefined"
                aria-describedby="note-error"
            />
            <InputError id="note-error" :message="errors.note" />
        </div>

        <div class="flex gap-2">
            <Button
                type="submit"
                :disabled="form.processing"
                data-test="purchase-order-submit"
            >
                <Spinner v-if="form.processing" />
                {{ submitLabel }}
            </Button>
            <Button variant="ghost" as-child>
                <Link :href="cancelHref">キャンセル</Link>
            </Button>
        </div>
    </form>
</template>
