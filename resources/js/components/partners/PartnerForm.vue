<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    fetchAddresses,
    INVALID_POSTAL_CODE_MESSAGE,
    isLookupResultCurrent,
    normalizePostalCode,
} from '@/lib/partners';
import type { KindOption, LookupSnapshot, Partner } from '@/lib/partners';
import { index, show, store, update } from '@/routes/partners';

/**
 * 取引先の登録・編集フォーム。入力検証はサーバー側（PartnerRequest）で行い、エラーは項目ごとに表示する。
 * 郵便番号の「住所を検索」は、サーバー（GET /partners/address-lookup → zipcloud）に問い合わせて住所欄を埋める
 */
const props = defineProps<{
    /** 編集のときの取引先（登録のときは undefined） */
    partner?: Partner;
    kindOptions: KindOption[];
}>();

const form = useForm({
    code: props.partner?.code ?? '',
    name: props.partner?.name ?? '',
    kind: props.partner?.kind ?? 'customer',
    postal_code: props.partner?.postalCode ?? '',
    address: props.partner?.address ?? '',
    phone: props.partner?.phone ?? '',
    email: props.partner?.email ?? '',
});

function submit() {
    form.submit(props.partner ? update(props.partner.id) : store());
}

const cancelHref = computed(() =>
    props.partner ? show(props.partner.id) : index(),
);

// --- 住所検索（spec §6） ---

type LookupState =
    | { status: 'idle' }
    | { status: 'loading' }
    | { status: 'choices'; addresses: string[] }
    | { status: 'done'; message: string }
    | { status: 'error'; message: string };

const lookup = ref<LookupState>({ status: 'idle' });
// 住所検索のリクエストの識別子。郵便番号を変えたら進めて、検索中の古い応答を捨てる
let lookupRequestId = 0;
const addressInput = ref<InstanceType<typeof Input> | null>(null);

function snapshot(): LookupSnapshot {
    return {
        requestId: lookupRequestId,
        postalCode: form.postal_code,
        address: form.address,
    };
}

// 郵便番号を変えたら、検索中の応答・前の候補を無効にする
watch(
    () => form.postal_code,
    () => {
        lookupRequestId++;
        lookup.value = { status: 'idle' };
    },
);

async function fillAddress(address: string) {
    form.address = address;
    lookup.value = {
        status: 'done',
        message: '住所を入力しました。番地・建物名を続けて入力してください',
    };
    await nextTick();
    (addressInput.value?.$el as HTMLInputElement | undefined)?.focus();
}

async function searchAddress() {
    const postalCode = normalizePostalCode(form.postal_code);
    if (!postalCode) {
        lookup.value = {
            status: 'error',
            message: INVALID_POSTAL_CODE_MESSAGE,
        };
        return;
    }
    lookupRequestId++;
    const started = snapshot();
    lookup.value = { status: 'loading' };
    const result = await fetchAddresses(postalCode);
    // 検索中に郵便番号・住所が変わった（または次の検索が始まった）ら、古い結果で上書きしない
    if (!isLookupResultCurrent(started, snapshot())) {
        if (started.requestId === lookupRequestId) {
            lookup.value = {
                status: 'done',
                message:
                    '検索中に入力が変わったため、検索結果は使いませんでした。必要ならもう一度検索してください',
            };
        }
        return;
    }
    if (!result.ok) {
        lookup.value = { status: 'error', message: result.message };
        return;
    }
    if (result.addresses.length === 1) {
        await fillAddress(result.addresses[0]);
    } else {
        lookup.value = { status: 'choices', addresses: result.addresses };
    }
}

const lookupMessage = computed(() => {
    switch (lookup.value.status) {
        case 'loading':
            return '検索中…';
        case 'choices':
            return '候補が複数あります。選んでください（該当がなければ住所を手入力してください）';
        case 'done':
        case 'error':
            return lookup.value.message;
        default:
            return '';
    }
});

const selectClass =
    'border-input h-9 w-full rounded-md border bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive md:text-sm dark:bg-input/30';
</script>

<template>
    <form class="max-w-xl space-y-5" novalidate @submit.prevent="submit">
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="grid gap-1.5">
                <Label for="partner-kind">
                    種別
                    <span class="text-xs font-normal text-destructive"
                        >必須</span
                    >
                </Label>
                <select
                    id="partner-kind"
                    v-model="form.kind"
                    :class="selectClass"
                    :aria-invalid="form.errors.kind ? true : undefined"
                    data-test="partner-kind"
                >
                    <option
                        v-for="option in kindOptions"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </option>
                </select>
                <InputError :message="form.errors.kind" />
                <p
                    v-if="!form.errors.kind"
                    class="text-xs text-muted-foreground"
                >
                    顧客は受注、仕入先は発注で選べます
                </p>
            </div>

            <div class="grid gap-1.5">
                <Label for="partner-code">
                    取引先コード
                    <span class="text-xs font-normal text-destructive"
                        >必須</span
                    >
                </Label>
                <Input
                    id="partner-code"
                    v-model="form.code"
                    maxlength="20"
                    autocomplete="off"
                    placeholder="C-0001"
                    :aria-invalid="form.errors.code ? true : undefined"
                />
                <InputError :message="form.errors.code" />
                <p
                    v-if="!form.errors.code"
                    class="text-xs text-muted-foreground"
                >
                    半角英数字・ハイフン・アンダースコア（大文字で保存します）
                </p>
            </div>

            <div class="grid gap-1.5 sm:col-span-2">
                <Label for="partner-name">
                    取引先名
                    <span class="text-xs font-normal text-destructive"
                        >必須</span
                    >
                </Label>
                <Input
                    id="partner-name"
                    v-model="form.name"
                    maxlength="100"
                    autocomplete="off"
                    :aria-invalid="form.errors.name ? true : undefined"
                />
                <InputError :message="form.errors.name" />
            </div>

            <div class="grid gap-1.5 sm:col-span-2">
                <Label for="partner-postal-code">郵便番号</Label>
                <div class="flex gap-2">
                    <Input
                        id="partner-postal-code"
                        v-model="form.postal_code"
                        class="w-36"
                        inputmode="numeric"
                        maxlength="8"
                        autocomplete="off"
                        placeholder="123-4567"
                        :aria-invalid="
                            form.errors.postal_code ? true : undefined
                        "
                        aria-describedby="partner-lookup-message"
                    />
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="lookup.status === 'loading'"
                        data-test="address-lookup"
                        @click="searchAddress"
                    >
                        <Spinner v-if="lookup.status === 'loading'" />
                        住所を検索
                    </Button>
                </div>
                <InputError :message="form.errors.postal_code" />
                <p
                    id="partner-lookup-message"
                    role="status"
                    :class="
                        lookup.status === 'error'
                            ? 'text-xs text-destructive'
                            : 'text-xs text-muted-foreground'
                    "
                    data-test="address-lookup-message"
                >
                    {{
                        lookupMessage ||
                        'ハイフンは任意です。「住所を検索」で住所を入力できます'
                    }}
                </p>
                <ul
                    v-if="lookup.status === 'choices'"
                    class="flex flex-wrap gap-2"
                >
                    <li v-for="address in lookup.addresses" :key="address">
                        <Button
                            type="button"
                            size="sm"
                            variant="secondary"
                            @click="fillAddress(address)"
                        >
                            {{ address }}
                        </Button>
                    </li>
                </ul>
            </div>

            <div class="grid gap-1.5 sm:col-span-2">
                <Label for="partner-address">住所</Label>
                <Input
                    id="partner-address"
                    ref="addressInput"
                    v-model="form.address"
                    maxlength="200"
                    autocomplete="off"
                    :aria-invalid="form.errors.address ? true : undefined"
                />
                <InputError :message="form.errors.address" />
            </div>

            <div class="grid gap-1.5">
                <Label for="partner-phone">電話番号</Label>
                <Input
                    id="partner-phone"
                    v-model="form.phone"
                    type="tel"
                    maxlength="20"
                    autocomplete="off"
                    placeholder="03-1234-5678"
                    :aria-invalid="form.errors.phone ? true : undefined"
                />
                <InputError :message="form.errors.phone" />
            </div>

            <div class="grid gap-1.5">
                <Label for="partner-email">メールアドレス</Label>
                <Input
                    id="partner-email"
                    v-model="form.email"
                    type="email"
                    maxlength="254"
                    autocomplete="off"
                    placeholder="info@example.com"
                    :aria-invalid="form.errors.email ? true : undefined"
                />
                <InputError :message="form.errors.email" />
            </div>
        </div>

        <div class="flex gap-2">
            <Button
                type="submit"
                :disabled="form.processing"
                data-test="partner-submit"
            >
                <Spinner v-if="form.processing" />
                {{ partner ? '保存する' : '登録する' }}
            </Button>
            <Button variant="outline" as-child>
                <Link :href="cancelHref">キャンセル</Link>
            </Button>
        </div>
    </form>
</template>
