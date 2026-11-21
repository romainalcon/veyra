<script setup lang="ts">
import { Head, Link, setLayoutProps, usePage } from '@inertiajs/vue3';
import { CheckCircle2 } from '@lucide/vue';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useOrganizationRealtime } from '@/composables/useOrganizationRealtime';
import { formatDateTime, formatElapsed } from '@/lib/monitoring';
import { index, show } from '@/routes/organizations/incidents';
import type { CurrentOrganization, IncidentSummary, Paginated } from '@/types';
import { useTranslations } from '@/lib/i18n';

const { t } = useTranslations();

defineProps<{
    incidents: Paginated<IncidentSummary>;
    filter: 'all' | 'open' | 'resolved';
}>();

const page = usePage();
const organization = computed(
    () => page.props.currentOrganization as CurrentOrganization,
);

const filters = [
    { value: 'all', label: t('All') },
    { value: 'open', label: t('Open') },
    { value: 'resolved', label: t('Resolved') },
] as const;

const paginationLabel = (label: string) =>
    label.replace('&laquo;', '«').replace('&raquo;', '»');

useOrganizationRealtime(organization.value.id, {
    only: ['incidents', 'currentOrganization'],
});

setLayoutProps({
    breadcrumbs: [
        { title: t('Incidents'), href: index(organization.value.slug) },
    ],
});
</script>

<template>
    <Head :title="t('Incidents')" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">
                    {{ t('Incidents') }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{
                        t(
                            'Opened after 3 consecutive failed checks, resolved after 2 successes.',
                        )
                    }}
                </p>
            </div>
            <nav class="flex gap-1" :aria-label="t('Filter incidents')">
                <Button
                    v-for="option in filters"
                    :key="option.value"
                    :variant="filter === option.value ? 'default' : 'ghost'"
                    size="sm"
                    as-child
                >
                    <Link
                        :href="
                            index(organization.slug, {
                                query: { status: option.value },
                            })
                        "
                        preserve-scroll
                        >{{ option.label }}</Link
                    >
                </Button>
            </nav>
        </div>

        <div
            v-if="incidents.data.length === 0"
            class="flex flex-1 flex-col items-center justify-center rounded-xl border border-dashed p-10 text-center"
            data-test="incidents-empty-state"
        >
            <CheckCircle2 class="mb-3 size-8 text-emerald-600" />
            <h2 class="text-lg font-semibold">{{ t('No incidents') }}</h2>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ t('Nothing to report for this filter.') }}
            </p>
        </div>

        <ul
            v-else
            class="divide-y rounded-lg border"
            data-test="incidents-list"
        >
            <li v-for="incident in incidents.data" :key="incident.id">
                <Link
                    :href="show([organization.slug, incident.id])"
                    class="flex flex-col gap-2 p-4 hover:bg-muted/50 sm:flex-row sm:items-center"
                >
                    <div class="min-w-0 flex-1">
                        <p class="flex items-center gap-2 font-medium">
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
                            <span class="truncate">{{
                                incident.monitor?.name ?? t('Deleted monitor')
                            }}</span>
                        </p>
                        <p class="truncate text-sm text-muted-foreground">
                            {{ incident.cause }}
                        </p>
                    </div>
                    <div
                        class="flex flex-wrap items-center gap-3 text-sm text-muted-foreground"
                    >
                        <span>{{ formatDateTime(incident.started_at) }}</span>
                        <span>{{
                            formatElapsed(incident.duration_seconds)
                        }}</span>
                        <Badge
                            v-if="incident.acknowledged_at"
                            variant="outline"
                            >{{ t('Acknowledged') }}</Badge
                        >
                    </div>
                </Link>
            </li>
        </ul>

        <nav
            v-if="incidents.last_page > 1"
            class="flex flex-wrap gap-1"
            :aria-label="t('Pagination')"
        >
            <template v-for="link in incidents.links" :key="link.label">
                <Button
                    v-if="link.url"
                    :variant="link.active ? 'default' : 'ghost'"
                    size="sm"
                    as-child
                >
                    <Link :href="link.url" preserve-scroll>{{
                        paginationLabel(link.label)
                    }}</Link>
                </Button>
            </template>
        </nav>
    </div>
</template>
