<script setup lang="ts">
import { Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { calcOrderAmounts, EMPTY_ORDER_LINE, lineAmount } from '@/lib/orders';
import { formatNumber } from '@/lib/products';
import type {
    LineProductOption,
    OrderLimits,
    OrderLineForm,
} from '@/types/orders';

/**
 * 受注・発注の明細の編集（行の追加・削除、小計・税・合計の表示）。受注（#8）と共通の部品。
 * 金額の表示はサーバー（App\Orders\OrderAmounts）と同じ式で計算するが、保存する金額はサーバーが明細から計算する。
 * エラーは Inertia の form.errors（lines.N.product_id など）をそのまま渡す
 */
const lines = defineModel<OrderLineForm[]>({ required: true });

const props = defineProps<{
    products: LineProductOption[];
    limits: OrderLimits;
    errors: Record<string, string | undefined>;
    /** 単価の初期値の説明（例「仕入単価」） */
    priceLabel: string;
}>();

const productById = computed(
    () => new Map(props.products.map((p) => [String(p.id), p])),
);

const amounts = computed(() =>
    calcOrderAmounts(lines.value, props.limits.taxRatePercent),
);

function addLine() {
    lines.value = [...lines.value, { ...EMPTY_ORDER_LINE }];
}

function removeLine(index: number) {
    lines.value = lines.value.filter((_, i) => i !== index);
}

/** 商品を選んだら、単価が空か前の商品の初期値のままなら、選んだ商品の初期値にする */
function selectProduct(index: number, productId: string) {
    const current = lines.value[index];
    const previous = productById.value.get(current.product_id);
    const next = productById.value.get(productId);
    const keepPrice =
        current.unit_price !== '' &&
        (previous === undefined ||
            current.unit_price !== String(previous.price));
    lines.value = lines.value.map((line, i) =>
        i === index
            ? {
                  ...line,
                  product_id: productId,
                  unit_price:
                      keepPrice || next === undefined
                          ? line.unit_price
                          : String(next.price),
              }
            : line,
    );
}

function updateField(
    index: number,
    field: 'quantity' | 'unit_price',
    value: string | number,
) {
    lines.value = lines.value.map((line, i) =>
        i === index ? { ...line, [field]: String(value) } : line,
    );
}

const errorOf = (index: number, field: keyof OrderLineForm) =>
    props.errors[`lines.${index}.${field}`];

const selectClass =
    'h-9 w-full min-w-48 rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive dark:bg-input/30';
</script>

<template>
    <fieldset class="grid gap-3">
        <legend class="mb-2 text-sm font-medium">明細</legend>
        <p class="text-xs text-muted-foreground">
            単価の初期値は{{ priceLabel }}です（変更できます）。同じ商品は 1
            行にまとめてください（{{ limits.lines }} 行まで）。
        </p>

        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="border-b bg-muted/50 text-left">
                    <tr>
                        <th scope="col" class="px-3 py-2 font-medium">商品</th>
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
                        <th scope="col" class="px-3 py-2">
                            <span class="sr-only">削除</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="lines.length === 0">
                        <td
                            colspan="5"
                            class="px-3 py-4 text-center text-muted-foreground"
                        >
                            明細がありません（下書きは明細なしでも保存できます）
                        </td>
                    </tr>
                    <tr
                        v-for="(line, index) in lines"
                        :key="index"
                        class="border-b align-top last:border-0"
                        data-test="order-line"
                    >
                        <td class="px-3 py-2">
                            <select
                                :value="line.product_id"
                                :aria-label="`${index + 1} 行目の商品`"
                                :aria-invalid="
                                    errorOf(index, 'product_id')
                                        ? true
                                        : undefined
                                "
                                :class="selectClass"
                                @change="
                                    selectProduct(
                                        index,
                                        ($event.target as HTMLSelectElement)
                                            .value,
                                    )
                                "
                            >
                                <option value="">商品を選択</option>
                                <option
                                    v-for="p in products"
                                    :key="p.id"
                                    :value="String(p.id)"
                                >
                                    {{ p.code }} {{ p.name
                                    }}{{ p.is_active ? '' : '（無効）' }}
                                </option>
                            </select>
                            <InputError
                                :message="errorOf(index, 'product_id')"
                            />
                        </td>
                        <td class="px-3 py-2">
                            <div class="flex items-center gap-1">
                                <Input
                                    :model-value="line.quantity"
                                    inputmode="numeric"
                                    :aria-label="`${index + 1} 行目の数量`"
                                    :aria-invalid="
                                        errorOf(index, 'quantity')
                                            ? true
                                            : undefined
                                    "
                                    class="w-24 text-right tabular-nums"
                                    @update:model-value="
                                        updateField(index, 'quantity', $event)
                                    "
                                />
                                <span class="w-6 text-xs text-muted-foreground">
                                    {{
                                        productById.get(line.product_id)
                                            ?.unit ?? ''
                                    }}
                                </span>
                            </div>
                            <InputError :message="errorOf(index, 'quantity')" />
                        </td>
                        <td class="px-3 py-2">
                            <Input
                                :model-value="line.unit_price"
                                inputmode="numeric"
                                :aria-label="`${index + 1} 行目の単価`"
                                :aria-invalid="
                                    errorOf(index, 'unit_price')
                                        ? true
                                        : undefined
                                "
                                class="w-28 text-right tabular-nums"
                                @update:model-value="
                                    updateField(index, 'unit_price', $event)
                                "
                            />
                            <InputError
                                :message="errorOf(index, 'unit_price')"
                            />
                        </td>
                        <td
                            class="px-3 py-2 pt-4 text-right whitespace-nowrap tabular-nums"
                        >
                            {{
                                lineAmount(line) === null
                                    ? '—'
                                    : formatNumber(lineAmount(line) ?? 0)
                            }}
                        </td>
                        <td class="px-3 py-2">
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                :aria-label="`${index + 1} 行目を削除`"
                                @click="removeLine(index)"
                            >
                                <Trash2 />
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div>
            <Button
                type="button"
                variant="outline"
                size="sm"
                :disabled="lines.length >= limits.lines"
                data-test="order-line-add"
                @click="addLine"
            >
                行を追加
            </Button>
        </div>
        <InputError :message="errors.lines" />

        <dl
            class="ml-auto grid w-full max-w-xs grid-cols-[1fr_auto] gap-x-4 gap-y-1 text-sm"
            aria-live="polite"
        >
            <dt class="text-muted-foreground">小計</dt>
            <dd class="text-right tabular-nums" data-test="order-subtotal">
                {{ formatNumber(amounts.subtotal) }} 円
            </dd>
            <dt class="text-muted-foreground">
                消費税（{{ limits.taxRatePercent }}%・切り捨て）
            </dt>
            <dd class="text-right tabular-nums" data-test="order-tax">
                {{ formatNumber(amounts.tax) }} 円
            </dd>
            <dt class="font-medium">合計</dt>
            <dd
                class="text-right font-semibold tabular-nums"
                data-test="order-total"
            >
                {{ formatNumber(amounts.total) }} 円
            </dd>
        </dl>
        <p
            v-if="amounts.total > limits.total"
            class="text-right text-sm text-destructive"
        >
            合計金額が上限（{{ formatNumber(limits.total) }}
            円）を超えています
        </p>
    </fieldset>
</template>
