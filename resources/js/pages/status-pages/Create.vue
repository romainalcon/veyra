<script setup lang="ts">
import { Head, setLayoutProps, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import StatusPageForm from '@/components/status-pages/StatusPageForm.vue';
import { create, index, store } from '@/routes/organizations/status-pages';
import type { CurrentOrganization, StatusPageFormOptions } from '@/types';
import { useTranslations } from '@/lib/i18n';

const { t } = useTranslations();

defineProps<StatusPageFormOptions>();

const page = usePage();
const organization = computed(
    () => page.props.currentOrganization as CurrentOrganization,
);

setLayoutProps({
    breadcrumbs: [
        { title: t('Status pages'), href: index(organization.value.slug) },
        { title: t('New status page'), href: create(organization.value.slug) },
    ],
});
</script>

<template>
    <Head :title="t('New status page')" />

    <div class="mx-auto w-full max-w-3xl px-4 py-6">
        <Heading
            :title="t('New status page')"
            :description="
                t(
                    'A public page showing the confirmed state, uptime, incidents and planned maintenance of selected monitors.',
                )
            "
        />
        <StatusPageForm
            :monitors="monitors"
            :max-components="maxComponents"
            :action="store.form(organization.slug)"
            :submit-label="t('Create status page')"
        />
    </div>
</template>
