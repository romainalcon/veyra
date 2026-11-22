<script setup lang="ts">
import { Head, setLayoutProps, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import MonitorForm from '@/components/monitors/MonitorForm.vue';
import { create, index, store } from '@/routes/organizations/monitors';
import type { CurrentOrganization, MonitorFormOptions } from '@/types';
import { useTranslations } from '@/lib/i18n';

const { t } = useTranslations();

defineProps<MonitorFormOptions>();

const page = usePage();
const organization = computed(
    () => page.props.currentOrganization as CurrentOrganization,
);

setLayoutProps({
    breadcrumbs: [
        { title: t('Monitors'), href: index(organization.value.slug) },
        { title: t('New monitor'), href: create(organization.value.slug) },
    ],
});
</script>

<template>
    <Head :title="t('New monitor')" />

    <div class="mx-auto w-full max-w-3xl px-4 py-6">
        <Heading
            :title="t('New monitor')"
            :description="
                t(
                    'Veyra checks HTTP endpoints, TCP ports and DNS records from its own workers, or waits for pings from your scheduled jobs.',
                )
            "
        />
        <MonitorForm
            :channels="channels"
            :intervals="intervals"
            :methods="methods"
            :content-types="contentTypes"
            :keyword-modes="keywordModes"
            :max-body-bytes="maxBodyBytes"
            :types="types"
            :tcp-ports="tcpPorts"
            :heartbeat-periods="heartbeatPeriods"
            :max-grace-minutes="maxGraceMinutes"
            :dns-record-types="dnsRecordTypes"
            :dns-resolvers="dnsResolvers"
            :dns-match-modes="dnsMatchModes"
            :max-dns-expected-values="maxDnsExpectedValues"
            :action="store.form(organization.slug)"
            :submit-label="t('Create monitor')"
        />
    </div>
</template>
