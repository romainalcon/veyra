<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    Activity,
    BellRing,
    Check,
    Copy,
    ExternalLink,
    Globe,
    HeartPulse,
    ShieldCheck,
    Wrench,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import AppearanceToggle from '@/components/AppearanceToggle.vue';
import AppLogoMark from '@/components/AppLogoMark.vue';
import UptimeBars from '@/components/charts/UptimeBars.vue';
import StatusBadge from '@/components/monitors/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { usePublicNavigation } from '@/composables/usePublicNavigation';
import { useTranslations } from '@/lib/i18n';
import { login, register } from '@/routes';
import type { MonitorStatus } from '@/types';

const props = defineProps<{
    canRegister: boolean;
    sourceUrl: string | null;
}>();

const { t } = useTranslations();
const headerLinks = usePublicNavigation('header');
const footerLinks = usePublicNavigation('footer');

const features = computed(() => [
    {
        icon: Activity,
        title: t('HTTP, TCP and heartbeats'),
        text: t(
            'Check websites and APIs, open ports, or let your cron jobs and workers ping a secret URL.',
        ),
    },
    {
        icon: HeartPulse,
        title: t('No false alarms'),
        text: t(
            'An outage is confirmed after 3 failed checks and recovery after 2 successes. Missing data is shown as unknown, never as downtime.',
        ),
    },
    {
        icon: BellRing,
        title: t('Alerts that arrive'),
        text: t(
            'E-mail and Discord notifications with retries, a reminder when nobody acknowledges, and a full delivery history.',
        ),
    },
    {
        icon: Globe,
        title: t('Public status pages'),
        text: t(
            'Share uptime, incidents and planned maintenance on your own domain, without exposing internal details.',
        ),
    },
    {
        icon: Wrench,
        title: t('Planned maintenance'),
        text: t(
            'Checks keep running during maintenance windows, without incidents, notifications or impact on uptime.',
        ),
    },
    {
        icon: ShieldCheck,
        title: t('TLS certificates'),
        text: t(
            'Get warned 14, 7 and 1 days before a certificate expires, once per certificate.',
        ),
    },
]);

/*
 * Illustration only: a deterministic, healthy 30-day history (an occasional
 * short dip shows as amber, never as an outage under an "operational" banner).
 */
const demoDays = (seed: number) =>
    Array.from({ length: 30 }, (_, index) => ({
        day: `d-${index}`,
        uptime: (index * 7 + seed) % 29 === 0 ? 99.6 : 100,
        coverage: 1,
    }));

const demoMonitors = computed<
    { name: string; target: string; status: MonitorStatus; seed: number }[]
>(() => [
    {
        name: t('Website'),
        target: 'https://acme.example',
        status: 'up',
        seed: 1,
    },
    { name: 'API', target: 'https://api.acme.example', status: 'up', seed: 5 },
    {
        name: t('Nightly backup'),
        target: t('Daily cron job'),
        status: 'up',
        seed: 11,
    },
    {
        name: t('Mail server'),
        target: 'mail.acme.example:587',
        status: 'up',
        seed: 17,
    },
]);

const repositoryUrl = computed(
    () => props.sourceUrl ?? 'https://github.com/romainalcon/veyra',
);
const installGuideUrl = computed(
    () => `${repositoryUrl.value}/blob/main/docs/INSTALL.md`,
);
const installCommands = computed(() =>
    [
        `git clone ${repositoryUrl.value}.git veyra`,
        'cd veyra',
        'cp deploy/docker/.env.docker.example .env.docker',
        'docker compose --env-file .env.docker up -d --wait',
    ].join('\n'),
);

const copied = ref(false);

const copyCommands = async () => {
    try {
        await navigator.clipboard.writeText(installCommands.value);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    } catch {
        copied.value = false;
    }
};
</script>

<template>
    <Head :title="t('Uptime monitoring you can host yourself')" />

    <div class="min-h-svh bg-background text-foreground">
        <header
            class="sticky top-0 z-10 border-b bg-background/80 backdrop-blur"
        >
            <nav
                class="mx-auto flex h-16 max-w-6xl items-center gap-4 px-4 sm:px-6"
                :aria-label="t('Main navigation')"
            >
                <Link
                    href="/"
                    class="flex items-center gap-2 font-semibold tracking-tight"
                >
                    <AppLogoMark class="size-8" />
                    Veyra
                </Link>

                <div
                    class="ml-6 hidden items-center gap-6 text-sm text-muted-foreground md:flex"
                >
                    <a href="#features" class="hover:text-foreground">{{
                        t('Features')
                    }}</a>
                    <a href="#self-hosting" class="hover:text-foreground">{{
                        t('Self-hosting')
                    }}</a>
                    <Link
                        v-for="link in headerLinks"
                        :key="link.id"
                        :href="link.href"
                        class="hover:text-foreground"
                        >{{ link.title }}</Link
                    >
                    <a
                        :href="repositoryUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="hover:text-foreground"
                        >GitHub</a
                    >
                </div>

                <div class="ml-auto flex items-center gap-2">
                    <AppearanceToggle />
                    <Button variant="ghost" size="sm" as-child>
                        <Link :href="login()" data-test="landing-login">{{
                            t('Log in')
                        }}</Link>
                    </Button>
                    <Button v-if="canRegister" size="sm" as-child>
                        <Link :href="register()" data-test="landing-register">{{
                            t('Get started')
                        }}</Link>
                    </Button>
                </div>
            </nav>
        </header>

        <main>
            <section
                class="mx-auto grid max-w-6xl items-center gap-12 px-4 py-16 sm:px-6 lg:grid-cols-2 lg:py-24"
            >
                <div class="flex flex-col gap-6">
                    <p
                        class="inline-flex w-fit items-center gap-2 rounded-full border px-3 py-1 text-xs text-muted-foreground"
                    >
                        <span
                            class="size-1.5 rounded-full bg-primary"
                            aria-hidden="true"
                        />
                        {{ t('Open source · AGPL-3.0') }}
                    </p>
                    <h1
                        class="text-4xl font-semibold tracking-tight text-balance sm:text-5xl"
                    >
                        {{
                            t(
                                'Know your services are down before your users do.',
                            )
                        }}
                    </h1>
                    <p
                        class="max-w-xl text-lg text-pretty text-muted-foreground"
                    >
                        {{
                            t(
                                'Veyra watches your websites, APIs, servers and scheduled jobs, confirms real outages, alerts your team and keeps your users informed with public status pages.',
                            )
                        }}
                    </p>
                    <div class="flex flex-wrap gap-3">
                        <Button v-if="canRegister" size="lg" as-child>
                            <Link :href="register()">{{
                                t('Start monitoring')
                            }}</Link>
                        </Button>
                        <Button v-else size="lg" as-child>
                            <Link :href="login()">{{ t('Log in') }}</Link>
                        </Button>
                        <Button size="lg" variant="outline" as-child>
                            <a href="#self-hosting">{{
                                t('Host it yourself')
                            }}</a>
                        </Button>
                    </div>
                </div>

                <figure
                    class="rounded-xl border bg-card p-4 shadow-sm sm:p-6"
                    :aria-label="t('Example of a Veyra dashboard')"
                >
                    <div
                        class="mb-4 rounded-lg border border-status-up/30 bg-status-up-soft p-3 text-sm font-medium text-status-up-soft-foreground"
                    >
                        {{ t('All systems operational') }}
                    </div>
                    <ul class="divide-y">
                        <li
                            v-for="monitor in demoMonitors"
                            :key="monitor.seed"
                            class="flex flex-col gap-2 py-3"
                        >
                            <div class="flex items-center gap-3">
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-medium">
                                        {{ monitor.name }}
                                    </p>
                                    <p
                                        class="truncate text-xs text-muted-foreground"
                                    >
                                        {{ monitor.target }}
                                    </p>
                                </div>
                                <StatusBadge :status="monitor.status" />
                            </div>
                            <UptimeBars :days="demoDays(monitor.seed)" />
                        </li>
                    </ul>
                </figure>
            </section>

            <section id="features" class="border-t bg-muted/30">
                <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 lg:py-24">
                    <div class="flex max-w-2xl flex-col gap-3">
                        <h2 class="text-3xl font-semibold tracking-tight">
                            {{ t('Everything you need to watch production') }}
                        </h2>
                        <p class="text-muted-foreground">
                            {{
                                t(
                                    'One application, no external agents: probes run on your own workers.',
                                )
                            }}
                        </p>
                    </div>
                    <div class="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <article
                            v-for="feature in features"
                            :key="feature.title"
                            class="flex flex-col gap-3 rounded-xl border bg-card p-6"
                        >
                            <span
                                class="flex size-10 items-center justify-center rounded-lg bg-primary/10 text-primary"
                            >
                                <component :is="feature.icon" class="size-5" />
                            </span>
                            <h3 class="font-medium">{{ feature.title }}</h3>
                            <p class="text-sm text-muted-foreground">
                                {{ feature.text }}
                            </p>
                        </article>
                    </div>
                </div>
            </section>

            <section id="self-hosting" class="border-t">
                <div
                    class="mx-auto grid max-w-6xl items-center gap-10 px-4 py-16 sm:px-6 lg:grid-cols-2 lg:py-24"
                >
                    <div class="flex flex-col gap-4">
                        <h2 class="text-3xl font-semibold tracking-tight">
                            {{ t('Your data, your servers') }}
                        </h2>
                        <p class="text-muted-foreground">
                            {{
                                t(
                                    'Veyra Community is free and open source. Run it with Docker Compose on any Linux host, with MariaDB and Redis.',
                                )
                            }}
                        </p>
                        <ul class="flex flex-col gap-2 text-sm">
                            <li
                                v-for="point in [
                                    t('Prebuilt, versioned Docker images'),
                                    t('Organizations, roles and invitations'),
                                    t(
                                        'Interface available in English and French',
                                    ),
                                ]"
                                :key="point"
                                class="flex items-center gap-2"
                            >
                                <Check class="size-4 text-primary" />
                                {{ point }}
                            </li>
                        </ul>
                        <div class="flex flex-wrap gap-3">
                            <Button variant="outline" as-child>
                                <a
                                    :href="installGuideUrl"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    {{ t('Read the install guide') }}
                                    <ExternalLink class="size-4" />
                                </a>
                            </Button>
                        </div>
                    </div>

                    <div
                        class="overflow-hidden rounded-xl border bg-sidebar text-sidebar-foreground shadow-sm dark:bg-black/30"
                    >
                        <div
                            class="flex items-center justify-between border-b px-4 py-2 text-xs text-muted-foreground"
                        >
                            <span>{{ t('Quick start') }}</span>
                            <button
                                type="button"
                                class="flex items-center gap-1 rounded px-2 py-1 hover:bg-sidebar-accent hover:text-sidebar-accent-foreground"
                                data-test="copy-install-commands"
                                @click="copyCommands"
                            >
                                <Check v-if="copied" class="size-3.5" />
                                <Copy v-else class="size-3.5" />
                                {{ copied ? t('Copied') : t('Copy') }}
                            </button>
                        </div>
                        <pre
                            class="overflow-x-auto p-4 text-sm leading-relaxed"
                        ><code>{{ installCommands }}</code></pre>
                    </div>
                </div>
            </section>

            <section class="border-t bg-muted/30">
                <div
                    class="mx-auto flex max-w-6xl flex-col items-center gap-6 px-4 py-16 text-center sm:px-6"
                >
                    <h2 class="text-3xl font-semibold tracking-tight">
                        {{ t('Start watching your services today.') }}
                    </h2>
                    <div class="flex flex-wrap justify-center gap-3">
                        <Button v-if="canRegister" size="lg" as-child>
                            <Link :href="register()">{{
                                t('Create an account')
                            }}</Link>
                        </Button>
                        <Button size="lg" variant="outline" as-child>
                            <Link :href="login()">{{ t('Log in') }}</Link>
                        </Button>
                    </div>
                </div>
            </section>
        </main>

        <footer class="border-t">
            <div
                class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-3 gap-y-1 px-4 py-6 text-xs text-muted-foreground sm:px-6"
            >
                <span>Veyra</span>
                <span aria-hidden="true">·</span>
                <span>AGPL-3.0</span>
                <span aria-hidden="true">·</span>
                <a
                    :href="repositoryUrl"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="hover:underline"
                    >{{ t('Source code') }}</a
                >
                <template v-for="link in footerLinks" :key="link.id">
                    <span aria-hidden="true">·</span>
                    <Link :href="link.href" class="hover:underline">{{
                        link.title
                    }}</Link>
                </template>
            </div>
        </footer>
    </div>
</template>
