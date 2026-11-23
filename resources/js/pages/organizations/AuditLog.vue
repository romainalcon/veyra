<script setup lang="ts">
import { Head, Link, router, setLayoutProps, usePage } from '@inertiajs/vue3';
import { ScrollText } from '@lucide/vue';
import { computed } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/lib/i18n';
import { formatDateTime } from '@/lib/monitoring';
import { index as auditLogIndex } from '@/routes/organizations/audit-log';
import type {
    AuditLogEntry,
    CurrentOrganization,
    CursorPaginated,
} from '@/types';

const { t } = useTranslations();

const props = defineProps<{
    entries: CursorPaginated<AuditLogEntry>;
    category: string | null;
    categories: { value: string; label: string }[];
    retentionDays: number;
}>();

const page = usePage();
const organization = computed(
    () => page.props.currentOrganization as CurrentOrganization,
);

setLayoutProps({
    breadcrumbs: [
        {
            title: t('Audit log'),
            href: auditLogIndex(organization.value.slug),
        },
    ],
});

const categoryOptions = computed(() => [
    { value: '', label: t('All actions') },
    ...props.categories,
]);

const selectedCategory = computed({
    get: () => props.category ?? '',
    set: (value: string) =>
        router.get(
            auditLogIndex.url(organization.value.slug),
            value === '' ? {} : { category: value },
            { preserveScroll: true },
        ),
});

const actorName = (entry: AuditLogEntry) =>
    entry.actor.type === 'system'
        ? t('System')
        : entry.actor.type === 'api_token'
          ? t('API token “:name”', { name: entry.actor.name ?? '' })
          : (entry.actor.name ?? t('Deleted user'));

const format = (value: unknown): string => {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    if (typeof value === 'boolean') {
        return value ? t('yes') : t('no');
    }

    if (Array.isArray(value)) {
        return value.length === 0
            ? '—'
            : value
                  .map((item) =>
                      typeof item === 'object'
                          ? JSON.stringify(item)
                          : String(item),
                  )
                  .join(', ');
    }

    return typeof value === 'object' ? JSON.stringify(value) : String(value);
};

const changedFields = (entry: AuditLogEntry) => {
    const before = entry.changes?.before ?? {};
    const after = entry.changes?.after ?? {};

    return [...new Set([...Object.keys(before), ...Object.keys(after)])].map(
        (field) => ({
            field,
            before: field in before ? format(before[field]) : null,
            after: field in after ? format(after[field]) : null,
        }),
    );
};

const metadataItems = (entry: AuditLogEntry) =>
    Object.entries(entry.metadata ?? {}).map(([key, value]) => ({
        key,
        value: format(value),
    }));
</script>

<template>
    <Head :title="t('Audit log')" />

    <div class="mx-auto flex w-full max-w-5xl flex-col gap-4 p-4">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">
                    {{ t('Audit log') }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{
                        t(
                            'Changes made by members and API tokens. Secrets are never recorded. Entries are kept for :days days.',
                            { days: retentionDays },
                        )
                    }}
                </p>
            </div>
            <label class="flex items-center gap-2 text-sm">
                <span class="sr-only">{{ t('Filter by resource') }}</span>
                <NativeSelect
                    v-model="selectedCategory"
                    :options="categoryOptions"
                    data-test="audit-category"
                />
            </label>
        </div>

        <EmptyState
            v-if="entries.data.length === 0"
            :icon="ScrollText"
            :title="t('Nothing recorded yet')"
            :description="
                t(
                    'Changes to monitors, channels, status pages, members, settings and API tokens will appear here.',
                )
            "
        />

        <ol v-else class="divide-y rounded-lg border" data-test="audit-entries">
            <li
                v-for="entry in entries.data"
                :key="entry.id"
                class="space-y-2 p-4 text-sm"
            >
                <div
                    class="flex flex-wrap items-baseline justify-between gap-2"
                >
                    <p class="min-w-0">
                        <span class="font-medium">{{ entry.label }}</span>
                        <span
                            v-if="entry.subject?.label"
                            class="text-muted-foreground"
                        >
                            · {{ entry.subject.label }}</span
                        >
                    </p>
                    <time
                        :datetime="entry.created_at"
                        class="text-xs text-muted-foreground tabular-nums"
                        >{{ formatDateTime(entry.created_at) }}</time
                    >
                </div>
                <p
                    class="flex flex-wrap items-center gap-2 text-xs text-muted-foreground"
                >
                    <Badge variant="outline" class="font-normal">{{
                        actorName(entry)
                    }}</Badge>
                    <span v-for="item in metadataItems(entry)" :key="item.key">
                        {{ item.key }}: {{ item.value }}
                    </span>
                </p>
                <details v-if="changedFields(entry).length > 0">
                    <summary
                        class="cursor-pointer text-xs text-muted-foreground hover:text-foreground"
                    >
                        {{
                            t(':count changed fields', {
                                count: changedFields(entry).length,
                            })
                        }}
                    </summary>
                    <div class="mt-2 overflow-x-auto">
                        <table class="w-full text-xs">
                            <thead class="text-left text-muted-foreground">
                                <tr>
                                    <th class="py-1 pr-4 font-normal">
                                        {{ t('Field') }}
                                    </th>
                                    <th class="py-1 pr-4 font-normal">
                                        {{ t('Before') }}
                                    </th>
                                    <th class="py-1 font-normal">
                                        {{ t('After') }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="font-mono">
                                <tr
                                    v-for="change in changedFields(entry)"
                                    :key="change.field"
                                    class="align-top"
                                >
                                    <td class="py-1 pr-4">
                                        {{ change.field }}
                                    </td>
                                    <td class="py-1 pr-4 break-all">
                                        {{ change.before ?? '—' }}
                                    </td>
                                    <td class="py-1 break-all">
                                        {{ change.after ?? '—' }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </details>
            </li>
        </ol>

        <nav
            v-if="entries.prev_page_url || entries.next_page_url"
            class="flex gap-2"
            :aria-label="t('Pagination')"
        >
            <Button
                v-if="entries.prev_page_url"
                variant="ghost"
                size="sm"
                as-child
            >
                <Link :href="entries.prev_page_url" preserve-scroll>{{
                    t('Newer')
                }}</Link>
            </Button>
            <Button
                v-if="entries.next_page_url"
                variant="ghost"
                size="sm"
                as-child
            >
                <Link :href="entries.next_page_url" preserve-scroll>{{
                    t('Older')
                }}</Link>
            </Button>
        </nav>
    </div>
</template>
