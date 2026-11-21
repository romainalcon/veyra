<script setup lang="ts">
import {
    Head,
    Link,
    router,
    setLayoutProps,
    usePage,
    usePoll,
} from '@inertiajs/vue3';
import { Pause, Pencil, Play, RefreshCw, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import CheckBadge from '@/components/monitors/CheckBadge.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    formatDateTime,
    formatDuration,
    formatInterval,
} from '@/lib/monitoring';
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
    MonitorCheck,
    MonitorDetail,
} from '@/types';

const props = defineProps<{
    monitor: MonitorDetail;
    checks: CursorPaginated<MonitorCheck>;
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
        { title: 'Monitors', href: index(organization.value.slug) },
        { title: props.monitor.name, href: show(params.value) },
    ],
});

// HTTP polling until real-time updates land (Sprint 3). Only the first page
// of history is refreshed.
const isFirstPage = computed(() => props.checks.prev_cursor === null);
usePoll(15000, { only: ['monitor', 'checks'] }, { keepAlive: false });

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
                    <Badge v-if="!monitor.enabled" variant="secondary"
                        >Paused</Badge
                    >
                </h1>
                <p class="truncate text-sm text-muted-foreground">
                    {{ monitor.method }} {{ monitor.url }}
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
                    Check now
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
                    {{ monitor.enabled ? 'Pause' : 'Resume' }}
                </Button>
                <Button v-if="can.update" variant="outline" size="sm" as-child>
                    <Link :href="edit(params)">
                        <Pencil class="size-4" />
                        Edit
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
                    Delete
                </Button>
            </div>
        </div>

        <InputError :message="page.props.errors?.monitor" />

        <dl class="grid gap-4 rounded-lg border p-4 text-sm sm:grid-cols-4">
            <div>
                <dt class="text-muted-foreground">Latest check</dt>
                <dd class="mt-1">
                    <CheckBadge :check="monitor.latest_check" />
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Interval / timeout</dt>
                <dd class="mt-1">
                    {{ formatInterval(monitor.interval_seconds) }} /
                    {{ monitor.timeout_seconds }} s
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Expected statuses</dt>
                <dd class="mt-1">{{ monitor.expected_statuses }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Next check</dt>
                <dd class="mt-1">
                    {{
                        monitor.enabled
                            ? formatDateTime(monitor.next_check_at)
                            : 'Paused'
                    }}
                </dd>
            </div>
            <div v-if="monitor.header_names.length" class="sm:col-span-4">
                <dt class="text-muted-foreground">Custom headers</dt>
                <dd class="mt-1">{{ monitor.header_names.join(', ') }}</dd>
            </div>
        </dl>

        <section class="space-y-3">
            <h2 class="text-base font-medium">Check history</h2>
            <p
                v-if="checks.data.length === 0"
                class="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground"
                data-test="checks-empty-state"
            >
                No checks recorded yet. The first one runs within a few seconds
                when workers are online.
            </p>
            <div v-else class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm" data-test="checks-table">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2 font-medium">Checked at</th>
                            <th class="px-4 py-2 font-medium">Result</th>
                            <th class="px-4 py-2 font-medium">Duration</th>
                            <th class="px-4 py-2 font-medium">Redirects</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="check in checks.data" :key="check.id">
                            <td class="px-4 py-2 whitespace-nowrap">
                                {{ formatDateTime(check.checked_at) }}
                            </td>
                            <td class="px-4 py-2">
                                <CheckBadge :check="check" />
                            </td>
                            <td class="px-4 py-2">
                                {{ formatDuration(check.duration_ms) }}
                            </td>
                            <td class="px-4 py-2">{{ check.redirects }}</td>
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
                    <Link :href="checks.prev_page_url" preserve-scroll
                        >Newer</Link
                    >
                </Button>
                <span v-else-if="!isFirstPage" />
                <Button
                    v-if="checks.next_page_url"
                    variant="ghost"
                    size="sm"
                    class="ml-auto"
                    as-child
                >
                    <Link :href="checks.next_page_url" preserve-scroll
                        >Older</Link
                    >
                </Button>
            </div>
        </section>
    </div>
</template>
