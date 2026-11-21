<script setup lang="ts">
import { Head, router, setLayoutProps, usePage } from '@inertiajs/vue3';
import { ExternalLink, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import StatusPageDomainSettings from '@/components/status-pages/StatusPageDomainSettings.vue';
import StatusPageForm from '@/components/status-pages/StatusPageForm.vue';
import { Button } from '@/components/ui/button';
import {
    destroy,
    edit,
    index,
    update,
} from '@/routes/organizations/status-pages';
import type {
    CurrentOrganization,
    StatusPageDetail,
    StatusPageFormOptions,
} from '@/types';

const props = defineProps<
    StatusPageFormOptions & { statusPage: StatusPageDetail }
>();

const page = usePage();
const organization = computed(
    () => page.props.currentOrganization as CurrentOrganization,
);
const params = computed(
    () => [organization.value.slug, props.statusPage.id] as [string, string],
);

setLayoutProps({
    breadcrumbs: [
        { title: 'Status pages', href: index(organization.value.slug) },
        { title: props.statusPage.title, href: edit(params.value) },
    ],
});

const remove = () => router.visit(destroy(params.value));
</script>

<template>
    <Head :title="`Edit ${statusPage.title}`" />

    <div class="mx-auto w-full max-w-3xl space-y-6 px-4 py-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
            <Heading class="flex-1" title="Edit status page" />
            <div class="flex gap-2">
                <Button
                    v-if="statusPage.is_published"
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <a
                        :href="statusPage.public_url"
                        target="_blank"
                        rel="noopener noreferrer"
                        data-test="public-link"
                    >
                        <ExternalLink class="size-4" />
                        View public page
                    </a>
                </Button>
                <Button
                    variant="ghost"
                    size="sm"
                    data-test="delete-status-page-button"
                    @click="remove"
                >
                    <Trash2 class="size-4" />
                    Delete
                </Button>
            </div>
        </div>
        <StatusPageForm
            :status-page="statusPage"
            :monitors="monitors"
            :max-components="maxComponents"
            :action="update.form(params)"
            submit-label="Save changes"
        />
        <StatusPageDomainSettings
            :params="params"
            :domain="statusPage.domain"
        />
    </div>
</template>
