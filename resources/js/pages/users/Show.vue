<script setup lang="ts">
import { Head, Link, router, useForm, useHttp, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { deactivate, index, password, reactivate, role } from '@/routes/users';
import { revoke, store as storeToken } from '@/routes/users/tokens';
import type {
    ApiTokenItem,
    IssuedApiToken,
    ManagedUser,
    RoleOption,
    UserLimits,
} from '@/types/users';

const props = defineProps<{
    user: ManagedUser;
    tokens: ApiTokenItem[];
    roleOptions: RoleOption[];
    maxActiveTokens: number;
    limits: UserLimits;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'ユーザー管理', href: index() }],
    },
});

const page = usePage();
/** 画面遷移を伴わない操作（無効化・失効）で返った全体のエラー（UserService の 'form'） */
const formError = computed(() => page.props.errors?.form as string | undefined);

/** ロール変更・無効化ができるか（自分自身・デモ用アカウントは不可。検査はサーバー側） */
const locked = computed(() => props.user.isSelf || props.user.isDemo);

// --- ロール変更 ---
const roleForm = useForm({ role: props.user.role });

function submitRole() {
    roleForm.submit(role(props.user.id), { preserveScroll: true });
}

// --- 無効化・再有効化 ---
const activeOpen = ref(false);
const activeProcessing = ref(false);

function toggleActive() {
    const action = props.user.isActive
        ? deactivate(props.user.id)
        : reactivate(props.user.id);
    router.visit(action.url, {
        method: action.method,
        preserveScroll: true,
        onStart: () => (activeProcessing.value = true),
        onFinish: () => {
            activeProcessing.value = false;
            activeOpen.value = false;
        },
    });
}

// --- パスワード再設定 ---
const passwordForm = useForm({ password: '', revoke_tokens: true });
const passwordOpen = ref(false);

function submitPassword() {
    passwordForm.submit(password(props.user.id), {
        preserveScroll: true,
        onFinish: () => {
            passwordOpen.value = false;
            passwordForm.reset('password');
        },
    });
}

// --- API トークン ---
const activeTokenCount = computed(
    () => props.tokens.filter((t) => t.revokedAt === null).length,
);

/**
 * 発行は JSON で受け取る（平文のトークンはこの応答でしか返らない。画面遷移のフラッシュに載せるとセッションに保存されるため）。
 * 表示したトークンはこの画面の状態にだけ持ち、閉じたら消す
 */
const tokenHttp = useHttp<{ name: string }, IssuedApiToken>({ name: '' });
const issued = ref<IssuedApiToken | null>(null);
const copied = ref(false);

function issueToken() {
    tokenHttp.post(storeToken.url(props.user.id), {
        onSuccess: (data) => {
            issued.value = data;
            copied.value = false;
            tokenHttp.reset();
            router.reload({ only: ['tokens'] });
        },
    });
}

async function copyToken() {
    if (!issued.value) return;
    try {
        await navigator.clipboard.writeText(issued.value.token);
        copied.value = true;
    } catch {
        copied.value = false;
    }
}

function closeIssued(open: boolean) {
    if (!open) issued.value = null;
}

const revokeTarget = ref<ApiTokenItem | null>(null);
const revokeProcessing = ref(false);

function revokeToken() {
    if (!revokeTarget.value) return;
    const action = revoke({
        user: props.user.id,
        token: revokeTarget.value.id,
    });
    router.visit(action.url, {
        method: action.method,
        preserveScroll: true,
        onStart: () => (revokeProcessing.value = true),
        onFinish: () => {
            revokeProcessing.value = false;
            revokeTarget.value = null;
        },
    });
}

const selectClass =
    'border-input h-9 rounded-md border bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm dark:bg-input/30';
</script>

<template>
    <Head :title="`ユーザー ${user.name}`" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-xl font-semibold">{{ user.name }}</h1>
            <Badge variant="outline">{{ user.roleLabel }}</Badge>
            <Badge v-if="user.isDemo" variant="outline">デモ</Badge>
            <Badge
                v-if="!user.isActive"
                variant="secondary"
                data-test="user-inactive"
                >無効</Badge
            >
        </div>

        <Alert v-if="formError" variant="destructive" data-test="user-error">
            <AlertDescription>{{ formError }}</AlertDescription>
        </Alert>
        <Alert v-if="user.isDemo">
            <AlertDescription>
                デモ用アカウントは、ほかの閲覧者もデモで使うため、ロール変更・無効化・パスワード再設定はできません（API
                トークンの発行・失効はできます）。試すときは新しいユーザーを登録してください。
            </AlertDescription>
        </Alert>

        <dl
            class="grid max-w-xl grid-cols-[8rem_1fr] gap-x-4 gap-y-2 rounded-xl border p-4 text-sm"
        >
            <dt class="text-muted-foreground">名前</dt>
            <dd>{{ user.name }}</dd>
            <dt class="text-muted-foreground">メールアドレス</dt>
            <dd class="break-all">{{ user.email }}</dd>
            <dt class="text-muted-foreground">ロール</dt>
            <dd>{{ user.roleLabel }}</dd>
            <dt class="text-muted-foreground">状態</dt>
            <dd>{{ user.isActive ? '有効' : '無効' }}</dd>
            <dt class="text-muted-foreground">登録日時</dt>
            <dd>{{ user.createdAt }}</dd>
        </dl>

        <!-- ロール変更・無効化 -->
        <section class="grid max-w-xl gap-3">
            <h2 class="font-semibold">ロールと状態</h2>
            <p v-if="user.isSelf" class="text-sm text-muted-foreground">
                自分自身のロール変更・無効化はできません。
            </p>
            <form
                class="flex flex-wrap items-end gap-2"
                @submit.prevent="submitRole"
            >
                <div class="grid gap-1.5">
                    <Label for="user-role">ロール</Label>
                    <select
                        id="user-role"
                        v-model="roleForm.role"
                        :class="selectClass"
                        :disabled="locked"
                    >
                        <option
                            v-for="option in roleOptions"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                </div>
                <Button
                    type="submit"
                    variant="outline"
                    :disabled="
                        locked ||
                        roleForm.processing ||
                        roleForm.role === user.role
                    "
                    data-test="user-role-submit"
                >
                    ロールを変更
                </Button>
                <Button
                    type="button"
                    :variant="user.isActive ? 'destructive' : 'default'"
                    :disabled="locked"
                    data-test="user-toggle-active"
                    @click="activeOpen = true"
                >
                    {{ user.isActive ? '無効にする' : '再度有効にする' }}
                </Button>
            </form>
            <InputError :message="roleForm.errors.role" />
        </section>

        <!-- パスワード再設定 -->
        <section class="grid max-w-xl gap-3">
            <h2 class="font-semibold">パスワード再設定</h2>
            <p class="text-sm text-muted-foreground">
                新しいパスワードを設定します。このユーザーのログイン中のセッションはすべて終了します{{
                    user.isSelf
                        ? '（自分自身の場合は、この画面からもログアウトします）'
                        : ''
                }}。
            </p>
            <form class="grid gap-3" @submit.prevent="passwordOpen = true">
                <div class="grid gap-1.5">
                    <Label for="user-new-password">新しいパスワード</Label>
                    <PasswordInput
                        id="user-new-password"
                        v-model="passwordForm.password"
                        autocomplete="new-password"
                        required
                        :disabled="user.isDemo"
                    />
                    <InputError :message="passwordForm.errors.password" />
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <input
                        v-model="passwordForm.revoke_tokens"
                        type="checkbox"
                        class="size-4"
                        :disabled="user.isDemo"
                        data-test="user-revoke-tokens"
                    />
                    API トークンもすべて失効させる
                </label>
                <InputError :message="passwordForm.errors.revoke_tokens" />
                <div>
                    <Button
                        type="submit"
                        variant="outline"
                        :disabled="user.isDemo || passwordForm.processing"
                        data-test="user-password-submit"
                    >
                        パスワードを再設定
                    </Button>
                </div>
            </form>
        </section>

        <!-- API トークン -->
        <section class="grid gap-3">
            <h2 class="font-semibold">API トークン</h2>
            <p class="max-w-xl text-sm text-muted-foreground">
                REST API（/api/v1）を
                <code>Authorization: Bearer &lt;トークン&gt;</code>
                で呼ぶためのトークンです。権限はこのユーザーのロールに従います。トークンは発行したときに
                1 回だけ表示します。有効なトークンは 1 ユーザーにつき
                {{ maxActiveTokens }} 件まで（いま {{ activeTokenCount }} 件）。
            </p>
            <form
                v-if="user.isActive"
                class="flex flex-wrap items-end gap-2"
                @submit.prevent="issueToken"
            >
                <div class="grid gap-1.5">
                    <Label for="token-name">トークンの名前</Label>
                    <Input
                        id="token-name"
                        v-model="tokenHttp.name"
                        class="w-64"
                        :maxlength="limits.token_name"
                        required
                        placeholder="例 在庫連携スクリプト"
                    />
                </div>
                <Button
                    type="submit"
                    :disabled="tokenHttp.processing"
                    data-test="token-issue"
                >
                    <Spinner v-if="tokenHttp.processing" />
                    発行する
                </Button>
            </form>
            <p v-else class="text-sm text-muted-foreground">
                無効なユーザーには API トークンを発行できません。
            </p>
            <InputError
                :message="
                    (tokenHttp.errors as Record<string, string | undefined>)
                        .form ?? tokenHttp.errors.name
                "
            />

            <div class="overflow-x-auto rounded-xl border">
                <table class="w-full min-w-[36rem] text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th scope="col" class="px-3 py-2 font-medium">
                                名前
                            </th>
                            <th scope="col" class="px-3 py-2 font-medium">
                                発行日時
                            </th>
                            <th scope="col" class="px-3 py-2 font-medium">
                                最終使用
                            </th>
                            <th scope="col" class="px-3 py-2 font-medium">
                                状態
                            </th>
                            <th scope="col" class="px-3 py-2 font-medium">
                                <span class="sr-only">操作</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="token in tokens"
                            :key="token.id"
                            class="border-t"
                            data-test="token-row"
                        >
                            <td class="px-3 py-2 break-all">
                                {{ token.name }}
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap">
                                {{ token.createdAt }}
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap">
                                {{ token.lastUsedAt ?? '未使用' }}
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap">
                                <Badge
                                    v-if="token.revokedAt"
                                    variant="secondary"
                                    >失効（{{ token.revokedAt }}）</Badge
                                >
                                <span v-else>有効</span>
                            </td>
                            <td class="px-3 py-2 text-right">
                                <Button
                                    v-if="!token.revokedAt"
                                    size="sm"
                                    variant="outline"
                                    data-test="token-revoke"
                                    @click="revokeTarget = token"
                                >
                                    失効させる
                                </Button>
                            </td>
                        </tr>
                        <tr v-if="tokens.length === 0">
                            <td
                                colspan="5"
                                class="px-3 py-6 text-center text-muted-foreground"
                            >
                                API トークンはありません
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <div>
            <Button variant="ghost" as-child>
                <Link :href="index()">一覧へ戻る</Link>
            </Button>
        </div>

        <!-- 無効化・再有効化の確認 -->
        <Dialog v-model:open="activeOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {{
                            user.isActive
                                ? 'ユーザーを無効にしますか？'
                                : 'ユーザーを再度有効にしますか？'
                        }}
                    </DialogTitle>
                    <DialogDescription>
                        {{
                            user.isActive
                                ? '無効にすると、このユーザーはログインできなくなります。ログイン中のセッションはすべて終了し、API トークンはすべて失効します（再度有効にしてもトークンは戻りません）。'
                                : '再度有効にすると、このユーザーは元のパスワードでログインできるようになります。API トークンは必要なら発行し直してください。'
                        }}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="outline">キャンセル</Button>
                    </DialogClose>
                    <Button
                        :variant="user.isActive ? 'destructive' : 'default'"
                        :disabled="activeProcessing"
                        data-test="user-toggle-active-confirm"
                        @click="toggleActive"
                    >
                        {{ user.isActive ? '無効にする' : '再度有効にする' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- パスワード再設定の確認 -->
        <Dialog v-model:open="passwordOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>パスワードを再設定しますか？</DialogTitle>
                    <DialogDescription>
                        「{{
                            user.name
                        }}」のログイン中のセッションはすべて終了します{{
                            passwordForm.revoke_tokens
                                ? '。API トークンもすべて失効します'
                                : '（API トークンは失効させません）'
                        }}。
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="outline">キャンセル</Button>
                    </DialogClose>
                    <Button
                        :disabled="passwordForm.processing"
                        data-test="user-password-confirm"
                        @click="submitPassword"
                    >
                        再設定する
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- 発行したトークン（1 回だけ表示） -->
        <Dialog :open="issued !== null" @update:open="closeIssued">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>API トークンを発行しました</DialogTitle>
                    <DialogDescription>
                        このトークンは今だけ表示します。閉じると二度と表示できないので、いまコピーして安全な場所に保管してください。
                    </DialogDescription>
                </DialogHeader>
                <div v-if="issued" class="grid gap-2">
                    <p class="text-sm">「{{ issued.name }}」</p>
                    <code
                        class="rounded-md border bg-muted p-2 text-xs break-all"
                        data-test="token-plain"
                        >{{ issued.token }}</code
                    >
                </div>
                <DialogFooter class="gap-2">
                    <Button variant="outline" @click="copyToken">
                        {{ copied ? 'コピーしました' : 'コピー' }}
                    </Button>
                    <DialogClose as-child>
                        <Button>閉じる</Button>
                    </DialogClose>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- 失効の確認 -->
        <Dialog
            :open="revokeTarget !== null"
            @update:open="(open: boolean) => !open && (revokeTarget = null)"
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>API トークンを失効させますか？</DialogTitle>
                    <DialogDescription>
                        「{{ revokeTarget?.name }}」で REST API
                        を呼べなくなります。元に戻せません。
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="outline">キャンセル</Button>
                    </DialogClose>
                    <Button
                        variant="destructive"
                        :disabled="revokeProcessing"
                        data-test="token-revoke-confirm"
                        @click="revokeToken"
                    >
                        失効させる
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
