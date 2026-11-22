<script setup lang="ts">
import EmptyState from '@/components/EmptyState.vue';
import { Head, Link, router, setLayoutProps, usePage } from '@inertiajs/vue3';
import { Pencil, Plus, Square, Wrench, X } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/monitoring';
import {
    cancel,
    create,
    edit,
    end,
    index,
} from '@/routes/organizations/maintenance';
import type {
    CurrentOrganization,
    MaintenanceStatus,
    MaintenanceWindow,
    Paginated,
} from '@/types';
import { useTranslations } from '@/lib/i18n';

const { t } = useTranslations();

defineProps<{
    upcoming: MaintenanceWindow[];
    past: Paginated<MaintenanceWindow>;
    canManage: boolean;
}>();

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
            <Button v-if="canManage" as-child>
                <Link :href="create(organization.slug)">
                    <Plus class="size-4" />
                    {{ t('New window') }}
                </Link>
            </Button>
        </div>

        <InputError :message="page.props.errors?.window" />

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
                        <Button variant="outline" size="sm" as-child>
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
    </div>
</template>
