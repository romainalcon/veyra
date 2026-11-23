<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppearanceToggle from '@/components/AppearanceToggle.vue';
import AppLogoMark from '@/components/AppLogoMark.vue';
import { Button } from '@/components/ui/button';
import { usePublicNavigation } from '@/composables/usePublicNavigation';
import { useTranslations } from '@/lib/i18n';
import { dashboard, home, login, register } from '@/routes';

/**
 * Layout of the core public site pages (changelog, roadmap, ADR-049): the
 * landing page's header and footer, with the PublicNavigation links.
 */
const page = usePage();
const { t } = useTranslations();
const headerLinks = usePublicNavigation('header');
const footerLinks = usePublicNavigation('footer');

const signedIn = computed(() => page.props.auth?.user != null);
const canRegister = computed(() => page.props.canRegister === true);
const sourceUrl = computed(() => page.props.system?.sourceUrl ?? null);

function isCurrent(href: string): boolean {
    return (
        new URL(href, 'http://localhost').pathname === page.url.split(/[?#]/)[0]
    );
}
</script>

<template>
    <div class="flex min-h-svh flex-col bg-background text-foreground">
        <header
            class="sticky top-0 z-30 border-b bg-background/90 backdrop-blur"
        >
            <nav
                class="mx-auto flex h-16 max-w-6xl items-center gap-4 px-4 sm:px-6"
                :aria-label="t('Main navigation')"
            >
                <Link
                    :href="home()"
                    class="flex items-center gap-2 text-lg font-semibold tracking-tight"
                >
                    <AppLogoMark class="size-8" />
                    Veyra
                </Link>

                <div
                    class="ml-6 hidden items-center gap-6 text-sm text-muted-foreground md:flex"
                >
                    <Link
                        v-for="link in headerLinks"
                        :key="link.id"
                        :href="link.href"
                        class="hover:text-foreground"
                        :class="{
                            'font-medium text-foreground': isCurrent(link.href),
                        }"
                        :aria-current="
                            isCurrent(link.href) ? 'page' : undefined
                        "
                        >{{ link.title }}</Link
                    >
                    <a
                        v-if="sourceUrl"
                        :href="sourceUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="hover:text-foreground"
                        >GitHub</a
                    >
                </div>

                <div class="ml-auto flex items-center gap-2">
                    <AppearanceToggle />
                    <Button v-if="signedIn" size="sm" as-child>
                        <Link :href="dashboard()">{{ t('Dashboard') }}</Link>
                    </Button>
                    <template v-else>
                        <Button variant="ghost" size="sm" as-child>
                            <Link :href="login()">{{ t('Log in') }}</Link>
                        </Button>
                        <Button v-if="canRegister" size="sm" as-child>
                            <Link :href="register()">{{
                                t('Get started')
                            }}</Link>
                        </Button>
                    </template>
                </div>
            </nav>
        </header>

        <main class="flex-1">
            <slot />
        </main>

        <footer class="border-t">
            <div
                class="mx-auto flex max-w-6xl flex-col gap-4 px-4 py-8 text-xs text-muted-foreground sm:flex-row sm:items-center sm:px-6"
            >
                <span
                    class="flex items-center gap-2 text-sm font-semibold text-foreground"
                >
                    <AppLogoMark class="size-6" />
                    Veyra
                </span>
                <div
                    class="flex flex-wrap items-center gap-x-3 gap-y-1 sm:ml-auto"
                >
                    <span>AGPL-3.0</span>
                    <template v-if="sourceUrl">
                        <span aria-hidden="true">·</span>
                        <a
                            :href="sourceUrl"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="hover:underline"
                            >{{ t('Source code') }}</a
                        >
                    </template>
                    <template v-for="link in footerLinks" :key="link.id">
                        <span aria-hidden="true">·</span>
                        <Link :href="link.href" class="hover:underline">{{
                            link.title
                        }}</Link>
                    </template>
                </div>
            </div>
        </footer>
    </div>
</template>
