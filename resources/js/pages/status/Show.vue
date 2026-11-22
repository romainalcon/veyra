<script setup lang="ts">
import { Head, usePoll } from '@inertiajs/vue3';
import { computed } from 'vue';
import UptimeBars from '@/components/charts/UptimeBars.vue';
import PublicStatusLabel from '@/components/status-pages/PublicStatusLabel.vue';
import { formatUptime } from '@/lib/analytics';
import { useTranslations } from '@/lib/i18n';
import { formatDateTime } from '@/lib/monitoring';
import { publicStatusStyle } from '@/lib/statusPage';
import type { StatusPageSnapshot } from '@/types';

const props = defineProps<{ snapshot: StatusPageSnapshot }>();

const { t } = useTranslations();

/*
 * Public pages never use the WebSocket: they poll the cached snapshot.
 */
usePoll(60_000, { only: ['snapshot'] });

const overall = computed(() => publicStatusStyle(props.snapshot.status));
const activeMaintenance = computed(() =>
    props.snapshot.maintenance.filter((window) => window.active),
);
const plannedMaintenance = computed(() =>
    props.snapshot.maintenance.filter((window) => !window.active),
);
const ongoingIncidents = computed(() =>
    props.snapshot.incidents.filter(
        (incident) => incident.status === 'ongoing',
    ),
);
const pastIncidents = computed(() =>
    props.snapshot.incidents.filter(
        (incident) => incident.status === 'resolved',
    ),
);

const updateLabel = {
    investigating: 'Investigating',
    update: 'Update',
    resolved: 'Resolved',
} as const;
</script>

<template>
    <Head :title="snapshot.page.title" />

    <div class="space-y-8">
        <header class="space-y-2">
            <h1 class="text-2xl font-semibold tracking-tight">
                {{ snapshot.page.title }}
            </h1>
            <p
                v-if="snapshot.page.description"
                class="text-sm whitespace-pre-line text-muted-foreground"
            >
                {{ snapshot.page.description }}
            </p>
        </header>

        <div
            :class="[
                'flex items-center gap-2 rounded-lg border p-4 font-medium',
                overall.banner,
            ]"
            :data-status="snapshot.status"
            data-test="overall-status"
        >
            <component
                :is="overall.icon"
                class="size-5 shrink-0"
                aria-hidden="true"
            />
            {{ t(overall.headline) }}
        </div>

        <section
            v-for="incident in ongoingIncidents"
            :key="incident.key"
            class="space-y-3 rounded-lg border border-status-down/40 p-4"
            data-test="ongoing-incident"
        >
            <h2 class="font-medium">{{ incident.title }}</h2>
            <ol class="space-y-2 text-sm">
                <li v-for="update in incident.updates" :key="update.key">
                    <span class="font-medium">{{
                        t(updateLabel[update.type])
                    }}</span>
                    <span class="text-muted-foreground">
                        · {{ formatDateTime(update.at) }}</span
                    >
                    <p
                        v-if="update.message"
                        class="whitespace-pre-line text-muted-foreground"
                    >
                        {{ update.message }}
                    </p>
                </li>
            </ol>
        </section>

        <section
            v-for="window in activeMaintenance"
            :key="window.key"
            class="space-y-1 rounded-lg border border-status-maintenance/40 p-4 text-sm"
            data-test="active-maintenance"
        >
            <h2 class="font-medium">{{ window.title }}</h2>
            <p class="text-muted-foreground">
                {{ t('Until :date', { date: formatDateTime(window.ends_at) }) }}
                ·
                {{ window.components.join(', ') }}
            </p>
            <p
                v-if="window.description"
                class="whitespace-pre-line text-muted-foreground"
            >
                {{ window.description }}
            </p>
        </section>

        <section
            v-for="(group, index) in snapshot.groups"
            :key="group.name ?? `ungrouped-${index}`"
            class="space-y-3"
        >
            <h2 v-if="group.name" class="text-base font-medium">
                {{ group.name }}
            </h2>
            <ul class="divide-y rounded-lg border" data-test="components">
                <li
                    v-for="component in group.components"
                    :key="component.id"
                    class="space-y-3 p-4"
                >
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="flex-1 font-medium">{{
                            component.name
                        }}</span>
                        <span
                            v-if="snapshot.page.show_uptime"
                            class="text-sm text-muted-foreground"
                            >{{
                                t(':uptime · 90 days', {
                                    uptime: formatUptime(component.uptime_90d),
                                })
                            }}</span
                        >
                        <PublicStatusLabel :status="component.status" />
                    </div>
                    <UptimeBars
                        v-if="
                            snapshot.page.show_uptime && component.days.length
                        "
                        :days="component.days"
                    />
                </li>
            </ul>
        </section>

        <p
            v-if="snapshot.groups.length === 0"
            class="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground"
        >
            {{ t('No components are listed on this page yet.') }}
        </p>

        <section v-if="plannedMaintenance.length" class="space-y-3">
            <h2 class="text-base font-medium">
                {{ t('Planned maintenance') }}
            </h2>
            <ul class="divide-y rounded-lg border text-sm">
                <li
                    v-for="window in plannedMaintenance"
                    :key="window.key"
                    class="space-y-1 p-4"
                >
                    <p class="font-medium">{{ window.title }}</p>
                    <p class="text-muted-foreground">
                        {{ formatDateTime(window.starts_at) }} →
                        {{ formatDateTime(window.ends_at) }} ·
                        {{ window.components.join(', ') }}
                    </p>
                    <p
                        v-if="window.description"
                        class="whitespace-pre-line text-muted-foreground"
                    >
                        {{ window.description }}
                    </p>
                </li>
            </ul>
        </section>

        <section class="space-y-3">
            <h2 class="text-base font-medium">
                {{ t('Past incidents (14 days)') }}
            </h2>
            <p
                v-if="pastIncidents.length === 0"
                class="text-sm text-muted-foreground"
            >
                {{ t('No incidents reported.') }}
            </p>
            <ul v-else class="divide-y rounded-lg border text-sm">
                <li
                    v-for="incident in pastIncidents"
                    :key="incident.key"
                    class="space-y-2 p-4"
                >
                    <p class="font-medium">{{ incident.title }}</p>
                    <p class="text-muted-foreground">
                        {{ formatDateTime(incident.started_at) }} →
                        {{ formatDateTime(incident.resolved_at) }}
                    </p>
                    <p
                        v-for="update in incident.updates.filter(
                            (item) => item.message,
                        )"
                        :key="update.key"
                        class="whitespace-pre-line text-muted-foreground"
                    >
                        {{ update.message }}
                    </p>
                </li>
            </ul>
        </section>

        <p class="text-xs text-muted-foreground">
            {{
                t('Updated :date. This page refreshes automatically.', {
                    date: formatDateTime(snapshot.generated_at),
                })
            }}
        </p>
    </div>
</template>
