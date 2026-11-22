<script setup lang="ts">
import EmptyState from '@/components/EmptyState.vue';
import { Head, Link, setLayoutProps, usePage } from '@inertiajs/vue3';
import { ExternalLink, Globe, Plus } from '@lucide/vue';
import { computed } from 'vue';
import LimitNotice from '@/components/LimitNotice.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useOrganizationLimit } from '@/composables/useOrganizationLimit';
import { create, edit, index } from '@/routes/organizations/status-pages';
import type { CurrentOrganization, StatusPageSummary } from '@/types';
import { useTranslations } from '@/lib/i18n';

const { t, tc } = useTranslations();

defineProps<{ statusPages: StatusPageSummary[]; canManage: boolean }>();

const page = usePage();
const organization = computed(
    () => page.props.currentOrganization as CurrentOrganization,
);

const { reached: limitReached } = useOrganizationLimit('status_pages');

setLayoutProps({
    breadcrumbs: [
        { title: t('Status pages'), href: index(organization.value.slug) },
    ],
});
</script>

<template>
    <Head :title="t('Status pages')" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">
                    {{ t('Status pages') }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{
                        t(
                            'Public pages for your users, refreshed every minute.',
                        )
                    }}
                </p>
            </div>
            <Button v-if="canManage && limitReached" disabled>
                <Plus class="size-4" />
                {{ t('New status page') }}
            </Button>
            <Button v-else-if="canManage" as-child>
                <Link :href="create(organization.slug)">
                    <Plus class="size-4" />
                    {{ t('New status page') }}
                </Link>
            </Button>
        </div>

        <LimitNotice
            feature="status_pages"
            :resource="t('status pages')"
            :hint="t('Delete a status page before creating another.')"
        />

        <EmptyState
            v-if="statusPages.length === 0"
            :icon="Globe"
            :title="t('No status pages yet')"
            :description="
                t(
                    'Share the state of your services without exposing internal details.',
                )
            "
            data-test="status-pages-empty-state"
        />

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
                                statusPage.is_published
                                    ? t('Published')
                                    : t('Draft')
                            }}</Badge
                        >
                    </p>
                    <p class="truncate text-sm text-muted-foreground">
                        {{
                            statusPage.custom_url ??
                            `/status/${statusPage.slug}`
                        }}
                        ·
                        {{
                            tc(
                                ':count component|:count components',
                                statusPage.components_count,
                            )
                        }}
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
                            :href="
                                statusPage.custom_url ?? statusPage.public_url
                            "
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            <ExternalLink class="size-4" />
                            {{ t('View') }}
                        </a>
                    </Button>
                    <Button
                        v-if="canManage"
                        variant="outline"
                        size="sm"
                        as-child
                    >
                        <Link
                            :href="edit([organization.slug, statusPage.id])"
                            >{{ t('Edit') }}</Link
                        >
                    </Button>
                </div>
            </li>
        </ul>
    </div>
</template>
