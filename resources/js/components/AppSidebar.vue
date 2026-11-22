<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import {
    Activity,
    Bell,
    Globe,
    KeyRound,
    LayoutGrid,
    Settings,
    Siren,
    Users,
    Wrench,
} from '@lucide/vue';
import { computed } from 'vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import OrganizationSwitcher from '@/components/OrganizationSwitcher.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
} from '@/components/ui/sidebar';
import { extensionIcon } from '@/lib/extension-icons';
import { useTranslations } from '@/lib/i18n';
import { dashboard, edit } from '@/routes/organizations';
import { index as apiTokens } from '@/routes/organizations/api-tokens';
import { index as incidents } from '@/routes/organizations/incidents';
import { index as maintenance } from '@/routes/organizations/maintenance';
import { index as members } from '@/routes/organizations/members';
import { index as monitors } from '@/routes/organizations/monitors';
import { index as notificationChannels } from '@/routes/organizations/notification-channels';
import { index as statusPages } from '@/routes/organizations/status-pages';
import type { NavItem } from '@/types';

const page = usePage();
const { t } = useTranslations();

const mainNavItems = computed<NavItem[]>(() => {
    const organization = page.props.currentOrganization;

    if (!organization) {
        return [];
    }

    return [
        {
            id: 'dashboard',
            title: t('Dashboard'),
            href: dashboard(organization.slug),
            icon: LayoutGrid,
        },
        {
            id: 'monitors',
            title: t('Monitors'),
            href: monitors(organization.slug),
            icon: Activity,
        },
        {
            id: 'incidents',
            title: t('Incidents'),
            href: incidents(organization.slug),
            icon: Siren,
            badge: organization.openIncidents,
        },
        {
            id: 'maintenance',
            title: t('Maintenance'),
            href: maintenance(organization.slug),
            icon: Wrench,
        },
        {
            id: 'status-pages',
            title: t('Status pages'),
            href: statusPages(organization.slug),
            icon: Globe,
        },
        {
            id: 'notifications',
            title: t('Notifications'),
            href: notificationChannels(organization.slug),
            icon: Bell,
        },
        {
            id: 'members',
            title: t('Members'),
            href: members(organization.slug),
            icon: Users,
        },
        ...(organization.can.manageApiTokens
            ? [
                  {
                      id: 'api-tokens',
                      title: t('API tokens'),
                      href: apiTokens(organization.slug),
                      icon: KeyRound,
                  },
              ]
            : []),
        // Already translated by the server; shown before the organization settings.
        ...(organization.navigation ?? []).map((item): NavItem => ({
            id: item.id,
            title: item.title,
            href: item.href,
            icon: extensionIcon(item.icon),
        })),
        {
            id: 'settings',
            title: t('Settings'),
            href: edit(organization.slug),
            icon: Settings,
        },
    ];
});
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <OrganizationSwitcher />
        </SidebarHeader>

        <SidebarContent>
            <NavMain v-if="mainNavItems.length" :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
