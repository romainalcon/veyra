<script setup lang="ts">
import { Form, Head, setLayoutProps } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/two-factor/login';
import { useTranslations } from '@/lib/i18n';

const { t } = useTranslations();

/**
 * Second login step (ADR-040): a code from the authenticator app, or one of
 * the single-use recovery codes when the device is lost.
 */
const useRecovery = ref(false);

const copy = computed(() =>
    useRecovery.value
        ? {
              title: t('Recovery code'),
              description: t(
                  'Enter one of your emergency recovery codes. Each code works only once.',
              ),
              toggle: t('Use an authentication code instead'),
          }
        : {
              title: t('Authentication code'),
              description: t(
                  'Enter the 6-digit code from your authenticator app.',
              ),
              toggle: t('Use a recovery code instead'),
          },
);

setLayoutProps({
    title: t('Two-factor authentication'),
    description: t('Confirm your identity to finish signing in.'),
});
</script>

<template>
    <Head :title="t('Two-factor authentication')" />

    <Form
        v-bind="store.form()"
        :reset-on-error="['code', 'recovery_code']"
        v-slot="{ errors, processing }"
        class="space-y-6"
    >
        <div class="grid gap-2">
            <Label :for="useRecovery ? 'recovery_code' : 'code'">{{
                copy.title
            }}</Label>
            <p class="text-sm text-muted-foreground">
                {{ copy.description }}
            </p>
            <Input
                v-if="useRecovery"
                id="recovery_code"
                name="recovery_code"
                autocomplete="one-time-code"
                autofocus
                required
                data-test="recovery-code-input"
            />
            <Input
                v-else
                id="code"
                name="code"
                inputmode="numeric"
                pattern="[0-9]*"
                maxlength="6"
                autocomplete="one-time-code"
                autofocus
                required
                data-test="code-input"
            />
            <InputError :message="errors.code ?? errors.recovery_code" />
        </div>

        <Button
            class="w-full"
            :disabled="processing"
            data-test="two-factor-challenge-button"
        >
            <Spinner v-if="processing" />
            {{ t('Continue') }}
        </Button>

        <button
            type="button"
            class="w-full text-center text-sm text-muted-foreground underline-offset-4 hover:underline"
            data-test="toggle-recovery"
            @click="useRecovery = !useRecovery"
        >
            {{ copy.toggle }}
        </button>
    </Form>
</template>
