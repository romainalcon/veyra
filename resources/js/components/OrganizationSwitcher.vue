<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Building2, Check, ChevronsUpDown, Plus } from '@lucide/vue';
import { computed } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useTranslations } from '@/lib/i18n';
import { create, dashboard } from '@/routes/organizations';
import type { OrganizationRole } from '@/types';

const page = usePage();
const { isMobile } = useSidebar();
const { t } = useTranslations();

const currentOrganization = computed(() => page.props.currentOrganization);
const organizations = computed(() => page.props.organizations ?? []);

const roleLabels: Record<OrganizationRole, string> = {
    owner: 'Owner',
    admin: 'Admin',
    member: 'Member',
    viewer: 'Viewer',
};
</script>

<template>
    <SidebarMenu>
        <SidebarMenuItem>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <SidebarMenuButton
                        size="lg"
                        class="data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground"
                        data-test="organization-switcher-trigger"
                    >
                        <div
                            class="flex aspect-square size-8 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground"
                        >
                            <Building2 class="size-4" />
                        </div>
                        <div
                            class="grid flex-1 text-left text-sm leading-tight"
                        >
                            <span class="truncate font-semibold">
                                {{
                                    currentOrganization?.name ??
                                    t('Select organization')
                                }}
                            </span>
                            <span
                                v-if="currentOrganization"
                                class="truncate text-xs text-muted-foreground"
                            >
                                {{ t(roleLabels[currentOrganization.role]) }}
                            </span>
                        </div>
                        <ChevronsUpDown class="ml-auto size-4" />
                    </SidebarMenuButton>
                </DropdownMenuTrigger>

                <DropdownMenuContent
                    class="w-(--reka-dropdown-menu-trigger-width) min-w-56 rounded-lg"
                    :side="isMobile ? 'bottom' : 'right'"
                    align="start"
                    :side-offset="4"
                >
                    <DropdownMenuLabel class="text-xs text-muted-foreground">
                        {{ t('Organizations') }}
                    </DropdownMenuLabel>
                    <DropdownMenuItem
                        v-for="organization in organizations"
                        :key="organization.id"
                        as-child
                        data-test="organization-switcher-item"
                    >
                        <Link
                            :href="dashboard(organization.slug)"
                            class="flex w-full cursor-pointer items-center gap-2 p-2"
                        >
                            <span class="truncate">{{
                                organization.name
                            }}</span>
                            <Check
                                v-if="
                                    currentOrganization?.id === organization.id
                                "
                                class="ml-auto size-4"
                            />
                        </Link>
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem as-child>
                        <Link
                            :href="create()"
                            class="flex w-full cursor-pointer items-center gap-2 p-2"
                        >
                            <Plus class="size-4" />
                            <span class="text-muted-foreground">{{
                                t('New organization')
                            }}</span>
                        </Link>
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </SidebarMenuItem>
    </SidebarMenu>
</template>
