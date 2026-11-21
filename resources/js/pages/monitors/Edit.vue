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

const props = defineProps<MonitorFormOptions & { monitor: MonitorDetail }>();

const page = usePage();
const organization = computed(
    () => page.props.currentOrganization as CurrentOrganization,
);

setLayoutProps({
    breadcrumbs: [
        { title: 'Monitors', href: index(organization.value.slug) },
        {
            title: props.monitor.name,
            href: show([organization.value.slug, props.monitor.id]),
        },
        {
            title: 'Edit',
            href: edit([organization.value.slug, props.monitor.id]),
        },
    ],
});
</script>

<template>
    <Head :title="`Edit ${monitor.name}`" />

    <div class="mx-auto w-full max-w-3xl px-4 py-6">
        <Heading title="Edit monitor" />
        <MonitorForm
            :channels="channels"
            :intervals="intervals"
            :methods="methods"
            :types="types"
            :tcp-ports="tcpPorts"
            :monitor="monitor"
            :action="update.form([organization.slug, monitor.id])"
            submit-label="Save changes"
        />
    </div>
</template>
