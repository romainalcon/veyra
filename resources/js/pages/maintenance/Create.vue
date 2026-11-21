<script setup lang="ts">
import { Head, setLayoutProps, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import MaintenanceForm from '@/components/maintenance/MaintenanceForm.vue';
import { create, index, store } from '@/routes/organizations/maintenance';
import type { CurrentOrganization, MaintenanceMonitorOption } from '@/types';

defineProps<{
    monitors: MaintenanceMonitorOption[];
    maxDurationHours: number;
}>();

const page = usePage();
const organization = computed(
    () => page.props.currentOrganization as CurrentOrganization,
);

setLayoutProps({
    breadcrumbs: [
        { title: 'Maintenance', href: index(organization.value.slug) },
        { title: 'New window', href: create(organization.value.slug) },
    ],
});
</script>

<template>
    <Head title="New maintenance window" />

    <div class="mx-auto w-full max-w-3xl px-4 py-6">
        <Heading
            title="New maintenance window"
            description="Checks keep running during the window, but they open no incident, send no notification and do not count towards uptime."
        />
        <MaintenanceForm
            :monitors="monitors"
            :max-duration-hours="maxDurationHours"
            :action="store.form(organization.slug)"
            submit-label="Schedule maintenance"
        />
    </div>
</template>
