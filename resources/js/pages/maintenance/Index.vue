<script setup lang="ts">
import EmptyState from '@/components/EmptyState.vue';
import { Head, Link, router, setLayoutProps, usePage } from '@inertiajs/vue3';
import {
    Pencil,
    Plus,
    Repeat,
    SkipForward,
    Square,
    Trash2,
    Wrench,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { describeSeries } from '@/lib/maintenance';
import { formatDateTime } from '@/lib/monitoring';
import {
    cancel,
    create,
    edit,
    end,
    index,
} from '@/routes/organizations/maintenance';
import {
    create as createSeries,
    destroy as destroySeries,
    edit as editSeries,
} from '@/routes/organizations/maintenance/series';
import type {
    CurrentOrganization,
    MaintenanceSeries,
    MaintenanceStatus,
    MaintenanceWindow,
    Paginated,
} from '@/types';
import { useTranslations } from '@/lib/i18n';

const { t } = useTranslations();

const props = defineProps<{
    upcoming: MaintenanceWindow[];
    past: Paginated<MaintenanceWindow>;
    series: MaintenanceSeries[];
    timezone: string;
    canManage: boolean;
}>();

/** Running or next occurrence of each series (upcoming is sorted by start). */
const nextOccurrence = computed(() => {
    const next = new Map<string, MaintenanceWindow>();

    for (const window of props.upcoming) {
        if (window.series_id && !next.has(window.series_id)) {
            next.set(window.series_id, window);
        }
    }

    return next;
});

const seriesToDelete = ref<MaintenanceSeries | null>(null);

const deleteSeries = () => {
    if (!seriesToDelete.value) {
        return;
    }

    router.visit(
        destroySeries([organization.value.slug, seriesToDelete.value.id]),
        {
            preserveScroll: true,
            onFinish: () => (seriesToDelete.value = null),
        },
    );
};

const page = usePage();
const organization = computed(
    () => page.props.currentOrganization as CurrentOrganization,
);

setLayoutProps({
    breadcrumbs: [
        { title: t('Maintenance'), href: index(organization.value.slug) },
    ],
});

const statusLabels: Record<
    MaintenanceStatus,
    { label: string; variant: 'default' | 'secondary' | 'outline' }
> = {
    active: { label: t('In progress'), variant: 'default' },
    scheduled: { label: t('Scheduled'), variant: 'outline' },
    completed: { label: t('Completed'), variant: 'secondary' },
    cancelled: { label: t('Cancelled'), variant: 'secondary' },
};

const params = (window: MaintenanceWindow) =>
    [organization.value.slug, window.id] as [string, string];

const cancelWindow = (window: MaintenanceWindow) =>
    router.visit(cancel(params(window)), { preserveScroll: true });

const endWindow = (window: MaintenanceWindow) =>
    router.visit(end(params(window)), { preserveScroll: true });

const paginationLabel = (label: string) =>
    label.replace('&laquo;', '«').replace('&raquo;', '»');
</script>

<template>
    <Head :title="t('Maintenance')" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">
                    {{ t('Maintenance') }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{
                        t(
                            'Planned windows suppress incidents and notifications and are excluded from uptime.',
                        )
                    }}
                </p>
            </div>
            <div v-if="canManage" class="flex flex-wrap gap-2">
                <Button variant="outline" as-child>
                    <Link
                        :href="createSeries(organization.slug)"
                        data-test="new-series-link"
                    >
                        <Repeat class="size-4" />
                        {{ t('New recurring maintenance') }}
                    </Link>
                </Button>
                <Button as-child>
                    <Link :href="create(organization.slug)">
                        <Plus class="size-4" />
                        {{ t('New window') }}
                    </Link>
                </Button>
            </div>
        </div>

        <InputError :message="page.props.errors?.window" />

        <section v-if="series.length" class="space-y-3">
            <div>
                <h2 class="text-base font-medium">
                    {{ t('Recurring maintenance') }}
                </h2>
                <p class="text-xs text-muted-foreground">
                    {{
                        t('Times in the organization time zone (:zone).', {
                            zone: timezone,
                        })
                    }}
                </p>
            </div>
            <ul
                class="divide-y rounded-lg border"
                data-test="maintenance-series"
            >
                <li
                    v-for="item in series"
                    :key="item.id"
                    class="flex flex-col gap-3 p-4 sm:flex-row sm:items-start"
                >
                    <div class="min-w-0 flex-1 space-y-1">
                        <p class="flex items-center gap-2 font-medium">
                            <Repeat
                                class="size-4 shrink-0 text-muted-foreground"
                            />
                            <span class="truncate">{{ item.title }}</span>
                        </p>
                        <p class="text-sm">{{ describeSeries(item) }}</p>
                        <p class="text-sm text-muted-foreground">
                            <template v-if="nextOccurrence.get(item.id)">
                                {{
                                    t('Next: :start', {
                                        start: formatDateTime(
                                            nextOccurrence.get(item.id)!
                                                .starts_at,
                                        ),
                                    })
                                }}
                            </template>
                            <template v-else>{{
                                t('No upcoming occurrence.')
                            }}</template>
                            ·
                            {{
                                item.monitors
                                    .map((monitor) => monitor.name)
                                    .join(', ') || t('No monitors')
                            }}
                        </p>
                    </div>
                    <div v-if="canManage" class="flex flex-wrap gap-2">
                        <Button variant="outline" size="sm" as-child>
                            <Link
                                :href="editSeries([organization.slug, item.id])"
                            >
                                <Pencil class="size-4" />
                                {{ t('Edit') }}
                            </Link>
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            data-test="delete-series-button"
                            @click="seriesToDelete = item"
                        >
                            <Trash2 class="size-4" />
                            {{ t('Delete') }}
                        </Button>
                    </div>
                </li>
            </ul>
        </section>

        <section class="space-y-3">
            <h2 class="text-base font-medium">
                {{ t('Upcoming and in progress') }}
            </h2>
            <EmptyState
                v-if="upcoming.length === 0"
                :icon="Wrench"
                :description="t('No maintenance planned.')"
                data-test="maintenance-empty-state"
            />
            <ul
                v-else
                class="divide-y rounded-lg border"
                data-test="maintenance-upcoming"
            >
                <li
                    v-for="window in upcoming"
                    :key="window.id"
                    class="flex flex-col gap-3 p-4 sm:flex-row sm:items-start"
                >
                    <div class="min-w-0 flex-1 space-y-1">
                        <p class="flex items-center gap-2 font-medium">
                            <Badge
                                :variant="statusLabels[window.status].variant"
                                :data-status="window.status"
                                >{{ statusLabels[window.status].label }}</Badge
                            >
                            <span class="truncate">{{ window.title }}</span>
                            <Repeat
                                v-if="window.series_id"
                                class="size-4 shrink-0 text-muted-foreground"
                                :aria-label="t('Recurring')"
                            />
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{ formatDateTime(window.starts_at) }} →
                            {{ formatDateTime(window.ends_at) }}
                        </p>
                        <p class="truncate text-sm text-muted-foreground">
                            {{
                                window.monitors
                                    .map((monitor) => monitor.name)
                                    .join(', ') || t('No monitors')
                            }}
                        </p>
                    </div>
                    <div v-if="canManage" class="flex flex-wrap gap-2">
                        <Button
                            v-if="!window.series_id"
                            variant="outline"
                            size="sm"
                            as-child
                        >
                            <Link :href="edit(params(window))">
                                <Pencil class="size-4" />
                                {{ t('Edit') }}
                            </Link>
                        </Button>
                        <Button
                            v-if="window.status === 'active'"
                            variant="outline"
                            size="sm"
                            data-test="end-maintenance-button"
                            @click="endWindow(window)"
                        >
                            <Square class="size-4" />
                            {{ t('End now') }}
                        </Button>
                        <Button
                            v-else-if="window.series_id"
                            variant="ghost"
                            size="sm"
                            data-test="skip-occurrence-button"
                            @click="cancelWindow(window)"
                        >
                            <SkipForward class="size-4" />
                            {{ t('Skip') }}
                        </Button>
                        <Button
                            v-else
                            variant="ghost"
                            size="sm"
                            data-test="cancel-maintenance-button"
                            @click="cancelWindow(window)"
                        >
                            <X class="size-4" />
                            {{ t('Cancel') }}
                        </Button>
                    </div>
                </li>
            </ul>
        </section>

        <section v-if="past.data.length" class="space-y-3">
            <h2 class="text-base font-medium">{{ t('History') }}</h2>
            <ul
                class="divide-y rounded-lg border text-sm"
                data-test="maintenance-history"
            >
                <li
                    v-for="window in past.data"
                    :key="window.id"
                    class="flex flex-wrap items-center gap-3 px-4 py-2"
                >
                    <Badge :variant="statusLabels[window.status].variant">{{
                        statusLabels[window.status].label
                    }}</Badge>
                    <span class="font-medium">{{ window.title }}</span>
                    <span class="text-muted-foreground">
                        {{ formatDateTime(window.starts_at) }} →
                        {{ formatDateTime(window.ends_at) }}
                    </span>
                </li>
            </ul>
            <nav
                v-if="past.last_page > 1"
                class="flex flex-wrap gap-1"
                :aria-label="t('Pagination')"
            >
                <template v-for="link in past.links" :key="link.label">
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
        </section>

        <Dialog
            :open="seriesToDelete !== null"
            @update:open="(open) => !open && (seriesToDelete = null)"
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{
                        t('Delete recurring maintenance?')
                    }}</DialogTitle>
                    <DialogDescription>
                        {{
                            t(
                                'Upcoming occurrences are removed. Running and past occurrences stay in the history.',
                            )
                        }}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="secondary">{{ t('Cancel') }}</Button>
                    </DialogClose>
                    <Button
                        variant="destructive"
                        data-test="confirm-delete-series-button"
                        @click="deleteSeries"
                    >
                        {{ t('Delete') }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
