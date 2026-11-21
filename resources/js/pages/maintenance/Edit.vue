<script setup lang="ts">
import { Head, setLayoutProps, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import MaintenanceForm from '@/components/maintenance/MaintenanceForm.vue';
import { edit, index, update } from '@/routes/organizations/maintenance';
import type {
    CurrentOrganization,
    MaintenanceMonitorOption,
    MaintenanceWindow,
} from '@/types';

const props = defineProps<{
    window: MaintenanceWindow;
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
        {
            title: props.window.title,
            href: edit([organization.value.slug, props.window.id]),
        },
    ],
});
</script>

<template>
    <Head :title="`Edit ${window.title}`" />

    <div class="mx-auto w-full max-w-3xl px-4 py-6">
        <Heading title="Edit maintenance window" />
        <MaintenanceForm
            :window="window"
            :monitors="monitors"
            :max-duration-hours="maxDurationHours"
            :action="update.form([organization.slug, window.id])"
            submit-label="Save changes"
        />
    </div>
</template>
