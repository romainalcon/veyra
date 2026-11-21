<script setup lang="ts">
import { Head, Link, setLayoutProps, usePage } from '@inertiajs/vue3';
import { ExternalLink, Globe, Plus } from '@lucide/vue';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { create, edit, index } from '@/routes/organizations/status-pages';
import type { CurrentOrganization, StatusPageSummary } from '@/types';

defineProps<{ statusPages: StatusPageSummary[]; canManage: boolean }>();

const page = usePage();
const organization = computed(
    () => page.props.currentOrganization as CurrentOrganization,
);

setLayoutProps({
    breadcrumbs: [
        { title: 'Status pages', href: index(organization.value.slug) },
    ],
});
</script>

<template>
    <Head title="Status pages" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">
                    Status pages
                </h1>
                <p class="text-sm text-muted-foreground">
                    Public pages for your users, refreshed every minute.
                </p>
            </div>
            <Button v-if="canManage" as-child>
                <Link :href="create(organization.slug)">
                    <Plus class="size-4" />
                    New status page
                </Link>
            </Button>
        </div>

        <div
            v-if="statusPages.length === 0"
            class="flex flex-1 flex-col items-center justify-center rounded-xl border border-dashed p-10 text-center"
            data-test="status-pages-empty-state"
        >
            <div
                class="mb-4 flex size-12 items-center justify-center rounded-full bg-muted"
            >
                <Globe class="size-6 text-muted-foreground" />
            </div>
            <h2 class="text-lg font-semibold">No status pages yet</h2>
            <p class="mt-1 max-w-sm text-sm text-muted-foreground">
                Share the state of your services without exposing internal
                details.
            </p>
        </div>

        <ul v-else class="divide-y rounded-lg border" data-test="status-pages">
            <li
                v-for="statusPage in statusPages"
                :key="statusPage.id"
                class="flex flex-col gap-2 p-4 sm:flex-row sm:items-center"
            >
                <div class="min-w-0 flex-1">
                    <p class="flex items-center gap-2 font-medium">
                        {{ statusPage.title }}
                        <Badge
                            :variant="
                                statusPage.is_published
                                    ? 'default'
                                    : 'secondary'
                            "
                            >{{
                                statusPage.is_published ? 'Published' : 'Draft'
                            }}</Badge
                        >
                    </p>
                    <p class="truncate text-sm text-muted-foreground">
                        /status/{{ statusPage.slug }} ·
                        {{ statusPage.components_count }} component(s)
                    </p>
                </div>
                <div class="flex gap-2">
                    <Button
                        v-if="statusPage.is_published"
                        variant="ghost"
                        size="sm"
                        as-child
                    >
                        <a
                            :href="statusPage.public_url"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            <ExternalLink class="size-4" />
                            View
                        </a>
                    </Button>
                    <Button
                        v-if="canManage"
                        variant="outline"
                        size="sm"
                        as-child
                    >
                        <Link :href="edit([organization.slug, statusPage.id])"
                            >Edit</Link
                        >
                    </Button>
                </div>
            </li>
        </ul>
    </div>
</template>
