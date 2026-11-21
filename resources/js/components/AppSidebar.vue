<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import {
    Activity,
    Bell,
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
import { dashboard, edit } from '@/routes/organizations';
import { index as incidents } from '@/routes/organizations/incidents';
import { index as maintenance } from '@/routes/organizations/maintenance';
import { index as members } from '@/routes/organizations/members';
import { index as monitors } from '@/routes/organizations/monitors';
import { index as notificationChannels } from '@/routes/organizations/notification-channels';
import type { NavItem } from '@/types';

const page = usePage();

const mainNavItems = computed<NavItem[]>(() => {
    const organization = page.props.currentOrganization;

    if (!organization) {
        return [];
    }

    return [
        {
            title: 'Dashboard',
            href: dashboard(organization.slug),
            icon: LayoutGrid,
        },
        {
            title: 'Monitors',
            href: monitors(organization.slug),
            icon: Activity,
        },
        {
            title: 'Incidents',
            href: incidents(organization.slug),
            icon: Siren,
            badge: organization.openIncidents,
        },
        {
            title: 'Maintenance',
            href: maintenance(organization.slug),
            icon: Wrench,
        },
        {
            title: 'Notifications',
            href: notificationChannels(organization.slug),
            icon: Bell,
        },
        {
            title: 'Members',
            href: members(organization.slug),
            icon: Users,
        },
        {
            title: 'Settings',
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
