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
import type { SalesOrderDetail, CustomerOption } from '@/types/sales-orders';
import type { RouteDefinition } from '@/wayfinder';

/**
 * 受注の作成・編集フォーム。入力はすべて文字列で送り、検証・金額の計算はサーバー側で行う
 * （App\Http\Requests\SalesOrderRequest・App\SalesOrders\SalesOrderService）。
 * 編集では画面を開いた時点の version を一緒に送り、他の人が先に更新していたら保存されない（競合）
 */
const props = defineProps<{
    order?: SalesOrderDetail;
    customers: CustomerOption[];
    products: LineProductOption[];
    limits: OrderLimits;
    action: RouteDefinition<'post' | 'put'>;
    submitLabel: string;
    cancelHref: NonNullable<InertiaLinkProps['href']>;
    /** 競合のときに開き直す画面（編集画面） */
    reloadHref?: NonNullable<InertiaLinkProps['href']>;
}>();

const form = useForm({
    customer_id: props.order ? String(props.order.customer.id) : '',
    due_date: props.order?.due_date ?? '',
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

// 選んでいる顧客が無効（編集で、今の顧客が無効になった下書き）。保存には有効な顧客が必要なので案内する
const selectedCustomerInactive = computed(
    () =>
        props.customers.find((s) => String(s.id) === form.customer_id)
            ?.is_active === false,
);

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
                <Label for="customer_id">顧客</Label>
                <select
                    id="customer_id"
                    v-model="form.customer_id"
                    name="customer_id"
                    :class="selectClass"
                    :aria-invalid="errors.customer_id ? true : undefined"
                    aria-describedby="customer_id-hint customer_id-error"
                    required
                >
                    <option value="">顧客を選択</option>
                    <option
                        v-for="s in customers"
                        :key="s.id"
                        :value="String(s.id)"
                        :disabled="!s.is_active"
                    >
                        {{ s.code }} {{ s.name
                        }}{{ s.is_active ? '' : '（無効）' }}
                    </option>
                </select>
                <p
                    v-if="selectedCustomerInactive"
                    id="customer_id-hint"
                    class="text-sm text-destructive"
                    data-test="customer-inactive"
                >
                    この顧客は無効です。別の顧客を選んでください
                </p>
                <InputError
                    id="customer_id-error"
                    :message="errors.customer_id"
                />
            </div>
            <div class="grid gap-2">
                <Label for="due_date">納期（任意）</Label>
                <Input
                    id="due_date"
                    v-model="form.due_date"
                    name="due_date"
                    type="date"
                    :min="order?.order_date"
                    :aria-invalid="errors.due_date ? true : undefined"
                    aria-describedby="due_date-error"
                />
                <p class="text-xs text-muted-foreground">
                    受注日は{{
                        order ? ` ${order.order_date} ` : '作成した日'
                    }}です（受注番号の日付になります）
                </p>
                <InputError id="due_date-error" :message="errors.due_date" />
            </div>
        </div>

        <OrderLinesEditor
            v-model="form.lines"
            :products="products"
            :limits="limits"
            :errors="errors"
            price-label="商品の売価"
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
                data-test="sales-order-submit"
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
