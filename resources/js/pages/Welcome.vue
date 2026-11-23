<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    Activity,
    ArrowRight,
    BellRing,
    Check,
    CircleCheck,
    Copy,
    ExternalLink,
    Globe,
    HeartPulse,
    Languages,
    Radar,
    ShieldCheck,
    Terminal,
    Users,
    Wrench,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import AppearanceToggle from '@/components/AppearanceToggle.vue';
import AppLogoMark from '@/components/AppLogoMark.vue';
import UptimeBars from '@/components/charts/UptimeBars.vue';
import StatusBadge from '@/components/monitors/StatusBadge.vue';
import SiteNavMenu from '@/components/SiteNavMenu.vue';
import type { SiteNavItem } from '@/components/SiteNavMenu.vue';
import { Button } from '@/components/ui/button';
import { usePublicNavigation } from '@/composables/usePublicNavigation';
import { formatUptime } from '@/lib/analytics';
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

/*
 * The first two features are wide bento tiles; the rest fill the grid.
 */
const features = computed(() => [
    {
        icon: HeartPulse,
        title: t('No false alarms'),
        text: t(
            'An outage is confirmed after 3 failed checks and recovery after 2 successes. Missing data is shown as unknown, never as downtime.',
        ),
        wide: true,
    },
    {
        icon: Globe,
        title: t('Public status pages'),
        text: t(
            'Share uptime, incidents and planned maintenance on your own domain, without exposing internal details.',
        ),
        wide: false,
    },
    {
        icon: Activity,
        title: t('HTTP, TCP and heartbeats'),
        text: t(
            'Check websites and APIs, open ports, or let your cron jobs and workers ping a secret URL.',
        ),
        wide: false,
    },
    {
        icon: BellRing,
        title: t('Alerts that arrive'),
        text: t(
            'E-mail, Slack, Discord and signed webhooks, with retries, a reminder when nobody acknowledges, and a full delivery history.',
        ),
        wide: true,
    },
    {
        icon: Wrench,
        title: t('Planned maintenance'),
        text: t(
            'Checks keep running during maintenance windows, without incidents, notifications or impact on uptime.',
        ),
        wide: false,
    },
    {
        icon: ShieldCheck,
        title: t('TLS certificates'),
        text: t(
            'Get warned 14, 7 and 1 days before a certificate expires, once per certificate.',
        ),
        wide: false,
    },
    {
        icon: Users,
        title: t('Built for teams'),
        text: t(
            'Organizations, roles and invitations, with every resource isolated per organization.',
        ),
        wide: true,
    },
]);

const steps = computed(() => [
    {
        icon: Radar,
        title: t('Probe'),
        text: t(
            'Your own workers probe each endpoint on schedule, with SSRF protections on every request.',
        ),
    },
    {
        icon: HeartPulse,
        title: t('Confirm'),
        text: t(
            'One failed check is not an outage: Veyra waits for 3 in a row before opening an incident.',
        ),
    },
    {
        icon: BellRing,
        title: t('Alert and inform'),
        text: t(
            'Your team is notified once, and your status page tells your users what is going on.',
        ),
    },
]);

const stats = computed(() => [
    { value: '3', label: t('failed checks to confirm an outage') },
    { value: '2', label: t('successes to confirm recovery') },
    { value: '0', label: t('external agents to install') },
    { value: formatUptime(100), label: t('open source, AGPL-3.0') },
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
    {
        name: string;
        target: string;
        status: MonitorStatus;
        seed: number;
        latency: string;
    }[]
>(() => [
    {
        name: t('Website'),
        target: 'https://acme.example',
        status: 'up',
        seed: 1,
        latency: '142 ms',
    },
    {
        name: 'API',
        target: 'https://api.acme.example',
        status: 'up',
        seed: 5,
        latency: '87 ms',
    },
    {
        name: t('Nightly backup'),
        target: t('Daily cron job'),
        status: 'up',
        seed: 11,
        latency: '—',
    },
    {
        name: t('Mail server'),
        target: 'mail.acme.example:587',
        status: 'up',
        seed: 17,
        latency: '31 ms',
    },
]);

const repositoryUrl = computed(
    () => props.sourceUrl ?? 'https://github.com/romainalcon/veyra',
);
/*
 * Every header link, for the compact menu shown when they do not fit inline.
 */
const menuItems = computed<SiteNavItem[]>(() => [
    { label: t('Features'), href: '#features', kind: 'anchor' },
    { label: t('How it works'), href: '#how-it-works', kind: 'anchor' },
    { label: t('Self-hosting'), href: '#self-hosting', kind: 'anchor' },
    ...headerLinks.value.map((link): SiteNavItem => ({
        label: link.title,
        href: link.href,
        kind: 'page',
    })),
    { label: 'GitHub', href: repositoryUrl.value, kind: 'external' },
]);
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

    <div class="min-h-svh overflow-x-clip bg-background text-foreground">
        <header
            class="sticky top-0 z-30 border-b bg-background/90 backdrop-blur"
        >
            <nav
                class="mx-auto flex h-16 max-w-6xl items-center gap-4 px-4 sm:px-6"
                :aria-label="t('Main navigation')"
            >
                <Link
                    href="/"
                    class="flex items-center gap-2 text-lg font-semibold tracking-tight"
                >
                    <AppLogoMark class="size-8" />
                    Veyra
                </Link>

                <div
                    class="ml-6 hidden items-center gap-5 text-sm whitespace-nowrap text-muted-foreground xl:flex"
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
                    <div class="xl:hidden">
                        <SiteNavMenu :items="menuItems" />
                    </div>
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
            <!-- Hero -->
            <section>
                <div
                    class="mx-auto flex max-w-6xl flex-col gap-7 px-4 pt-16 pb-12 sm:px-6 sm:pt-24"
                >
                    <a
                        :href="repositoryUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex w-fit items-center gap-2 font-mono text-xs text-muted-foreground hover:text-foreground"
                    >
                        <span
                            class="size-2 rounded-full bg-status-up"
                            aria-hidden="true"
                        />
                        {{ t('Open source · AGPL-3.0') }}
                    </a>

                    <h1
                        class="max-w-4xl text-4xl font-semibold tracking-tight text-balance sm:text-6xl"
                    >
                        {{ t('Know your services are down') }}
                        <span class="text-muted-foreground">{{
                            t('before your users do.')
                        }}</span>
                    </h1>

                    <p
                        class="max-w-2xl text-lg text-pretty text-muted-foreground"
                    >
                        {{
                            t(
                                'Veyra watches your websites, APIs, servers and scheduled jobs, confirms real outages, alerts your team and keeps your users informed with public status pages.',
                            )
                        }}
                    </p>

                    <div class="flex flex-wrap items-center gap-3">
                        <Button v-if="canRegister" size="lg" as-child>
                            <Link :href="register()">
                                {{ t('Start monitoring') }}
                                <ArrowRight class="size-4" />
                            </Link>
                        </Button>
                        <Button v-else size="lg" as-child>
                            <Link :href="login()">{{ t('Log in') }}</Link>
                        </Button>
                        <Button size="lg" variant="outline" as-child>
                            <a href="#self-hosting">
                                <Terminal class="size-4" />
                                {{ t('Host it yourself') }}
                            </a>
                        </Button>
                    </div>

                    <ul
                        class="flex flex-wrap gap-x-6 gap-y-2 text-sm text-muted-foreground"
                    >
                        <li
                            v-for="point in [
                                t('Free and open source'),
                                t('Docker Compose in minutes'),
                                t('English and French'),
                            ]"
                            :key="point"
                            class="flex items-center gap-1.5"
                        >
                            <Check class="size-4 text-primary" />
                            {{ point }}
                        </li>
                    </ul>
                </div>

                <!-- A flat trace with a single beat: the last check came back. -->
                <div
                    class="mx-auto flex max-w-6xl items-center px-4 sm:px-6"
                    aria-hidden="true"
                >
                    <span class="h-px flex-1 bg-border" />
                    <svg
                        viewBox="0 0 80 40"
                        class="h-10 w-20 shrink-0 text-primary"
                        fill="none"
                    >
                        <path
                            d="M0 20h22l5-12 8 26 7-22 4 8h34"
                            stroke="currentColor"
                            stroke-width="1.75"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                    </svg>
                    <span class="h-px w-16 bg-border sm:w-40" />
                </div>

                <!-- Product preview -->
                <div class="relative mx-auto max-w-6xl px-4 pt-6 pb-20 sm:px-6">
                    <figure
                        class="overflow-hidden rounded-xl border bg-card shadow-sm"
                        :aria-label="t('Example of a Veyra dashboard')"
                    >
                        <div
                            class="flex items-center gap-2 border-b bg-muted/50 px-4 py-2.5"
                            aria-hidden="true"
                        >
                            <span class="size-2.5 rounded-full bg-border" />
                            <span class="size-2.5 rounded-full bg-border" />
                            <span class="size-2.5 rounded-full bg-border" />
                            <span
                                class="mx-auto rounded-md border bg-background px-3 py-0.5 font-mono text-xs text-muted-foreground"
                                >veyra.acme.example</span
                            >
                        </div>

                        <div
                            class="grid gap-6 p-4 sm:p-6 lg:grid-cols-[1fr_16rem]"
                        >
                            <div class="min-w-0">
                                <div
                                    class="mb-4 flex items-center gap-2 rounded-lg border border-status-up/30 bg-status-up-soft p-3 text-sm font-medium text-status-up-soft-foreground"
                                >
                                    <CircleCheck class="size-4" />
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
                                                <p
                                                    class="truncate text-sm font-medium"
                                                >
                                                    {{ monitor.name }}
                                                </p>
                                                <p
                                                    class="truncate font-mono text-xs text-muted-foreground"
                                                >
                                                    {{ monitor.target }}
                                                </p>
                                            </div>
                                            <span
                                                class="hidden font-mono text-xs text-muted-foreground tabular-nums sm:inline"
                                                >{{ monitor.latency }}</span
                                            >
                                            <StatusBadge
                                                :status="monitor.status"
                                            />
                                        </div>
                                        <UptimeBars
                                            :days="demoDays(monitor.seed)"
                                        />
                                    </li>
                                </ul>
                            </div>

                            <div
                                class="hidden flex-col gap-4 lg:flex"
                                aria-hidden="true"
                            >
                                <div class="rounded-xl border p-4">
                                    <p class="text-xs text-muted-foreground">
                                        {{ t('Uptime (30 days)') }}
                                    </p>
                                    <p
                                        class="mt-1 text-3xl font-semibold tracking-tight tabular-nums"
                                    >
                                        {{ formatUptime(99.98) }}
                                    </p>
                                </div>
                                <div class="rounded-xl border p-4">
                                    <p class="text-xs text-muted-foreground">
                                        {{ t('Average latency') }}
                                    </p>
                                    <svg
                                        viewBox="0 0 200 60"
                                        class="mt-2 h-14 w-full text-primary"
                                        fill="none"
                                    >
                                        <path
                                            d="M0 42 L20 38 L40 40 L60 30 L80 34 L100 22 L120 28 L140 18 L160 24 L180 14 L200 20 V60 H0 Z"
                                            class="fill-primary/10"
                                        />
                                        <path
                                            d="M0 42 L20 38 L40 40 L60 30 L80 34 L100 22 L120 28 L140 18 L160 24 L180 14 L200 20"
                                            stroke="currentColor"
                                            stroke-width="2"
                                            stroke-linejoin="round"
                                        />
                                    </svg>
                                </div>
                            </div>
                        </div>
                    </figure>

                    <div
                        class="absolute right-8 -bottom-2 hidden w-72 rounded-lg border bg-popover p-4 text-left shadow-md sm:block"
                        aria-hidden="true"
                    >
                        <div class="flex items-start gap-3">
                            <span
                                class="flex size-8 shrink-0 items-center justify-center rounded-full bg-status-up-soft text-status-up-soft-foreground"
                            >
                                <BellRing class="size-4" />
                            </span>
                            <div class="min-w-0">
                                <p class="text-sm font-medium">
                                    {{ t('API is back up') }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{
                                        t(
                                            'Resolved after 4 min · Slack, e-mail',
                                        )
                                    }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Key figures -->
            <section class="border-y bg-muted/30">
                <dl
                    class="mx-auto grid max-w-6xl grid-cols-2 gap-px px-4 sm:px-6 lg:grid-cols-4"
                >
                    <div
                        v-for="stat in stats"
                        :key="stat.label"
                        class="flex flex-col gap-1 px-2 py-8 text-center"
                    >
                        <dt class="order-2 text-sm text-muted-foreground">
                            {{ stat.label }}
                        </dt>
                        <dd
                            class="order-1 text-4xl font-bold tracking-tight text-primary tabular-nums"
                        >
                            {{ stat.value }}
                        </dd>
                    </div>
                </dl>
            </section>

            <!-- How it works -->
            <section id="how-it-works" class="scroll-mt-16">
                <div class="mx-auto max-w-6xl px-4 py-20 sm:px-6 lg:py-28">
                    <div
                        class="mx-auto flex max-w-2xl flex-col items-center gap-3 text-center"
                    >
                        <p
                            class="text-sm font-semibold tracking-wide text-primary uppercase"
                        >
                            {{ t('How it works') }}
                        </p>
                        <h2
                            class="text-3xl font-bold tracking-tight sm:text-4xl"
                        >
                            {{ t('Real outages, not noise') }}
                        </h2>
                    </div>
                    <ol class="relative mt-14 grid gap-10 md:grid-cols-3">
                        <div
                            class="absolute top-6 right-[16%] left-[16%] hidden h-px bg-border md:block"
                            aria-hidden="true"
                        />
                        <li
                            v-for="(step, index) in steps"
                            :key="step.title"
                            class="relative flex flex-col items-center gap-3 text-center"
                        >
                            <span
                                class="relative flex size-12 items-center justify-center rounded-xl border bg-card text-primary"
                            >
                                <component :is="step.icon" class="size-5" />
                                <span
                                    class="absolute -top-2 -right-2 flex size-5 items-center justify-center rounded-full bg-primary text-[11px] font-semibold text-primary-foreground"
                                    >{{ index + 1 }}</span
                                >
                            </span>
                            <h3 class="text-lg font-semibold">
                                {{ step.title }}
                            </h3>
                            <p class="max-w-xs text-sm text-muted-foreground">
                                {{ step.text }}
                            </p>
                        </li>
                    </ol>
                </div>
            </section>

            <!-- Features -->
            <section id="features" class="scroll-mt-16 border-t bg-muted/30">
                <div class="mx-auto max-w-6xl px-4 py-20 sm:px-6 lg:py-28">
                    <div class="flex max-w-2xl flex-col gap-3">
                        <p
                            class="text-sm font-semibold tracking-wide text-primary uppercase"
                        >
                            {{ t('Features') }}
                        </p>
                        <h2
                            class="text-3xl font-bold tracking-tight sm:text-4xl"
                        >
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
                    <div class="mt-12 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <article
                            v-for="feature in features"
                            :key="feature.title"
                            :class="[
                                'flex flex-col gap-3 rounded-xl border bg-card p-6',
                                feature.wide && 'lg:col-span-2',
                            ]"
                        >
                            <span
                                class="flex size-10 items-center justify-center rounded-lg bg-primary/10 text-primary"
                            >
                                <component :is="feature.icon" class="size-5" />
                            </span>
                            <h3 class="font-semibold">{{ feature.title }}</h3>
                            <p class="text-sm text-muted-foreground">
                                {{ feature.text }}
                            </p>
                        </article>
                        <article
                            class="flex flex-col justify-between gap-3 rounded-xl border border-dashed p-6 lg:col-span-2"
                        >
                            <Languages class="size-5 text-primary" />
                            <p class="text-sm text-muted-foreground">
                                {{
                                    t(
                                        'Interface, e-mails and status pages in English and French.',
                                    )
                                }}
                            </p>
                        </article>
                    </div>
                </div>
            </section>

            <!-- Self-hosting -->
            <section id="self-hosting" class="scroll-mt-16 border-t">
                <div
                    class="mx-auto grid max-w-6xl items-center gap-12 px-4 py-20 sm:px-6 lg:grid-cols-2 lg:py-28"
                >
                    <div class="flex flex-col gap-4">
                        <p
                            class="text-sm font-semibold tracking-wide text-primary uppercase"
                        >
                            {{ t('Self-hosting') }}
                        </p>
                        <h2
                            class="text-3xl font-bold tracking-tight sm:text-4xl"
                        >
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
                                <span
                                    class="flex size-5 items-center justify-center rounded-full bg-primary/10 text-primary"
                                >
                                    <Check class="size-3.5" />
                                </span>
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

                    <div class="relative">
                        <div
                            class="overflow-hidden rounded-xl border border-white/10 bg-[oklch(0.17_0.02_265)] text-[oklch(0.92_0.01_265)]"
                        >
                            <div
                                class="flex items-center justify-between border-b border-white/10 px-4 py-2.5 text-xs text-[oklch(0.72_0.03_265)]"
                            >
                                <span class="flex items-center gap-2">
                                    <Terminal class="size-3.5" />
                                    {{ t('Quick start') }}
                                </span>
                                <button
                                    type="button"
                                    class="flex items-center gap-1 rounded px-2 py-1 hover:bg-white/10 hover:text-white"
                                    data-test="copy-install-commands"
                                    @click="copyCommands"
                                >
                                    <Check v-if="copied" class="size-3.5" />
                                    <Copy v-else class="size-3.5" />
                                    {{ copied ? t('Copied') : t('Copy') }}
                                </button>
                            </div>
                            <pre
                                class="overflow-x-auto p-5 text-sm leading-relaxed"
                            ><code><template v-for="(line, index) in installCommands.split('\n')" :key="index"><span class="text-[oklch(0.72_0.14_275)] select-none">$ </span>{{ line }}
</template></code></pre>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Call to action -->
            <section class="px-4 pb-20 sm:px-6">
                <div
                    class="mx-auto max-w-6xl rounded-2xl bg-primary px-6 py-16 text-center text-primary-foreground sm:px-16"
                >
                    <h2
                        class="mx-auto max-w-2xl text-3xl font-bold tracking-tight text-balance sm:text-4xl"
                    >
                        {{ t('Start watching your services today.') }}
                    </h2>
                    <p class="mx-auto mt-3 max-w-xl opacity-85">
                        {{
                            t(
                                'Set up your first monitor in under a minute, or run Veyra on your own servers.',
                            )
                        }}
                    </p>
                    <div class="mt-8 flex flex-wrap justify-center gap-3">
                        <Button
                            v-if="canRegister"
                            size="lg"
                            variant="secondary"
                            class="h-11 px-6"
                            as-child
                        >
                            <Link :href="register()">
                                {{ t('Create an account') }}
                                <ArrowRight class="size-4" />
                            </Link>
                        </Button>
                        <Button
                            size="lg"
                            variant="ghost"
                            class="h-11 px-6 text-primary-foreground hover:bg-primary-foreground/10 hover:text-primary-foreground"
                            as-child
                        >
                            <Link :href="login()">{{ t('Log in') }}</Link>
                        </Button>
                    </div>
                </div>
            </section>
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
            </div>
        </footer>
    </div>
</template>
