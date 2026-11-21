<script setup lang="ts">
import { Deferred, Head, Link, setLayoutProps, usePage } from '@inertiajs/vue3';
import { Activity } from '@lucide/vue';
import { computed } from 'vue';
import IncidentsChart from '@/components/charts/IncidentsChart.vue';
import StatusBadge from '@/components/monitors/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { coverageNote, formatUptime } from '@/lib/analytics';
import { useOrganizationRealtime } from '@/composables/useOrganizationRealtime';
import { formatDateTime, formatElapsed } from '@/lib/monitoring';
import { dashboard } from '@/routes/organizations';
import {
    index as incidentsIndex,
    show as showIncident,
} from '@/routes/organizations/incidents';
import {
    create,
    index,
    show as showMonitor,
} from '@/routes/organizations/monitors';
import type {
    CurrentOrganization,
    IncidentSummary,
    MonitorStatus,
    OrganizationAnalytics,
} from '@/types';

defineProps<{
    monitorCounts: {
        total: number;
        paused: number;
        byStatus: Record<MonitorStatus, number>;
    };
    openIncidents: IncidentSummary[];
    analytics?: OrganizationAnalytics;
}>();

const periods = ['24h', '7d', '30d'] as const;

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

            <Deferred data="analytics">
                <template #fallback>
                    <Skeleton class="h-64 rounded-xl" />
                </template>
                <template v-if="analytics">
                    <section
                        class="grid gap-4 sm:grid-cols-3"
                        data-test="dashboard-uptime"
                    >
                        <div
                            v-for="period in periods"
                            :key="period"
                            class="rounded-xl border p-4"
                        >
                            <p class="text-sm text-muted-foreground">
                                Overall uptime {{ period }}
                            </p>
                            <p class="mt-1 text-2xl font-semibold">
                                {{
                                    formatUptime(
                                        analytics.uptime[period].uptime,
                                    )
                                }}
                            </p>
                            <p
                                v-if="coverageNote(analytics.uptime[period])"
                                class="mt-1 text-xs text-muted-foreground"
                            >
                                {{ coverageNote(analytics.uptime[period]) }}
                            </p>
                        </div>
                    </section>

                    <section class="space-y-3 rounded-xl border p-4">
                        <div class="flex items-center justify-between">
                            <h2 class="text-sm font-medium">
                                Incidents (30 days)
                            </h2>
                            <span
                                v-if="analytics.incidents.mttr_minutes !== null"
                                class="text-xs text-muted-foreground"
                                >Mean time to recovery:
                                {{ analytics.incidents.mttr_minutes }} min</span
                            >
                        </div>
                        <IncidentsChart :days="analytics.incidents.days" />
                    </section>

                    <section class="space-y-3">
                        <h2 class="text-base font-medium">Monitors</h2>
                        <div class="overflow-x-auto rounded-lg border">
                            <table
                                class="w-full text-sm"
                                data-test="dashboard-monitors"
                            >
                                <thead
                                    class="bg-muted/50 text-left text-muted-foreground"
                                >
                                    <tr>
                                        <th class="px-4 py-2 font-medium">
                                            Monitor
                                        </th>
                                        <th class="px-4 py-2 font-medium">
                                            State
                                        </th>
                                        <th class="px-4 py-2 font-medium">
                                            Uptime 7d
                                        </th>
                                        <th class="px-4 py-2 font-medium">
                                            Avg latency 24h
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y">
                                    <tr
                                        v-for="row in analytics.monitors"
                                        :key="row.id"
                                    >
                                        <td class="px-4 py-2">
                                            <Link
                                                :href="
                                                    showMonitor([
                                                        organization.slug,
                                                        row.id,
                                                    ])
                                                "
                                                class="hover:underline"
                                                >{{ row.name }}</Link
                                            >
                                        </td>
                                        <td class="px-4 py-2">
                                            <StatusBadge :status="row.status" />
                                        </td>
                                        <td class="px-4 py-2">
                                            {{ formatUptime(row.uptime_7d) }}
                                            <span
                                                v-if="row.partial"
                                                class="text-xs text-muted-foreground"
                                                >(partial)</span
                                            >
                                        </td>
                                        <td class="px-4 py-2">
                                            {{
                                                row.latency_24h === null
                                                    ? '—'
                                                    : `${row.latency_24h} ms`
                                            }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </template>
            </Deferred>
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
