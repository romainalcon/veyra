<script setup lang="ts">
import { Head, setLayoutProps, usePage } from '@inertiajs/vue3';
import { Activity } from '@lucide/vue';
import { computed } from 'vue';
import { useOrganizationRealtime } from '@/composables/useOrganizationRealtime';
import { dashboard } from '@/routes/organizations';
import type { CurrentOrganization } from '@/types';

const page = usePage();
const organization = computed(
    () => page.props.currentOrganization as CurrentOrganization,
);

useOrganizationRealtime(organization.value.id);

setLayoutProps({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard(organization.value.slug) },
    ],
});
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <div
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
                HTTP monitoring for {{ organization.name }} arrives in the next
                release. Your team and organization settings are ready.
            </p>
        </div>
    </div>
</template>
