<script setup lang="ts">
import { Head, Link, setLayoutProps, usePage } from '@inertiajs/vue3';
import { Activity, Plus } from '@lucide/vue';
import { computed } from 'vue';
import CheckBadge from '@/components/monitors/CheckBadge.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    formatDateTime,
    formatDuration,
    formatInterval,
} from '@/lib/monitoring';
import { create, index, show } from '@/routes/organizations/monitors';
import type { CurrentOrganization, MonitorSummary, Paginated } from '@/types';

defineProps<{
    monitors: Paginated<MonitorSummary>;
    canCreate: boolean;
}>();

const page = usePage();
const organization = computed(
    () => page.props.currentOrganization as CurrentOrganization,
);

const paginationLabel = (label: string) =>
    label.replace('&laquo;', '«').replace('&raquo;', '»');

setLayoutProps({
    breadcrumbs: [{ title: 'Monitors', href: index(organization.value.slug) }],
});
</script>

<template>
    <Head title="Monitors" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">Monitors</h1>
                <p class="text-sm text-muted-foreground">
                    Results shown are the latest raw checks.
                </p>
            </div>
            <Button v-if="canCreate" as-child>
                <Link :href="create(organization.slug)">
                    <Plus class="size-4" />
                    New monitor
                </Link>
            </Button>
        </div>

        <div
            v-if="monitors.data.length === 0"
            class="flex flex-1 flex-col items-center justify-center rounded-xl border border-dashed p-10 text-center"
            data-test="monitors-empty-state"
        >
            <div
                class="mb-4 flex size-12 items-center justify-center rounded-full bg-muted"
            >
                <Activity class="size-6 text-muted-foreground" />
            </div>
            <h2 class="text-lg font-semibold">No monitors yet</h2>
            <p class="mt-1 max-w-sm text-sm text-muted-foreground">
                Add an HTTP or HTTPS endpoint to start checking it every minute
                or more.
            </p>
            <Button v-if="canCreate" class="mt-4" as-child>
                <Link :href="create(organization.slug)">Create a monitor</Link>
            </Button>
        </div>

        <ul v-else class="divide-y rounded-lg border" data-test="monitors-list">
            <li v-for="monitor in monitors.data" :key="monitor.id">
                <Link
                    :href="show([organization.slug, monitor.id])"
                    class="flex flex-col gap-2 p-4 hover:bg-muted/50 sm:flex-row sm:items-center"
                >
                    <div class="min-w-0 flex-1">
                        <p class="flex items-center gap-2 truncate font-medium">
                            {{ monitor.name }}
                            <Badge v-if="!monitor.enabled" variant="secondary"
                                >Paused</Badge
                            >
                        </p>
                        <p class="truncate text-sm text-muted-foreground">
                            {{ monitor.method }} {{ monitor.url }}
                        </p>
                    </div>
                    <div
                        class="flex flex-wrap items-center gap-3 text-sm text-muted-foreground"
                    >
                        <span
                            >every
                            {{ formatInterval(monitor.interval_seconds) }}</span
                        >
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
            aria-label="Pagination"
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
