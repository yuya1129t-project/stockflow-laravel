<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/login';

defineOptions({
    layout: {
        title: 'StockFlow にログイン',
        description: '受発注・在庫管理（デモ）',
    },
});

type DemoAccount = {
    email: string;
    name: string;
    role: 'admin' | 'staff' | 'viewer';
};

// demoAccounts・demoPassword はシード（database/seeders/Demo/DemoData.php）の値
defineProps<{
    status?: string;
    demoAccounts: DemoAccount[];
    demoPassword: string;
}>();

const email = ref('');
const password = ref('');

/** デモ用アカウントのログイン情報を入力欄に入れる */
const fillDemoAccount = (account: DemoAccount, demoPassword: string) => {
    email.value = account.email;
    password.value = demoPassword;
};
</script>

<template>
    <Head title="ログイン" />

    <div
        v-if="status"
        class="mb-4 text-center text-sm font-medium text-green-600"
        data-test="login-status"
    >
        {{ status }}
    </div>

    <Form
        v-bind="store.form()"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div class="grid gap-6">
            <div class="grid gap-2">
                <Label for="email">メールアドレス</Label>
                <Input
                    id="email"
                    v-model="email"
                    type="email"
                    name="email"
                    required
                    v-focus
                    :tabindex="1"
                    autocomplete="email"
                    placeholder="email@example.com"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="password">パスワード</Label>
                <PasswordInput
                    id="password"
                    v-model="password"
                    name="password"
                    required
                    :tabindex="2"
                    autocomplete="current-password"
                    placeholder="パスワード"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="flex items-center justify-between">
                <Label for="remember" class="flex items-center space-x-3">
                    <Checkbox id="remember" name="remember" :tabindex="3" />
                    <span>ログインしたままにする</span>
                </Label>
            </div>

            <Button
                type="submit"
                class="mt-4 w-full"
                :tabindex="4"
                :disabled="processing"
                data-test="login-button"
            >
                <Spinner v-if="processing" />
                ログイン
            </Button>
        </div>
    </Form>

    <!-- デモ用アカウント（docs/spec.md §5・§9。パスワードはデモ専用の値） -->
    <section
        class="rounded-lg border p-4 text-sm"
        aria-labelledby="demo-accounts-heading"
        data-test="demo-accounts"
    >
        <h2 id="demo-accounts-heading" class="font-medium">デモ用アカウント</h2>
        <p class="mt-1 text-muted-foreground">
            クリックするとメールアドレスとパスワードを入力します（パスワードはすべて
            <code class="font-mono">{{ demoPassword }}</code
            >）。
        </p>
        <ul class="mt-3 grid gap-2">
            <li v-for="account in demoAccounts" :key="account.email">
                <Button
                    type="button"
                    variant="outline"
                    class="h-auto w-full justify-between gap-2 py-2"
                    :data-test="`demo-account-${account.role}`"
                    @click="fillDemoAccount(account, demoPassword)"
                >
                    <span class="font-mono text-xs">{{ account.role }}</span>
                    <span class="truncate text-xs text-muted-foreground">{{
                        account.email
                    }}</span>
                </Button>
            </li>
        </ul>
    </section>
</template>
