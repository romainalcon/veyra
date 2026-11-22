<script setup lang="ts">
import { Head, setLayoutProps, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import MaintenanceSeriesForm from '@/components/maintenance/MaintenanceSeriesForm.vue';
import { useTranslations } from '@/lib/i18n';
import { index } from '@/routes/organizations/maintenance';
import { create, store } from '@/routes/organizations/maintenance/series';
import type { CurrentOrganization, MaintenanceMonitorOption } from '@/types';

const { t } = useTranslations();

defineProps<{
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
            title: t('New recurring maintenance'),
            href: create(organization.value.slug),
        },
    ],
});
</script>

<template>
    <Head :title="t('New recurring maintenance')" />

    <div class="mx-auto w-full max-w-3xl px-4 py-6">
        <Heading
            :title="t('New recurring maintenance')"
            :description="
                t(
                    'Occurrences are planned two weeks ahead and behave like one-off windows: checks keep running but open no incident and do not count towards uptime.',
                )
            "
        />
        <MaintenanceSeriesForm
            :monitors="monitors"
            :max-duration-hours="maxDurationHours"
            :timezone="timezone"
            :action="store.form(organization.slug)"
            :submit-label="t('Schedule recurring maintenance')"
        />
    </div>
</template>
