<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, Check, CircleDashed, Loader, Search } from '@lucide/vue';
import { useTranslations } from '@/lib/i18n';
import { formatReleaseDate } from '@/lib/site';

type Milestone = {
    id: string;
    version: string;
    status: 'in_progress' | 'planned' | 'exploring';
    statusLabel: string;
    title: string;
    summary: string;
    highlights: string[];
};

defineProps<{
    milestones: Milestone[];
    latestRelease: {
        version: string;
        date: string | null;
        href: string;
    } | null;
}>();

const { t } = useTranslations();

const statusIcons = {
    in_progress: Loader,
    planned: CircleDashed,
    exploring: Search,
} as const;

const statusClasses = {
    in_progress: 'bg-primary text-primary-foreground border-transparent',
    planned: 'bg-background text-foreground',
    exploring: 'border-dashed bg-background text-muted-foreground',
} as const;
</script>

<template>
    <Head :title="t('Roadmap')" />

    <div class="mx-auto max-w-4xl px-4 py-12 sm:px-6 lg:py-16">
        <div class="flex max-w-2xl flex-col gap-3">
            <p
                class="text-sm font-semibold tracking-wide text-primary uppercase"
            >
                {{ t('Roadmap') }}
            </p>
            <h1 class="text-3xl font-bold tracking-tight sm:text-4xl">
                {{ t('What is coming next') }}
            </h1>
            <p class="text-muted-foreground">
                {{
                    t(
                        'What is coming next in Veyra, in the order we are working on it.',
                    )
                }}
                {{
                    t(
                        'Plans can change as we learn from the people using Veyra.',
                    )
                }}
            </p>
        </div>

        <Link
            v-if="latestRelease"
            :href="latestRelease.href"
            class="group mt-8 flex items-center gap-3 rounded-xl border bg-card p-4 text-sm hover:bg-muted/50"
            data-test="roadmap-latest-release"
        >
            <span
                class="flex size-8 shrink-0 items-center justify-center rounded-full bg-status-up-soft text-status-up-soft-foreground"
            >
                <Check class="size-4" />
            </span>
            <span class="min-w-0 flex-1">
                <span class="font-medium">{{
                    t('Latest release: :version', {
                        version: latestRelease.version,
                    })
                }}</span>
                <span
                    v-if="latestRelease.date"
                    class="block text-muted-foreground"
                    >{{ formatReleaseDate(latestRelease.date) }}</span
                >
            </span>
            <span
                class="flex items-center gap-1 text-muted-foreground group-hover:text-foreground"
            >
                {{ t('Read the changelog') }}
                <ArrowRight class="size-4" />
            </span>
        </Link>

        <p
            v-if="milestones.length === 0"
            class="mt-12 rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground"
            data-test="roadmap-empty"
        >
            {{
                t(
                    'Nothing is planned right now: see the changelog for the latest releases.',
                )
            }}
        </p>

        <ol v-else class="relative mt-12 flex flex-col gap-10">
            <div
                class="absolute top-2 bottom-2 left-4 w-px bg-border"
                aria-hidden="true"
            />
            <li
                v-for="milestone in milestones"
                :id="milestone.id"
                :key="milestone.id"
                class="relative flex scroll-mt-24 gap-5"
                data-test="roadmap-milestone"
            >
                <span
                    :class="[
                        'relative flex size-8 shrink-0 items-center justify-center rounded-full border',
                        statusClasses[milestone.status],
                    ]"
                    aria-hidden="true"
                >
                    <component
                        :is="statusIcons[milestone.status]"
                        class="size-4"
                    />
                </span>
                <article
                    class="flex min-w-0 flex-1 flex-col gap-3 rounded-xl border bg-card p-5 sm:p-6"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <span
                            class="rounded-full border px-2 py-0.5 font-mono text-xs text-muted-foreground"
                            >{{
                                t('Version :version', {
                                    version: milestone.version,
                                })
                            }}</span
                        >
                        <span
                            :class="[
                                'rounded-full border px-2 py-0.5 text-xs font-medium',
                                statusClasses[milestone.status],
                            ]"
                            >{{ milestone.statusLabel }}</span
                        >
                    </div>
                    <h2 class="text-xl font-semibold tracking-tight">
                        {{ milestone.title }}
                    </h2>
                    <p class="text-muted-foreground">
                        {{ milestone.summary }}
                    </p>
                    <ul
                        v-if="milestone.highlights.length > 0"
                        class="flex flex-col gap-2 text-sm"
                    >
                        <li
                            v-for="highlight in milestone.highlights"
                            :key="highlight"
                            class="flex gap-2"
                        >
                            <span
                                class="mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary"
                            >
                                <Check class="size-3" />
                            </span>
                            {{ highlight }}
                        </li>
                    </ul>
                </article>
            </li>
        </ol>
    </div>
</template>
