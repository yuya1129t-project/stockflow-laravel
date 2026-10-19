<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { LogOut } from '@lucide/vue';
import { computed } from 'vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { logout } from '@/routes';
import type { BreadcrumbItem } from '@/types';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

const page = usePage();
const user = computed(() => page.props.auth.user);

const handleLogout = () => {
    router.flushAll();
};
</script>

<template>
    <header
        class="flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/70 px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4"
    >
        <div class="flex min-w-0 items-center gap-2">
            <SidebarTrigger class="-ml-1" />
            <template v-if="breadcrumbs && breadcrumbs.length > 0">
                <Breadcrumbs :breadcrumbs="breadcrumbs" />
            </template>
        </div>

        <!-- ログイン中のユーザー名・ロールとログアウト -->
        <div v-if="user" class="ml-auto flex items-center gap-2 text-sm">
            <span class="hidden truncate font-medium sm:inline">{{
                user.name
            }}</span>
            <Badge variant="secondary" data-test="header-role">{{
                user.roleLabel
            }}</Badge>
            <Button variant="ghost" size="sm" as-child>
                <Link
                    :href="logout()"
                    as="button"
                    @click="handleLogout"
                    data-test="header-logout-button"
                >
                    <LogOut class="h-4 w-4" />
                    <span class="hidden sm:inline">ログアウト</span>
                </Link>
            </Button>
        </div>
    </header>
</template>
