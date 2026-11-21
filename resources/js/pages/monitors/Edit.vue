<script setup lang="ts">
import { Head, setLayoutProps, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import MonitorForm from '@/components/monitors/MonitorForm.vue';
import { edit, index, show, update } from '@/routes/organizations/monitors';
import type {
    CurrentOrganization,
    MonitorDetail,
    MonitorFormOptions,
} from '@/types';
import { useTranslations } from '@/lib/i18n';

const { t } = useTranslations();

const props = defineProps<MonitorFormOptions & { monitor: MonitorDetail }>();

const page = usePage();
const organization = computed(
    () => page.props.currentOrganization as CurrentOrganization,
);

setLayoutProps({
    breadcrumbs: [
        { title: t('Monitors'), href: index(organization.value.slug) },
        {
            title: props.monitor.name,
            href: show([organization.value.slug, props.monitor.id]),
        },
        {
            title: t('Edit'),
            href: edit([organization.value.slug, props.monitor.id]),
        },
    ],
});
</script>

<template>
    <Head :title="t('Edit :name', { name: monitor.name })" />

    <div class="mx-auto w-full max-w-3xl px-4 py-6">
        <Heading :title="t('Edit monitor')" />
        <MonitorForm
            :channels="channels"
            :intervals="intervals"
            :methods="methods"
            :types="types"
            :tcp-ports="tcpPorts"
            :heartbeat-periods="heartbeatPeriods"
            :max-grace-minutes="maxGraceMinutes"
            :monitor="monitor"
            :action="update.form([organization.slug, monitor.id])"
            :submit-label="t('Save changes')"
        />
    </div>
</template>
