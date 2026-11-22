<script setup lang="ts">
import { Check, Copy } from '@lucide/vue';
import { ref } from 'vue';
import LimitNotice from '@/components/LimitNotice.vue';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/lib/i18n';
import type { StatusPageAudience } from '@/types';

const props = defineProps<{
    audience: StatusPageAudience;
    published: boolean;
    subscriptionsEnabled: boolean;
}>();

const { t, tc } = useTranslations();
const copied = ref<string | null>(null);

const snippets = () =>
    props.audience.badges.map((badge) => ({
        name: badge.name,
        status: badge.status,
        markdown: `[![${badge.name}](${badge.status})](${props.audience.public_base})`,
        html: `<a href="${props.audience.public_base}"><img src="${badge.status}" alt="${badge.name.replace(/"/g, '&quot;')}"></a>`,
        uptime: badge.uptime,
    }));

const copy = async (key: string, value: string) => {
    try {
        await navigator.clipboard.writeText(value);
        copied.value = key;
        setTimeout(() => {
            if (copied.value === key) {
                copied.value = null;
            }
        }, 2000);
    } catch {
        copied.value = null;
    }
};
</script>

<template>
    <section
        class="space-y-5 rounded-lg border p-4"
        data-test="audience-settings"
    >
        <div>
            <h2 class="text-base font-medium">{{ t('Audience') }}</h2>
            <p class="text-sm text-muted-foreground">
                {{
                    t(
                        'Subscribers, feeds and badges. Only published components and public updates are ever shared.',
                    )
                }}
            </p>
        </div>

        <div class="space-y-2">
            <h3 class="text-sm font-medium">{{ t('E-mail subscribers') }}</h3>
            <p class="text-sm" data-test="subscriber-counts">
                {{
                    tc(
                        ':count confirmed subscriber|:count confirmed subscribers',
                        audience.subscribers.confirmed,
                    )
                }}
                ·
                {{
                    tc(
                        ':count awaiting confirmation|:count awaiting confirmation',
                        audience.subscribers.pending,
                    )
                }}
            </p>
            <p class="text-xs text-muted-foreground">
                {{
                    subscriptionsEnabled
                        ? t(
                              'Subscribers receive incident openings, public updates, resolutions and planned maintenance. Their addresses are never shown.',
                          )
                        : t(
                              'Subscriptions are off: enable them in the form above to show the sign-up form on the public page.',
                          )
                }}
            </p>
            <LimitNotice
                feature="status_page_subscribers"
                :resource="t('status page subscribers')"
                :hint="
                    t(
                        'New visitors cannot subscribe until the limit is raised.',
                    )
                "
            />
        </div>

        <div class="space-y-2">
            <h3 class="text-sm font-medium">{{ t('Feeds') }}</h3>
            <ul class="space-y-1 font-mono text-xs break-all">
                <li>Atom · {{ audience.feeds.atom }}</li>
                <li>RSS · {{ audience.feeds.rss }}</li>
            </ul>
        </div>

        <div class="space-y-2">
            <h3 class="text-sm font-medium">{{ t('Badges') }}</h3>
            <p v-if="!published" class="text-xs text-muted-foreground">
                {{
                    t('Badges and feeds are served once the page is published.')
                }}
            </p>
            <p
                v-if="audience.badges.length === 0"
                class="rounded-lg border border-dashed p-4 text-center text-sm text-muted-foreground"
            >
                {{ t('Add components to get their badges.') }}
            </p>
            <ul v-else class="divide-y rounded-lg border">
                <li
                    v-for="(badge, index) in snippets()"
                    :key="badge.status"
                    class="space-y-2 p-3"
                    data-test="badge-snippet"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="flex-1 text-sm font-medium">{{
                            badge.name
                        }}</span>
                        <img
                            v-if="published"
                            :src="badge.status"
                            :alt="badge.name"
                            height="20"
                        />
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="copy(`md-${index}`, badge.markdown)"
                        >
                            <component
                                :is="copied === `md-${index}` ? Check : Copy"
                                class="size-4"
                            />
                            {{ t('Copy Markdown') }}
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="copy(`html-${index}`, badge.html)"
                        >
                            <component
                                :is="copied === `html-${index}` ? Check : Copy"
                                class="size-4"
                            />
                            {{ t('Copy HTML') }}
                        </Button>
                    </div>
                    <p
                        v-if="badge.uptime"
                        class="font-mono text-xs break-all text-muted-foreground"
                    >
                        {{ badge.uptime }}
                    </p>
                </li>
            </ul>
            <p class="text-xs text-muted-foreground">
                {{
                    t(
                        'Uptime badges also exist for 24h and 7d: replace uptime-30d in the address.',
                    )
                }}
            </p>
        </div>
    </section>
</template>
