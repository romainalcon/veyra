<script setup lang="ts">
import EmptyState from '@/components/EmptyState.vue';
import { Head, Link, setLayoutProps, usePage } from '@inertiajs/vue3';
import { Activity, Download, Plus, Upload } from '@lucide/vue';
import { computed } from 'vue';
import LimitNotice from '@/components/LimitNotice.vue';
import CheckBadge from '@/components/monitors/CheckBadge.vue';
import StatusBadge from '@/components/monitors/StatusBadge.vue';
import TagFilter from '@/components/monitors/TagFilter.vue';
import { useOrganizationLimit } from '@/composables/useOrganizationLimit';
import { useOrganizationRealtime } from '@/composables/useOrganizationRealtime';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    formatDateTime,
    formatDuration,
    formatInterval,
} from '@/lib/monitoring';
import {
    create,
    exportMethod,
    index,
    show,
} from '@/routes/organizations/monitors';
import { create as importCreate } from '@/routes/organizations/monitors/import';
import type { CurrentOrganization, MonitorSummary, Paginated } from '@/types';
import { useTranslations } from '@/lib/i18n';

const { t } = useTranslations();

defineProps<{
    monitors: Paginated<MonitorSummary>;
    canCreate: boolean;
    tag: string | null;
    tagOptions: string[];
}>();

const page = usePage();
const organization = computed(
    () => page.props.currentOrganization as CurrentOrganization,
);

useOrganizationRealtime(organization.value.id, { only: ['monitors'] });

const { reached: limitReached } = useOrganizationLimit('monitors');

const paginationLabel = (label: string) =>
    label.replace('&laquo;', '«').replace('&raquo;', '»');

setLayoutProps({
    breadcrumbs: [
        { title: t('Monitors'), href: index(organization.value.slug) },
    ],
});
</script>

<template>
    <Head :title="t('Monitors')" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">
                    {{ t('Monitors') }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{
                        t(
                            'Confirmed state, with the latest raw check alongside.',
                        )
                    }}
                </p>
            </div>
            <div class="flex flex-wrap items-center justify-end gap-2">
                <Button variant="outline" size="sm" as-child>
                    <a
                        :href="
                            exportMethod.url(organization.slug, {
                                query: tag ? { tag } : {},
                            })
                        "
                        data-test="export-monitors"
                    >
                        <Download class="size-4" />
                        {{ t('Export') }}
                    </a>
                </Button>
                <Button v-if="canCreate" variant="outline" size="sm" as-child>
                    <Link :href="importCreate(organization.slug)">
                        <Upload class="size-4" />
                        {{ t('Import') }}
                    </Link>
                </Button>
                <Button v-if="canCreate && limitReached" disabled>
                    <Plus class="size-4" />
                    {{ t('New monitor') }}
                </Button>
                <Button v-else-if="canCreate" as-child>
                    <Link :href="create(organization.slug)">
                        <Plus class="size-4" />
                        {{ t('New monitor') }}
                    </Link>
                </Button>
            </div>
        </div>

        <TagFilter
            :tags="tagOptions"
            :current="tag"
            :href="index.url(organization.slug)"
        />

        <LimitNotice
            feature="monitors"
            :resource="t('active monitors')"
            :hint="
                t(
                    'Pause or delete a monitor before adding or resuming another.',
                )
            "
        />

        <EmptyState
            v-if="monitors.data.length === 0 && tag"
            :icon="Activity"
            :title="t('No monitor with this tag')"
            :description="t('Choose another tag or show all monitors.')"
        />

        <EmptyState
            v-else-if="monitors.data.length === 0"
            :icon="Activity"
            :title="t('No monitors yet')"
            :description="
                t(
                    'Add an HTTP or HTTPS endpoint to start checking it every minute or more.',
                )
            "
            data-test="monitors-empty-state"
        >
            <Button v-if="canCreate && !limitReached" as-child>
                <Link :href="create(organization.slug)">{{
                    t('Create a monitor')
                }}</Link>
            </Button>
        </EmptyState>

        <ul v-else class="divide-y rounded-lg border" data-test="monitors-list">
            <li v-for="monitor in monitors.data" :key="monitor.id">
                <Link
                    :href="show([organization.slug, monitor.id])"
                    class="flex flex-col gap-2 p-4 hover:bg-muted/50 sm:flex-row sm:items-center"
                >
                    <div class="min-w-0 flex-1">
                        <p class="flex items-center gap-2 truncate font-medium">
                            <StatusBadge :status="monitor.state.status" />
                            {{ monitor.name }}
                            <Badge
                                v-if="!monitor.enabled"
                                variant="secondary"
                                >{{ t('Paused') }}</Badge
                            >
                        </p>
                        <p
                            class="truncate font-mono text-xs text-muted-foreground"
                        >
                            {{ monitor.target }}
                        </p>
                        <p
                            v-if="monitor.tags.length > 0"
                            class="mt-1 flex flex-wrap gap-1"
                        >
                            <Badge
                                v-for="monitorTag in monitor.tags"
                                :key="monitorTag"
                                variant="outline"
                                class="font-normal"
                                >{{ monitorTag }}</Badge
                            >
                        </p>
                    </div>
                    <div
                        class="flex flex-wrap items-center gap-3 text-sm text-muted-foreground tabular-nums"
                    >
                        <span>{{
                            t('every :interval', {
                                interval: formatInterval(
                                    monitor.interval_seconds,
                                ),
                            })
                        }}</span>
                        <span v-if="monitor.latest_check">
                            {{
                                formatDuration(monitor.latest_check.duration_ms)
                            }}
                            ·
                            {{
                                formatDateTime(monitor.latest_check.checked_at)
                            }}
                        </span>
                        <CheckBadge :check="monitor.latest_check" />
                    </div>
                </Link>
            </li>
        </ul>

        <nav
            v-if="monitors.last_page > 1"
            class="flex flex-wrap gap-1"
            :aria-label="t('Pagination')"
        >
            <template v-for="link in monitors.links" :key="link.label">
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
