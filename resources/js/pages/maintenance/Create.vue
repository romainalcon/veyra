<script setup lang="ts">
import { Head, setLayoutProps, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import MaintenanceForm from '@/components/maintenance/MaintenanceForm.vue';
import { create, index, store } from '@/routes/organizations/maintenance';
import type { CurrentOrganization, MaintenanceMonitorOption } from '@/types';
import { useTranslations } from '@/lib/i18n';

const { t } = useTranslations();

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
        { title: t('Maintenance'), href: index(organization.value.slug) },
        { title: t('New window'), href: create(organization.value.slug) },
    ],
});
</script>

<template>
    <Head :title="t('New maintenance window')" />

    <div class="mx-auto w-full max-w-3xl px-4 py-6">
        <Heading
            :title="t('New maintenance window')"
            :description="
                t(
                    'Checks keep running during the window, but they open no incident, send no notification and do not count towards uptime.',
                )
            "
        />
        <MaintenanceForm
            :monitors="monitors"
            :max-duration-hours="maxDurationHours"
            :action="store.form(organization.slug)"
            :submit-label="t('Schedule maintenance')"
        />
    </div>
</template>
