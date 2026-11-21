<script setup lang="ts">
import { Head, setLayoutProps, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import MonitorForm from '@/components/monitors/MonitorForm.vue';
import { create, index, store } from '@/routes/organizations/monitors';
import type { CurrentOrganization, MonitorFormOptions } from '@/types';

defineProps<MonitorFormOptions>();

const page = usePage();
const organization = computed(
    () => page.props.currentOrganization as CurrentOrganization,
);

setLayoutProps({
    breadcrumbs: [
        { title: 'Monitors', href: index(organization.value.slug) },
        { title: 'New monitor', href: create(organization.value.slug) },
    ],
});
</script>

<template>
    <Head title="New monitor" />

    <div class="mx-auto w-full max-w-3xl px-4 py-6">
        <Heading
            title="New monitor"
            description="Veyra checks the endpoint from its own workers and records every result."
        />
        <MonitorForm
            :channels="channels"
            :intervals="intervals"
            :methods="methods"
            :types="types"
            :tcp-ports="tcpPorts"
            :action="store.form(organization.slug)"
            submit-label="Create monitor"
        />
    </div>
</template>
