<script setup lang="ts">
import { Head, setLayoutProps, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import MaintenanceSeriesForm from '@/components/maintenance/MaintenanceSeriesForm.vue';
import { useTranslations } from '@/lib/i18n';
import { index } from '@/routes/organizations/maintenance';
import { edit, update } from '@/routes/organizations/maintenance/series';
import type {
    CurrentOrganization,
    MaintenanceMonitorOption,
    MaintenanceSeries,
} from '@/types';

const { t } = useTranslations();

const props = defineProps<{
    series: MaintenanceSeries;
    monitors: MaintenanceMonitorOption[];
    maxDurationHours: number;
    timezone: string;
}>();

const page = usePage();
const organization = computed(
    () => page.props.currentOrganization as CurrentOrganization,
);

setLayoutProps({
    breadcrumbs: [
        { title: t('Maintenance'), href: index(organization.value.slug) },
        {
            title: props.series.title,
            href: edit([organization.value.slug, props.series.id]),
        },
    ],
});
</script>

<template>
    <Head :title="t('Edit :name', { name: series.title })" />

    <div class="mx-auto w-full max-w-3xl px-4 py-6">
        <Heading :title="t('Edit recurring maintenance')" />
        <MaintenanceSeriesForm
            :series="series"
            :monitors="monitors"
            :max-duration-hours="maxDurationHours"
            :timezone="timezone"
            :action="update.form([organization.slug, series.id])"
            :submit-label="t('Save changes')"
        />
    </div>
</template>
