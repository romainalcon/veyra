<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Map as MapIcon } from '@lucide/vue';
import { computed } from 'vue';
import { currentLocale, useTranslations } from '@/lib/i18n';
import { formatReleaseDate } from '@/lib/site';
import { roadmap } from '@/routes';

/**
 * Released versions of CHANGELOG.md, rendered on the server (raw HTML
 * stripped), hence the v-html. Release notes are written in English.
 */
defineProps<{
    releases: {
        version: string;
        date: string | null;
        anchor: string;
        html: string;
    }[];
}>();

const { t } = useTranslations();
const notesInEnglishOnly = computed(() => currentLocale() !== 'en');
</script>

<template>
    <Head :title="t('Changelog')" />

    <div class="mx-auto max-w-6xl px-4 py-12 sm:px-6 lg:py-16">
        <div class="flex max-w-2xl flex-col gap-3">
            <p
                class="text-sm font-semibold tracking-wide text-primary uppercase"
            >
                {{ t('Changelog') }}
            </p>
            <h1 class="text-3xl font-bold tracking-tight sm:text-4xl">
                {{ t('What is new in Veyra') }}
            </h1>
            <p class="text-muted-foreground">
                {{ t('Every Veyra release: new features, changes and fixes.') }}
                <Link
                    :href="roadmap()"
                    class="inline-flex items-center gap-1 font-medium text-foreground underline underline-offset-2"
                >
                    <MapIcon class="size-4" />
                    {{ t('See what is coming next') }}
                </Link>
            </p>
            <p
                v-if="notesInEnglishOnly"
                class="text-sm text-muted-foreground italic"
                data-test="changelog-language-notice"
            >
                {{ t('Release notes are written in English.') }}
            </p>
        </div>

        <p
            v-if="releases.length === 0"
            class="mt-12 rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground"
            data-test="changelog-empty"
        >
            {{ t('No release notes yet.') }}
        </p>

        <div v-else class="mt-12 grid gap-10 lg:grid-cols-[10rem_1fr]">
            <nav
                class="hidden lg:block"
                :aria-label="t('Versions')"
                data-test="changelog-versions"
            >
                <ul
                    class="sticky top-24 flex flex-col gap-1 border-l text-sm text-muted-foreground"
                >
                    <li v-for="release in releases" :key="release.version">
                        <a
                            :href="`#${release.anchor}`"
                            class="-ml-px block border-l border-transparent py-1 pl-4 font-mono hover:border-foreground hover:text-foreground"
                            >{{ release.version }}</a
                        >
                    </li>
                </ul>
            </nav>

            <ol class="flex min-w-0 flex-col">
                <li
                    v-for="(release, index) in releases"
                    :id="release.anchor"
                    :key="release.version"
                    class="scroll-mt-24 border-t py-10 first:border-t-0 first:pt-0"
                    data-test="changelog-release"
                >
                    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                        <h2
                            class="font-mono text-2xl font-semibold tracking-tight"
                        >
                            <a
                                :href="`#${release.anchor}`"
                                class="hover:underline"
                                >{{ release.version }}</a
                            >
                        </h2>
                        <span
                            v-if="index === 0"
                            class="rounded-full bg-primary/10 px-2 py-0.5 text-xs font-medium text-primary"
                            >{{ t('Latest') }}</span
                        >
                        <time
                            v-if="release.date"
                            :datetime="release.date"
                            class="text-sm text-muted-foreground"
                            >{{ formatReleaseDate(release.date) }}</time
                        >
                    </div>
                    <!-- eslint-disable-next-line vue/no-v-html -->
                    <div
                        lang="en"
                        class="mt-4 text-sm leading-relaxed text-muted-foreground [&_a]:text-foreground [&_a]:underline [&_a]:underline-offset-2 [&_code]:rounded [&_code]:bg-muted [&_code]:px-1 [&_code]:py-0.5 [&_code]:font-mono [&_code]:text-[0.85em] [&_code]:text-foreground [&_h3]:mt-6 [&_h3]:mb-2 [&_h3]:text-xs [&_h3]:font-semibold [&_h3]:tracking-wide [&_h3]:text-foreground [&_h3]:uppercase [&_h3:first-child]:mt-0 [&_li]:break-words [&_p]:mb-3 [&_strong]:font-semibold [&_strong]:text-foreground [&_ul]:list-disc [&_ul]:space-y-2 [&_ul]:pl-5"
                        v-html="release.html"
                    />
                </li>
            </ol>
        </div>
    </div>
</template>
