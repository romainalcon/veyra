<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { LogOut, Settings } from '@lucide/vue';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import UserInfo from '@/components/UserInfo.vue';
import { extensionIcon } from '@/lib/extension-icons';
import { useTranslations } from '@/lib/i18n';
import { logout } from '@/routes';
import { edit } from '@/routes/profile';
import type { ExtensionNavItem, User } from '@/types';

type Props = {
    user: User;
    /** Entries contributed by optional extensions, already translated and authorized by the server. */
    navigation?: ExtensionNavItem[];
};

const handleLogout = () => {
    router.flushAll();
};

withDefaults(defineProps<Props>(), {
    navigation: () => [],
});

const { t } = useTranslations();
</script>

<template>
    <DropdownMenuLabel class="p-0 font-normal">
        <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
            <UserInfo :user="user" :show-email="true" />
        </div>
    </DropdownMenuLabel>
    <DropdownMenuSeparator />
    <DropdownMenuGroup>
        <DropdownMenuItem :as-child="true">
            <Link class="block w-full cursor-pointer" :href="edit()" prefetch>
                <Settings class="mr-2 h-4 w-4" />
                {{ t('Settings') }}
            </Link>
        </DropdownMenuItem>
    </DropdownMenuGroup>
    <template v-if="navigation.length">
        <DropdownMenuSeparator />
        <DropdownMenuGroup data-test="user-menu-extensions">
            <DropdownMenuItem
                v-for="item in navigation"
                :key="item.id"
                :as-child="true"
            >
                <Link class="block w-full cursor-pointer" :href="item.href">
                    <component
                        :is="extensionIcon(item.icon)"
                        class="mr-2 h-4 w-4"
                    />
                    {{ item.title }}
                </Link>
            </DropdownMenuItem>
        </DropdownMenuGroup>
    </template>
    <DropdownMenuSeparator />
    <DropdownMenuItem :as-child="true">
        <Link
            class="block w-full cursor-pointer"
            :href="logout()"
            @click="handleLogout"
            as="button"
            data-test="logout-button"
        >
            <LogOut class="mr-2 h-4 w-4" />
            {{ t('Log out') }}
        </Link>
    </DropdownMenuItem>
</template>
