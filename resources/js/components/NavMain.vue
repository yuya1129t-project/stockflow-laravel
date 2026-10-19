<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import type { NavItem } from '@/types';

defineProps<{
    items: NavItem[];
}>();

const { isCurrentUrl } = useCurrentUrl();
</script>

<template>
    <SidebarGroup class="px-2 py-0">
        <SidebarGroupLabel>メニュー</SidebarGroupLabel>
        <SidebarMenu>
            <SidebarMenuItem v-for="item in items" :key="item.title">
                <SidebarMenuButton
                    v-if="item.ready !== false"
                    as-child
                    :is-active="isCurrentUrl(item.href)"
                    :tooltip="item.title"
                >
                    <Link :href="item.href">
                        <component :is="item.icon" />
                        <span>{{ item.title }}</span>
                    </Link>
                </SidebarMenuButton>
                <!-- 未実装の画面はリンクにしない -->
                <template v-else>
                    <SidebarMenuButton
                        aria-disabled="true"
                        class="cursor-default text-muted-foreground hover:bg-transparent hover:text-muted-foreground active:bg-transparent"
                        :tooltip="`${item.title}（準備中）`"
                        data-test="nav-coming-soon"
                    >
                        <component :is="item.icon" />
                        <span>{{ item.title }}</span>
                    </SidebarMenuButton>
                    <SidebarMenuBadge class="text-muted-foreground">
                        準備中
                    </SidebarMenuBadge>
                </template>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
