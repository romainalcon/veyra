<script setup lang="ts">
import { Head, Link, setLayoutProps, usePage } from '@inertiajs/vue3';
import { Activity } from '@lucide/vue';
import { computed } from 'vue';
import { useOrganizationRealtime } from '@/composables/useOrganizationRealtime';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes/organizations';
import { create, index } from '@/routes/organizations/monitors';
import type { CurrentOrganization } from '@/types';

defineProps<{
    monitorCounts: { total: number; paused: number };
}>();

const page = usePage();
const organization = computed(
    () => page.props.currentOrganization as CurrentOrganization,
);

useOrganizationRealtime(organization.value.id);

setLayoutProps({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard(organization.value.slug) },
    ],
});
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <div
            v-if="monitorCounts.total > 0"
            class="grid gap-4 sm:grid-cols-2"
            data-test="dashboard-monitor-counts"
        >
            <Link
                :href="index(organization.slug)"
                class="rounded-xl border p-4 hover:bg-muted/50"
            >
                <p class="text-sm text-muted-foreground">Monitors</p>
                <p class="mt-1 text-2xl font-semibold">
                    {{ monitorCounts.total }}
                </p>
            </Link>
            <div class="rounded-xl border p-4">
                <p class="text-sm text-muted-foreground">Paused</p>
                <p class="mt-1 text-2xl font-semibold">
                    {{ monitorCounts.paused }}
                </p>
            </div>
            <p class="text-sm text-muted-foreground sm:col-span-2">
                Confirmed up/down states, incidents and uptime charts arrive in
                the next releases.
            </p>
        </div>
        <div
            v-else
            class="flex flex-1 flex-col items-center justify-center rounded-xl border border-dashed border-sidebar-border/70 p-10 text-center dark:border-sidebar-border"
            data-test="dashboard-empty-state"
        >
            <div
                class="mb-4 flex size-12 items-center justify-center rounded-full bg-muted"
            >
                <Activity class="size-6 text-muted-foreground" />
            </div>
            <h2 class="text-lg font-semibold">No monitors yet</h2>
            <p class="mt-1 max-w-sm text-sm text-muted-foreground">
                Start watching your first HTTP endpoint for
                {{ organization.name }}.
            </p>
            <Button
                v-if="organization.can.update || organization.role === 'member'"
                class="mt-4"
                as-child
            >
                <Link :href="create(organization.slug)">Create a monitor</Link>
            </Button>
        </div>
    </div>
</template>
