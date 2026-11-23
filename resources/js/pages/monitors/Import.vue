<script setup lang="ts">
import { Head, Link, router, setLayoutProps, usePage } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck, CopyMinus, Upload } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslations } from '@/lib/i18n';
import { index } from '@/routes/organizations/monitors';
import { preview, store } from '@/routes/organizations/monitors/import';
import type { CurrentOrganization, ImportPreview } from '@/types';

const { t } = useTranslations();

defineProps<{
    maxMonitors: number;
    maxFileKb: number;
}>();

const page = usePage();
const organization = computed(
    () => page.props.currentOrganization as CurrentOrganization,
);

setLayoutProps({
    breadcrumbs: [
        { title: t('Monitors'), href: index(organization.value.slug) },
        { title: t('Import'), href: '#' },
    ],
});

const file = ref<File | null>(null);
const skipExisting = ref(true);
const processing = ref(false);
const errors = computed(() => page.props.errors as Record<string, string>);

/** Flashed by the dry run; cleared when another file is chosen. */
const result = computed(
    () =>
        (page.flash as { importPreview?: ImportPreview } | undefined)
            ?.importPreview ?? null,
);
const previewedFile = ref<File | null>(null);
const shownPreview = computed(() =>
    previewedFile.value !== null && previewedFile.value === file.value
        ? result.value
        : null,
);

const canImport = computed(
    () =>
        shownPreview.value !== null &&
        shownPreview.value.summary.invalid === 0 &&
        shownPreview.value.summary.valid > 0 &&
        shownPreview.value.fitsQuota,
);

const onFile = (event: Event) => {
    file.value = (event.target as HTMLInputElement).files?.[0] ?? null;
};

const submit = (dryRun: boolean) => {
    if (!file.value) {
        return;
    }

    const route = dryRun
        ? preview(organization.value.slug)
        : store(organization.value.slug);
    const selected = file.value;

    router.post(
        route.url,
        { file: selected, skip_existing: skipExisting.value ? 1 : 0 },
        {
            forceFormData: true,
            preserveScroll: true,
            preserveState: true,
            onStart: () => (processing.value = true),
            onFinish: () => (processing.value = false),
            onSuccess: () => {
                if (dryRun) {
                    previewedFile.value = selected;
                }
            },
        },
    );
};

const excludedLabel = (key: string) =>
    ({
        headers: t('custom headers'),
        body: t('request body'),
        basic_auth: t('basic authentication'),
        ping_token: t('ping URL'),
    })[key] ?? key;
</script>

<template>
    <Head :title="t('Import monitors')" />

    <div class="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">
                {{ t('Import monitors') }}
            </h1>
            <p class="text-sm text-muted-foreground">
                {{
                    t(
                        'Upload a file exported from Veyra. Every monitor is checked like in the form before anything is created, and nothing is created if one of them is invalid.',
                    )
                }}
            </p>
        </div>

        <form
            class="grid gap-4 rounded-lg border p-4"
            @submit.prevent="submit(true)"
        >
            <div class="grid gap-2">
                <Label for="import-file">{{ t('Export file (JSON)') }}</Label>
                <Input
                    id="import-file"
                    type="file"
                    accept="application/json,.json"
                    required
                    @change="onFile"
                />
                <p class="text-xs text-muted-foreground">
                    {{
                        t('Up to :count monitors and :size KiB per file.', {
                            count: maxMonitors,
                            size: maxFileKb,
                        })
                    }}
                </p>
                <InputError :message="errors.file" />
                <InputError :message="errors.limit" />
            </div>

            <label class="flex items-center gap-2 text-sm">
                <input
                    v-model="skipExisting"
                    type="checkbox"
                    class="size-4 rounded border-input"
                />
                {{
                    t('Skip monitors that already exist (same type and target)')
                }}
            </label>

            <div>
                <Button
                    type="submit"
                    variant="outline"
                    :disabled="!file || processing"
                >
                    <Spinner v-if="processing" />
                    {{ t('Preview') }}
                </Button>
            </div>
        </form>

        <section
            v-if="shownPreview"
            class="space-y-4"
            data-test="import-preview"
        >
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <Badge variant="secondary">
                    {{
                        t(':count to create', {
                            count: shownPreview.summary.valid,
                        })
                    }}
                </Badge>
                <Badge
                    v-if="shownPreview.summary.duplicates > 0"
                    variant="outline"
                >
                    {{
                        t(':count already exist', {
                            count: shownPreview.summary.duplicates,
                        })
                    }}
                </Badge>
                <Badge
                    v-if="shownPreview.summary.invalid > 0"
                    variant="destructive"
                >
                    {{
                        t(':count invalid', {
                            count: shownPreview.summary.invalid,
                        })
                    }}
                </Badge>
            </div>

            <p
                v-if="!shownPreview.fitsQuota && shownPreview.quota"
                class="rounded-lg border border-destructive/50 p-3 text-sm text-destructive"
            >
                {{
                    t(
                        'This organization is limited to :limit active monitors (:used in use): pause or remove monitors from the file.',
                        {
                            limit: shownPreview.quota.limit,
                            used: shownPreview.quota.used,
                        },
                    )
                }}
            </p>

            <ul class="divide-y rounded-lg border text-sm">
                <li
                    v-for="row in shownPreview.rows"
                    :key="row.index"
                    class="flex gap-3 p-3"
                >
                    <CircleCheck
                        v-if="row.status === 'valid'"
                        class="mt-0.5 size-4 shrink-0 text-emerald-600 dark:text-emerald-400"
                        :aria-label="t('Will be created')"
                    />
                    <CopyMinus
                        v-else-if="row.status === 'duplicate'"
                        class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                        :aria-label="t('Already exists')"
                    />
                    <CircleAlert
                        v-else
                        class="mt-0.5 size-4 shrink-0 text-destructive"
                        :aria-label="t('Invalid')"
                    />
                    <div class="min-w-0 flex-1 space-y-1">
                        <p
                            class="flex flex-wrap items-center gap-2 font-medium"
                        >
                            {{
                                row.name ||
                                t('Monitor :number', { number: row.index + 1 })
                            }}
                            <Badge
                                variant="outline"
                                class="font-normal uppercase"
                                >{{ row.type }}</Badge
                            >
                            <Badge v-if="!row.enabled" variant="secondary">{{
                                t('Paused')
                            }}</Badge>
                            <Badge
                                v-if="row.status === 'duplicate'"
                                variant="outline"
                                >{{ t('Already exists') }}</Badge
                            >
                        </p>
                        <p
                            v-if="row.target"
                            class="truncate font-mono text-xs text-muted-foreground"
                        >
                            {{ row.target }}
                        </p>
                        <p
                            v-if="row.tags.length > 0"
                            class="text-xs text-muted-foreground"
                        >
                            {{ row.tags.join(', ') }}
                        </p>
                        <ul
                            v-if="row.errors.length > 0"
                            class="list-disc pl-4 text-destructive"
                        >
                            <li v-for="error in row.errors" :key="error">
                                {{ error }}
                            </li>
                        </ul>
                        <p
                            v-if="row.excluded.length > 0"
                            class="text-xs text-amber-700 dark:text-amber-400"
                        >
                            {{
                                t(
                                    'Not in the export, set again after importing: :items.',
                                    {
                                        items: row.excluded
                                            .map(excludedLabel)
                                            .join(', '),
                                    },
                                )
                            }}
                        </p>
                    </div>
                </li>
            </ul>

            <div class="flex flex-wrap gap-2">
                <Button
                    :disabled="!canImport || processing"
                    data-test="confirm-import"
                    @click="submit(false)"
                >
                    <Spinner v-if="processing" />
                    <Upload v-else class="size-4" />
                    {{
                        t('Import :count monitors', {
                            count: shownPreview.summary.valid,
                        })
                    }}
                </Button>
                <Button variant="ghost" as-child>
                    <Link :href="index(organization.slug)">{{
                        t('Cancel')
                    }}</Link>
                </Button>
            </div>
        </section>
    </div>
</template>
