<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatNumber, PRODUCT_FIELD_LABELS } from '@/lib/products';
import { importMethod, index } from '@/routes/products';
import { store } from '@/routes/products/import';
import type { ProductImportResult } from '@/types/products';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: '商品', href: index() },
            { title: 'CSV 取込', href: importMethod() },
        ],
    },
});

const props = defineProps<{
    limits: { maxRows: number; maxBytes: number };
}>();

const page = usePage();
// 取込の結果（サーバーが 1 回だけの flash で返す。App\Http\Controllers\ProductImportController）
const result = computed(
    () => page.flash.importResult as ProductImportResult | undefined,
);

const form = useForm<{ file: File | null }>({ file: null });
// 送信後にファイルの選択を消すため、入力欄を作り直す
const fileInputKey = ref(0);
const clientError = ref<string>();
const maxKb = computed(() => props.limits.maxBytes / 1024);

function onFileChange(event: Event) {
    const input = event.target as HTMLInputElement;
    form.file = input.files?.[0] ?? null;
    clientError.value = undefined;
}

// 大きすぎるファイルは送信しない（サーバー側でも同じ上限を検査する。PHP の送信サイズの上限を超えると
// 検査に届く前に拒否されるため、画面で先に止める）
function submit() {
    if (form.file === null) {
        clientError.value = 'CSV ファイルを選択してください';
        return;
    }
    if (form.file.size > props.limits.maxBytes) {
        clientError.value = `ファイルが大きすぎます（上限 ${maxKb.value}KB）。ファイルを分けて取り込んでください`;
        return;
    }
    clientError.value = undefined;
    form.submit(store(), {
        forceFormData: true,
        onFinish: () => {
            form.reset();
            fileInputKey.value++;
        },
    });
}

const requiredColumns = Object.values(PRODUCT_FIELD_LABELS).join('・');
</script>

<template>
    <Head title="商品の CSV 取込" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <h1 class="text-xl font-semibold">商品の CSV 取込</h1>

        <div class="max-w-2xl space-y-1 text-sm text-muted-foreground">
            <p>
                商品コードが登録済みなら更新、なければ新規登録します。1
                行でもエラーがあれば、何も取り込みません（エラーの行番号と内容を表示します）。
            </p>
            <ul class="list-disc space-y-1 pl-5">
                <li>
                    文字コードは UTF-8（Excel では「CSV
                    UTF-8（コンマ区切り）」で保存）
                </li>
                <li>
                    1 行目は見出し行。必要な列:
                    {{ requiredColumns }}（列の順番は問いません）
                </li>
                <li>
                    データ行は
                    {{ formatNumber(limits.maxRows) }} 行まで、ファイルは
                    {{ maxKb }}KB まで
                </li>
                <li>
                    一覧の「CSV
                    出力」のファイルを編集してそのまま取り込めます（「現在庫」「状態」の列は読み飛ばします。有効／無効は
                    CSV では変わりません）
                </li>
            </ul>
        </div>

        <form
            class="flex max-w-2xl flex-wrap items-end gap-2"
            @submit.prevent="submit"
        >
            <div class="grid w-full gap-1.5 sm:w-auto sm:min-w-80">
                <Label for="file">CSV ファイル</Label>
                <Input
                    id="file"
                    :key="fileInputKey"
                    type="file"
                    accept=".csv,text/csv"
                    @change="onFileChange"
                />
            </div>
            <Button
                type="submit"
                :disabled="form.processing"
                data-test="import-submit"
            >
                <Spinner v-if="form.processing" />
                {{ form.processing ? '取込中…' : '取り込む' }}
            </Button>
        </form>

        <p
            v-if="clientError"
            role="alert"
            class="max-w-2xl rounded-lg border border-destructive/40 bg-destructive/5 px-3 py-2 text-sm text-destructive"
        >
            {{ clientError }}
        </p>
        <InputError v-else :message="form.errors.file" />

        <template v-if="!clientError && result">
            <div
                v-if="result.status === 'success'"
                role="status"
                class="max-w-2xl space-y-2 rounded-lg border border-emerald-600/30 bg-emerald-600/5 p-3 text-sm"
            >
                <p class="font-medium">
                    {{ result.fileName }} を取り込みました: 新規
                    {{ formatNumber(result.summary.created) }} 件・更新
                    {{ formatNumber(result.summary.updated) }} 件・変更なし
                    {{ formatNumber(result.summary.unchanged) }} 件
                </p>
                <Button variant="outline" size="sm" as-child>
                    <Link :href="index()">商品一覧へ</Link>
                </Button>
            </div>

            <div v-else role="alert" class="space-y-3">
                <p
                    class="max-w-2xl rounded-lg border border-destructive/40 bg-destructive/5 px-3 py-2 text-sm text-destructive"
                >
                    <span class="font-medium">{{ result.fileName }}: </span>
                    {{ result.message }}
                </p>
                <div
                    v-if="result.errors.length > 0"
                    class="max-w-3xl space-y-2"
                >
                    <p class="text-sm">
                        エラー {{ formatNumber(result.totalErrors) }} 件
                        <template
                            v-if="result.totalErrors > result.errors.length"
                        >
                            （先頭の {{ result.errors.length }} 件を表示）
                        </template>
                    </p>
                    <div class="overflow-x-auto rounded-xl border">
                        <table class="w-full text-sm" data-test="import-errors">
                            <thead class="border-b bg-muted/50 text-left">
                                <tr>
                                    <th
                                        scope="col"
                                        class="w-20 px-3 py-2 text-right font-medium"
                                    >
                                        行
                                    </th>
                                    <th
                                        scope="col"
                                        class="w-28 px-3 py-2 font-medium"
                                    >
                                        項目
                                    </th>
                                    <th
                                        scope="col"
                                        class="px-3 py-2 font-medium"
                                    >
                                        内容
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(error, i) in result.errors"
                                    :key="i"
                                    class="border-b last:border-0"
                                >
                                    <td
                                        class="px-3 py-2 text-right whitespace-nowrap tabular-nums"
                                    >
                                        {{
                                            error.line === null
                                                ? '—'
                                                : `${error.line} 行目`
                                        }}
                                    </td>
                                    <td class="px-3 py-2 whitespace-nowrap">
                                        {{ error.field ?? '—' }}
                                    </td>
                                    <td class="px-3 py-2">
                                        {{ error.message }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>
