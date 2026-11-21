<script setup lang="ts">
import { Head, Link, router, setLayoutProps, usePage } from '@inertiajs/vue3';
import { Pause, Pencil, Play, RefreshCw, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import CertificatePanel from '@/components/monitors/CertificatePanel.vue';
import CheckBadge from '@/components/monitors/CheckBadge.vue';
import HeartbeatPanel from '@/components/monitors/HeartbeatPanel.vue';
import MonitorStatsPanel from '@/components/monitors/MonitorStatsPanel.vue';
import StatusBadge from '@/components/monitors/StatusBadge.vue';
import { useOrganizationRealtime } from '@/composables/useOrganizationRealtime';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import {
    formatDateTime,
    formatDuration,
    formatElapsed,
    formatInterval,
} from '@/lib/monitoring';
import { show as showIncident } from '@/routes/organizations/incidents';
import {
    destroy,
    edit,
    enabled,
    index,
    show,
} from '@/routes/organizations/monitors';
import { store as checkNow } from '@/routes/organizations/monitors/checks';
import type {
    CurrentOrganization,
    CursorPaginated,
    IncidentSummary,
    MonitorCheck,
    MonitorDetail,
    MonitorMaintenance,
    MonitorStats,
    StatsRange,
} from '@/types';
import { useTranslations } from '@/lib/i18n';

const { t } = useTranslations();

const props = defineProps<{
    monitor: MonitorDetail;
    checks: CursorPaginated<MonitorCheck>;
    incidents: IncidentSummary[];
    maintenance: MonitorMaintenance | null;
    stats?: MonitorStats;
    range: StatsRange;
    can: { update: boolean; delete: boolean; checkNow: boolean };
}>();

const page = usePage();
const organization = computed(
    () => page.props.currentOrganization as CurrentOrganization,
);
const params = computed(
    () => [organization.value.slug, props.monitor.id] as [string, string],
);

setLayoutProps({
    breadcrumbs: [
        { title: t('Monitors'), href: index(organization.value.slug) },
        { title: props.monitor.name, href: show(params.value) },
    ],
});

/** Also shown when an http:// URL redirects to HTTPS on the same host. */
const showCertificate = computed(
    () =>
        props.monitor.type === 'http' &&
        (props.monitor.certificate !== null ||
            (props.monitor.url ?? '').toLowerCase().startsWith('https://')),
);

const isFirstPage = computed(() => props.checks.prev_cursor === null);
const activeIncident = computed(
    () =>
        props.incidents.find((incident) => incident.status === 'open') ?? null,
);

useOrganizationRealtime(organization.value.id, {
    only: ['monitor', 'checks', 'incidents'],
    filter: (payload) => payload.monitor_id === props.monitor.id,
});

const rangeHref = (range: StatsRange) =>
    show.url(params.value, { query: { range } });

const toggle = () =>
    router.visit(enabled(params.value), {
        data: { enabled: !props.monitor.enabled },
        preserveScroll: true,
    });

const runCheck = () =>
    router.visit(checkNow(params.value), { preserveScroll: true });

const remove = () => router.visit(destroy(params.value));
</script>

<template>
    <Head :title="monitor.name" />

    <div class="mx-auto w-full max-w-5xl space-y-6 px-4 py-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start">
            <div class="min-w-0 flex-1 space-y-1">
                <h1
                    class="flex items-center gap-2 text-xl font-semibold tracking-tight"
                >
                    {{ monitor.name }}
                    <StatusBadge :status="monitor.state.status" />
                    <Badge v-if="!monitor.enabled" variant="secondary">{{
                        t('Paused')
                    }}</Badge>
                </h1>
                <p class="truncate text-sm text-muted-foreground">
                    {{ monitor.target }}
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button
                    v-if="can.checkNow && monitor.enabled"
                    variant="outline"
                    size="sm"
                    data-test="check-now-button"
                    @click="runCheck"
                >
                    <RefreshCw class="size-4" />
                    {{ t('Check now') }}
                </Button>
                <Button
                    v-if="can.update"
                    variant="outline"
                    size="sm"
                    data-test="toggle-monitor-button"
                    @click="toggle"
                >
                    <component
                        :is="monitor.enabled ? Pause : Play"
                        class="size-4"
                    />
                    {{ monitor.enabled ? t('Pause') : t('Resume') }}
                </Button>
                <Button v-if="can.update" variant="outline" size="sm" as-child>
                    <Link :href="edit(params)">
                        <Pencil class="size-4" />
                        {{ t('Edit') }}
                    </Link>
                </Button>
                <Button
                    v-if="can.delete"
                    variant="ghost"
                    size="sm"
                    data-test="delete-monitor-button"
                    @click="remove"
                >
                    <Trash2 class="size-4" />
                    {{ t('Delete') }}
                </Button>
            </div>
        </div>

        <InputError :message="page.props.errors?.monitor" />

        <Link
            v-if="activeIncident"
            :href="showIncident([organization.slug, activeIncident.id])"
            class="block rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700 hover:bg-red-100 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-300"
            data-test="active-incident-banner"
        >
            <span class="font-medium">{{ t('Ongoing incident') }}</span>
            {{
                t('since :date (:elapsed)', {
                    date: formatDateTime(activeIncident.started_at),
                    elapsed: formatElapsed(activeIncident.duration_seconds),
                })
            }}<template v-if="activeIncident.cause">
                — {{ activeIncident.cause }}</template
            >
        </Link>

        <div
            v-if="maintenance"
            class="rounded-lg border border-sky-200 bg-sky-50 p-4 text-sm text-sky-800 dark:border-sky-900/50 dark:bg-sky-950/40 dark:text-sky-300"
            data-test="maintenance-banner"
        >
            <span class="font-medium">{{
                maintenance.active
                    ? t('Under maintenance')
                    : t('Maintenance planned')
            }}</span>
            — {{ maintenance.title }}:
            <template v-if="!maintenance.active"
                >{{ formatDateTime(maintenance.starts_at) }} →
            </template>
            {{
                t(
                    'until :date. Checks keep running but open no incident and do not count towards uptime.',
                    { date: formatDateTime(maintenance.ends_at) },
                )
            }}
        </div>

        <dl class="grid gap-4 rounded-lg border p-4 text-sm sm:grid-cols-4">
            <div>
                <dt class="text-muted-foreground">
                    {{ t('Latest raw check') }}
                </dt>
                <dd class="mt-1">
                    <CheckBadge :check="monitor.latest_check" />
                </dd>
            </div>
            <div>
                <template v-if="monitor.heartbeat">
                    <dt class="text-muted-foreground">
                        {{ t('Period / grace') }}
                    </dt>
                    <dd class="mt-1">
                        {{ formatInterval(monitor.interval_seconds) }} /
                        {{ formatInterval(monitor.heartbeat.grace_seconds) }}
                    </dd>
                </template>
                <template v-else>
                    <dt class="text-muted-foreground">
                        {{ t('Interval / timeout') }}
                    </dt>
                    <dd class="mt-1">
                        {{ formatInterval(monitor.interval_seconds) }} /
                        {{ monitor.timeout_seconds }} s
                    </dd>
                </template>
            </div>
            <div v-if="monitor.type === 'http'">
                <dt class="text-muted-foreground">
                    {{ t('Expected statuses') }}
                </dt>
                <dd class="mt-1">{{ monitor.expected_statuses }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">
                    {{
                        monitor.heartbeat
                            ? t('Ping expected by')
                            : t('Next check')
                    }}
                </dt>
                <dd class="mt-1">
                    {{
                        monitor.enabled
                            ? formatDateTime(monitor.next_check_at)
                            : t('Paused')
                    }}
                </dd>
            </div>
            <div v-if="monitor.type === 'tcp'">
                <dt class="text-muted-foreground">{{ t('Check') }}</dt>
                <dd class="mt-1">{{ t('TCP connection') }}</dd>
            </div>
            <div v-if="monitor.header_names.length" class="sm:col-span-4">
                <dt class="text-muted-foreground">
                    {{ t('Custom headers') }}
                </dt>
                <dd class="mt-1">{{ monitor.header_names.join(', ') }}</dd>
            </div>
        </dl>

        <HeartbeatPanel
            :monitor="monitor"
            :organization-slug="organization.slug"
            :can-update="can.update"
        />

        <CertificatePanel
            v-if="showCertificate"
            :certificate="monitor.certificate"
        />

        <Deferred data="stats">
            <template #fallback>
                <div class="grid gap-4 sm:grid-cols-3">
                    <Skeleton v-for="n in 3" :key="n" class="h-24 rounded-lg" />
                </div>
                <Skeleton class="h-64 rounded-lg" />
            </template>
            <MonitorStatsPanel
                v-if="stats"
                :stats="stats"
                :range="range"
                :range-href="rangeHref"
            />
        </Deferred>

        <section v-if="incidents.length" class="space-y-3">
            <h2 class="text-base font-medium">
                {{ t('Recent incidents') }}
            </h2>
            <ul class="divide-y rounded-lg border text-sm">
                <li v-for="incident in incidents" :key="incident.id">
                    <Link
                        :href="showIncident([organization.slug, incident.id])"
                        class="flex flex-wrap items-center gap-3 px-4 py-2 hover:bg-muted/50"
                    >
                        <Badge
                            :variant="
                                incident.status === 'open'
                                    ? 'destructive'
                                    : 'secondary'
                            "
                        >
                            {{
                                incident.status === 'open'
                                    ? t('Open')
                                    : t('Resolved')
                            }}
                        </Badge>
                        <span>{{ formatDateTime(incident.started_at) }}</span>
                        <span class="text-muted-foreground">
                            {{ formatElapsed(incident.duration_seconds) }}
                        </span>
                        <span class="truncate text-muted-foreground">{{
                            incident.cause
                        }}</span>
                    </Link>
                </li>
            </ul>
        </section>

        <section class="space-y-3">
            <h2 class="text-base font-medium">{{ t('Check history') }}</h2>
            <p
                v-if="checks.data.length === 0"
                class="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground"
                data-test="checks-empty-state"
            >
                {{
                    t(
                        'No checks recorded yet. The first one runs within a few seconds when workers are online.',
                    )
                }}
            </p>
            <div v-else class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm" data-test="checks-table">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2 font-medium">
                                {{ t('Checked at') }}
                            </th>
                            <th class="px-4 py-2 font-medium">
                                {{ t('Result') }}
                            </th>
                            <th class="px-4 py-2 font-medium">
                                {{ t('Duration') }}
                            </th>
                            <th
                                v-if="monitor.type === 'http'"
                                class="px-4 py-2 font-medium"
                            >
                                {{ t('Redirects') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="check in checks.data" :key="check.id">
                            <td class="px-4 py-2 whitespace-nowrap">
                                {{ formatDateTime(check.checked_at) }}
                            </td>
                            <td class="px-4 py-2">
                                <span class="flex items-center gap-2">
                                    <CheckBadge :check="check" />
                                    <Badge
                                        v-if="check.in_maintenance"
                                        variant="outline"
                                        data-test="check-maintenance"
                                        >{{ t('Maintenance') }}</Badge
                                    >
                                </span>
                            </td>
                            <td class="px-4 py-2">
                                {{ formatDuration(check.duration_ms) }}
                            </td>
                            <td
                                v-if="monitor.type === 'http'"
                                class="px-4 py-2"
                            >
                                {{ check.redirects }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="flex justify-between">
                <Button
                    v-if="checks.prev_page_url"
                    variant="ghost"
                    size="sm"
                    as-child
                >
                    <Link :href="checks.prev_page_url" preserve-scroll>{{
                        t('Newer')
                    }}</Link>
                </Button>
                <span v-else-if="!isFirstPage" />
                <Button
                    v-if="checks.next_page_url"
                    variant="ghost"
                    size="sm"
                    class="ml-auto"
                    as-child
                >
                    <Link :href="checks.next_page_url" preserve-scroll>{{
                        t('Older')
                    }}</Link>
                </Button>
            </div>
        </section>
    </div>
</template>
