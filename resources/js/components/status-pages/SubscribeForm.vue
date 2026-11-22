<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { BellRing, CheckCircle2 } from '@lucide/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslations } from '@/lib/i18n';

const props = defineProps<{
    action: string;
    /** Accent colours of the page, when branded. */
    buttonStyle?: Record<string, string>;
}>();

const { t } = useTranslations();

/*
 * The page is sessionless (no cookies, no CSRF token): the subscription is a
 * plain JSON request. `website` is a honeypot that people never fill in.
 */
const form = useHttp({ email: '', website: '' });
const message = ref<string | null>(null);
const failure = ref<string | null>(null);

const submit = async () => {
    message.value = null;
    failure.value = null;

    try {
        const response = (await form.post(props.action)) as {
            message?: string;
        } | null;

        if (response?.message) {
            message.value = response.message;
            form.reset();
        }
    } catch {
        failure.value = t(
            'The request could not be sent. Please try again later.',
        );
    }
};
</script>

<template>
    <section class="space-y-3 rounded-lg border p-4" data-test="subscribe-form">
        <h2 class="flex items-center gap-2 text-base font-medium">
            <BellRing class="text-accent-text size-4" aria-hidden="true" />
            {{ t('Get updates by e-mail') }}
        </h2>
        <p
            v-if="message"
            class="flex items-center gap-2 text-sm text-success"
            role="status"
            data-test="subscribe-success"
        >
            <CheckCircle2 class="size-4 shrink-0" aria-hidden="true" />
            {{ message }}
        </p>
        <form v-else class="space-y-2" novalidate @submit.prevent="submit">
            <Label for="subscribe-email" class="sr-only">{{
                t('E-mail address')
            }}</Label>
            <div class="flex flex-col gap-2 sm:flex-row">
                <Input
                    id="subscribe-email"
                    v-model="form.email"
                    type="email"
                    autocomplete="email"
                    required
                    maxlength="254"
                    :placeholder="t('you@example.com')"
                    :aria-invalid="form.errors.email ? true : undefined"
                    class="flex-1"
                />
                <div class="hidden" aria-hidden="true">
                    <label for="subscribe-website">Website</label>
                    <input
                        id="subscribe-website"
                        v-model="form.website"
                        type="text"
                        tabindex="-1"
                        autocomplete="off"
                    />
                </div>
                <Button
                    type="submit"
                    :style="buttonStyle"
                    :disabled="form.processing"
                    data-test="subscribe-button"
                >
                    <Spinner v-if="form.processing" />
                    {{ t('Subscribe') }}
                </Button>
            </div>
            <InputError :message="form.errors.email ?? failure ?? undefined" />
            <p class="text-xs text-muted-foreground">
                {{
                    t(
                        'We will send a confirmation link first. Every e-mail has a one-click unsubscribe link.',
                    )
                }}
            </p>
        </form>
    </section>
</template>
