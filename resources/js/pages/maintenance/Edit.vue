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
import { useTranslations } from '@/lib/i18n';

const { t } = useTranslations();

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
        { title: t('Maintenance'), href: index(organization.value.slug) },
        {
            title: props.window.title,
            href: edit([organization.value.slug, props.window.id]),
        },
    ],
});
</script>

<template>
    <Head :title="t('Edit :name', { name: window.title })" />

    <div class="mx-auto w-full max-w-3xl px-4 py-6">
        <Heading :title="t('Edit maintenance window')" />
        <MaintenanceForm
            :window="window"
            :monitors="monitors"
            :max-duration-hours="maxDurationHours"
            :action="update.form([organization.slug, window.id])"
            :submit-label="t('Save changes')"
        />
    </div>
</template>
