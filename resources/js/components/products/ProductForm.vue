<script setup lang="ts">
import type { InertiaLinkProps } from '@inertiajs/vue3';
import { Link, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { PRODUCT_FIELD_LABELS } from '@/lib/products';
import type { Product, ProductLimits } from '@/types/products';
import type { RouteDefinition } from '@/wayfinder';

/**
 * 商品の登録・編集フォーム。入力はすべて文字列で送り、検証・正規化（コードの大文字化・全角数字など）はサーバー側で行う
 * （App\Http\Requests\ProductRequest。画面の maxlength などは補助）
 */
const props = defineProps<{
    product?: Product;
    categories: string[];
    limits: ProductLimits;
    action: RouteDefinition<'post' | 'put'>;
    submitLabel: string;
    cancelHref: NonNullable<InertiaLinkProps['href']>;
}>();

const form = useForm({
    code: props.product?.code ?? '',
    name: props.product?.name ?? '',
    category: props.product?.category ?? '',
    unit: props.product?.unit ?? '',
    sales_price: props.product ? String(props.product.sales_price) : '',
    purchase_price: props.product ? String(props.product.purchase_price) : '',
    reorder_point: props.product ? String(props.product.reorder_point) : '',
});

const textFields = [
    {
        key: 'code',
        maxlength: props.limits.code,
        hint: '例: P-0001（英字は大文字で保存します）',
    },
    { key: 'name', maxlength: props.limits.name },
    {
        key: 'category',
        maxlength: props.limits.category,
        list: 'product-categories',
    },
    { key: 'unit', maxlength: props.limits.unit, hint: '例: 個・箱・kg' },
] as const;

const numberFields = [
    { key: 'sales_price', suffix: '円' },
    { key: 'purchase_price', suffix: '円' },
    { key: 'reorder_point', suffix: props.product?.unit ?? '' },
] as const;

function submit() {
    form.submit(props.action);
}
</script>

<template>
    <form class="grid max-w-xl gap-5" novalidate @submit.prevent="submit">
        <div v-for="field in textFields" :key="field.key" class="grid gap-2">
            <Label :for="field.key">{{
                PRODUCT_FIELD_LABELS[field.key]
            }}</Label>
            <Input
                :id="field.key"
                v-model="form[field.key]"
                :name="field.key"
                :maxlength="field.maxlength"
                :list="'list' in field ? field.list : undefined"
                :aria-invalid="form.errors[field.key] ? true : undefined"
                :aria-describedby="`${field.key}-error`"
                :class="field.key === 'code' ? 'font-mono' : undefined"
                required
            />
            <p v-if="'hint' in field" class="text-xs text-muted-foreground">
                {{ field.hint }}
            </p>
            <InputError
                :id="`${field.key}-error`"
                :message="form.errors[field.key]"
            />
        </div>
        <datalist id="product-categories">
            <option v-for="c in categories" :key="c" :value="c" />
        </datalist>

        <div class="grid gap-5 sm:grid-cols-3">
            <div
                v-for="field in numberFields"
                :key="field.key"
                class="grid gap-2"
            >
                <Label :for="field.key">
                    {{ PRODUCT_FIELD_LABELS[field.key] }}
                    <span
                        v-if="field.suffix"
                        class="text-xs text-muted-foreground"
                    >
                        （{{ field.suffix }}）
                    </span>
                </Label>
                <Input
                    :id="field.key"
                    v-model="form[field.key]"
                    :name="field.key"
                    inputmode="numeric"
                    class="text-right tabular-nums"
                    :aria-invalid="form.errors[field.key] ? true : undefined"
                    :aria-describedby="`${field.key}-error`"
                    required
                />
                <InputError
                    :id="`${field.key}-error`"
                    :message="form.errors[field.key]"
                />
            </div>
        </div>

        <div class="flex gap-2">
            <Button
                type="submit"
                :disabled="form.processing"
                data-test="product-submit"
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
