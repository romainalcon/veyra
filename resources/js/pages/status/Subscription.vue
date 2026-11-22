<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { CheckCircle2, CircleAlert, MailCheck, MailX } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTranslations } from '@/lib/i18n';
import type { SubscriptionPageState } from '@/types';

const props = defineProps<{
    state: SubscriptionPageState;
    page: { title: string; url: string } | null;
    actionUrl: string | null;
}>();

const { t } = useTranslations();
const processing = ref(false);

const content = computed(() => {
    const title = props.page?.title ?? '';

    switch (props.state) {
        case 'confirm':
            return {
                icon: MailCheck,
                heading: t('Confirm your subscription'),
                text: t('You will receive updates from :page by e-mail.', {
                    page: title,
                }),
                action: t('Confirm my subscription'),
            };
        case 'confirmed':
            return {
                icon: CheckCircle2,
                heading: t('Subscription confirmed'),
                text: t('You will receive updates from :page by e-mail.', {
                    page: title,
                }),
                action: null,
            };
        case 'unsubscribe':
            return {
                icon: MailX,
                heading: t('Unsubscribe'),
                text: t('You will no longer receive updates from :page.', {
                    page: title,
                }),
                action: t('Unsubscribe'),
            };
        case 'unsubscribed':
            return {
                icon: CheckCircle2,
                heading: t('You are unsubscribed'),
                text: t('No more e-mails will be sent to this address.'),
                action: null,
            };
        default:
            return {
                icon: CircleAlert,
                heading: t('This link is invalid or has expired'),
                text: t(
                    'Subscribe again from the status page to receive a new link.',
                ),
                action: null,
            };
    }
});

const submit = () => {
    if (!props.actionUrl) {
        return;
    }

    router.post(
        props.actionUrl,
        {},
        {
            onStart: () => (processing.value = true),
            onFinish: () => (processing.value = false),
        },
    );
};
</script>

<template>
    <Head :title="content.heading" />

    <div
        class="mx-auto max-w-md space-y-4 rounded-lg border p-6 text-center"
        :data-state="state"
        data-test="subscription-page"
    >
        <component
            :is="content.icon"
            class="mx-auto size-8 text-muted-foreground"
            aria-hidden="true"
        />
        <h1 class="text-xl font-semibold tracking-tight">
            {{ content.heading }}
        </h1>
        <p class="text-sm text-muted-foreground">{{ content.text }}</p>
        <Button
            v-if="content.action && actionUrl"
            :disabled="processing"
            data-test="subscription-action"
            @click="submit"
        >
            <Spinner v-if="processing" />
            {{ content.action }}
        </Button>
        <p v-if="page" class="text-sm">
            <a :href="page.url" class="underline-offset-4 hover:underline">{{
                page.title
            }}</a>
        </p>
    </div>
</template>
