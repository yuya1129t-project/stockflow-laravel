<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { create, index, store } from '@/routes/users';
import type { RoleOption, UserLimits } from '@/types/users';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'ユーザー管理', href: index() },
            { title: '登録', href: create() },
        ],
    },
});

defineProps<{
    roleOptions: RoleOption[];
    limits: UserLimits;
}>();

/** 入力検証はサーバー側（StoreUserRequest）で行い、エラーは項目ごとに表示する */
const form = useForm({
    name: '',
    email: '',
    role: 'viewer',
    password: '',
});

function submit() {
    form.submit(store(), { onError: () => form.reset('password') });
}

const selectClass =
    'border-input h-9 w-full rounded-md border bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm dark:bg-input/30';
</script>

<template>
    <Head title="ユーザーの登録" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <h1 class="text-xl font-semibold">ユーザーの登録</h1>

        <form class="grid max-w-xl gap-4" @submit.prevent="submit">
            <div class="grid gap-1.5">
                <Label for="user-name">名前</Label>
                <Input
                    id="user-name"
                    v-model="form.name"
                    :maxlength="limits.name"
                    required
                    autocomplete="off"
                />
                <InputError :message="form.errors.name" />
            </div>
            <div class="grid gap-1.5">
                <Label for="user-email">メールアドレス</Label>
                <Input
                    id="user-email"
                    v-model="form.email"
                    type="email"
                    :maxlength="limits.email"
                    required
                    autocomplete="off"
                    placeholder="例 user@example.com"
                />
                <p class="text-xs text-muted-foreground">
                    公開デモでは、ほかの閲覧者にも表示されます。実在のメールアドレスは入れないでください（例
                    test1@example.com）
                </p>
                <InputError :message="form.errors.email" />
            </div>
            <div class="grid gap-1.5">
                <Label for="user-role">ロール</Label>
                <select id="user-role" v-model="form.role" :class="selectClass">
                    <option
                        v-for="option in roleOptions"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </option>
                </select>
                <InputError :message="form.errors.role" />
            </div>
            <div class="grid gap-1.5">
                <Label for="user-password">初期パスワード</Label>
                <PasswordInput
                    id="user-password"
                    v-model="form.password"
                    required
                    autocomplete="new-password"
                />
                <p class="text-xs text-muted-foreground">
                    8 文字以上（本番では 12
                    文字以上・英大文字・英小文字・数字・記号を含む）。登録したユーザーに伝えてください
                </p>
                <InputError :message="form.errors.password" />
            </div>

            <div class="flex flex-wrap gap-2">
                <Button
                    type="submit"
                    :disabled="form.processing"
                    data-test="user-submit"
                >
                    <Spinner v-if="form.processing" />
                    登録する
                </Button>
                <Button variant="ghost" as-child>
                    <Link :href="index()">キャンセル</Link>
                </Button>
            </div>
        </form>
    </div>
</template>
