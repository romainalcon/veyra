<script setup lang="ts">
import { Head, Link, setLayoutProps, usePage } from '@inertiajs/vue3';
import { Activity } from '@lucide/vue';
import { computed } from 'vue';
import StatusBadge from '@/components/monitors/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { useOrganizationRealtime } from '@/composables/useOrganizationRealtime';
import { formatDateTime, formatElapsed } from '@/lib/monitoring';
import { dashboard } from '@/routes/organizations';
import {
    index as incidentsIndex,
    show as showIncident,
} from '@/routes/organizations/incidents';
import { create, index } from '@/routes/organizations/monitors';
import type {
    CurrentOrganization,
    IncidentSummary,
    MonitorStatus,
} from '@/types';

defineProps<{
    monitorCounts: {
        total: number;
        paused: number;
        byStatus: Record<MonitorStatus, number>;
    };
    openIncidents: IncidentSummary[];
}>();

const page = usePage();
const organization = computed(
    () => page.props.currentOrganization as CurrentOrganization,
);

const statuses: MonitorStatus[] = ['up', 'degraded', 'down', 'unknown'];

useOrganizationRealtime(organization.value.id, {
    only: ['monitorCounts', 'openIncidents', 'currentOrganization'],
});

setLayoutProps({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard(organization.value.slug) },
    ],
});
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <template v-if="monitorCounts.total > 0">
            <div
                class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5"
                data-test="dashboard-monitor-counts"
            >
                <Link
                    v-for="status in statuses"
                    :key="status"
                    :href="index(organization.slug)"
                    class="rounded-xl border p-4 hover:bg-muted/50"
                >
                    <StatusBadge :status="status" />
                    <p class="mt-3 text-2xl font-semibold">
                        {{ monitorCounts.byStatus[status] }}
                    </p>
                </Link>
                <div class="rounded-xl border p-4">
                    <p class="text-sm text-muted-foreground">Paused</p>
                    <p class="mt-3 text-2xl font-semibold">
                        {{ monitorCounts.paused }}
                    </p>
                </div>
            </div>

            <section class="space-y-3">
                <div class="flex items-center justify-between">
                    <h2 class="text-base font-medium">Open incidents</h2>
                    <Link
                        :href="incidentsIndex(organization.slug)"
                        class="text-sm text-muted-foreground hover:underline"
                        >All incidents</Link
                    >
                </div>
                <p
                    v-if="openIncidents.length === 0"
                    class="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground"
                    data-test="no-open-incidents"
                >
                    No open incidents. All confirmed failures are resolved.
                </p>
                <ul v-else class="divide-y rounded-lg border text-sm">
                    <li v-for="incident in openIncidents" :key="incident.id">
                        <Link
                            :href="
                                showIncident([organization.slug, incident.id])
                            "
                            class="flex flex-wrap items-center gap-3 px-4 py-3 hover:bg-muted/50"
                        >
                            <span class="font-medium">{{
                                incident.monitor?.name ?? 'Deleted monitor'
                            }}</span>
                            <span class="text-muted-foreground">
                                since
                                {{ formatDateTime(incident.started_at) }} ({{
                                    formatElapsed(incident.duration_seconds)
                                }})
                            </span>
                            <span
                                v-if="!incident.acknowledged_at"
                                class="text-xs font-medium text-red-600 dark:text-red-400"
                                >Not acknowledged</span
                            >
                        </Link>
                    </li>
                </ul>
            </section>
        </template>
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
