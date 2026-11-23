<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Menu } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useTranslations } from '@/lib/i18n';

/**
 * A public site link: an in-page anchor, an Inertia page or an external URL.
 */
export type SiteNavItem = {
    label: string;
    href: string;
    kind: 'anchor' | 'page' | 'external';
};

defineProps<{
    items: SiteNavItem[];
}>();

const { t } = useTranslations();
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button
                variant="ghost"
                size="icon"
                :aria-label="t('Open menu')"
                data-test="site-nav-menu"
            >
                <Menu class="size-4" />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-56">
            <DropdownMenuItem v-for="item in items" :key="item.href" as-child>
                <Link v-if="item.kind === 'page'" :href="item.href">{{
                    item.label
                }}</Link>
                <a
                    v-else-if="item.kind === 'external'"
                    :href="item.href"
                    target="_blank"
                    rel="noopener noreferrer"
                    >{{ item.label }}</a
                >
                <a v-else :href="item.href">{{ item.label }}</a>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
