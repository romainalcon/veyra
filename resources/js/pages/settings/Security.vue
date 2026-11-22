<script setup lang="ts">
import { Form, Head, setLayoutProps } from '@inertiajs/vue3';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/security';
import {
    confirm as confirmTwoFactor,
    disable as disableTwoFactor,
    enable as enableTwoFactor,
    regenerateRecoveryCodes,
} from '@/routes/two-factor';
import { Input } from '@/components/ui/input';
import { useTranslations } from '@/lib/i18n';

const { t } = useTranslations();

type Props = {
    passwordRules: string;
    twoFactor: {
        enabled: boolean;
        pending: boolean;
        qrCodeSvg: string | null;
        setupKey: string | null;
        recoveryCodes: string[] | null;
        requiredBy: string[];
    };
};

const props = defineProps<Props>();

setLayoutProps({
    breadcrumbs: [
        {
            title: t('Security settings'),
            href: edit(),
        },
    ],
});
</script>

<template>
    <Head :title="t('Security settings')" />

    <h1 class="sr-only">{{ t('Security settings') }}</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            :title="t('Update password')"
            :description="
                t(
                    'Ensure your account is using a long, random password to stay secure',
                )
            "
        />

        <Form
            v-bind="SecurityController.update.form()"
            :options="{
                preserveScroll: true,
            }"
            reset-on-success
            :reset-on-error="[
                'password',
                'password_confirmation',
                'current_password',
            ]"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="current_password">{{
                    t('Current password')
                }}</Label>
                <PasswordInput
                    id="current_password"
                    name="current_password"
                    class="mt-1 block w-full"
                    autocomplete="current-password"
                    :placeholder="t('Current password')"
                />
                <InputError :message="errors.current_password" />
            </div>

            <div class="grid gap-2">
                <Label for="password">{{ t('New password') }}</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    class="mt-1 block w-full"
                    autocomplete="new-password"
                    :placeholder="t('New password')"
                    :passwordrules="props.passwordRules"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">{{
                    t('Confirm password')
                }}</Label>
                <PasswordInput
                    id="password_confirmation"
                    name="password_confirmation"
                    class="mt-1 block w-full"
                    autocomplete="new-password"
                    :placeholder="t('Confirm password')"
                    :passwordrules="props.passwordRules"
                />
                <InputError :message="errors.password_confirmation" />
            </div>

            <div class="flex items-center gap-4">
                <Button
                    :disabled="processing"
                    data-test="update-password-button"
                >
                    {{ t('Save') }}
                </Button>
            </div>
        </Form>
        <div class="space-y-6 border-t pt-6" data-test="two-factor-section">
            <Heading
                variant="small"
                :title="t('Two-factor authentication')"
                :description="
                    t(
                        'Ask for a code from an authenticator app at sign-in, in addition to your password.',
                    )
                "
            />

            <p
                v-if="twoFactor.requiredBy.length > 0 && !twoFactor.enabled"
                class="rounded-md border border-status-degraded/30 bg-status-degraded-soft p-3 text-sm text-status-degraded-soft-foreground"
                data-test="two-factor-required"
            >
                {{
                    t('Required by :organizations.', {
                        organizations: twoFactor.requiredBy.join(', '),
                    })
                }}
            </p>

            <template v-if="twoFactor.enabled">
                <p class="text-sm font-medium text-status-up">
                    {{ t('Two-factor authentication is enabled.') }}
                </p>

                <div
                    v-if="twoFactor.recoveryCodes"
                    class="space-y-2 rounded-md border p-4"
                    data-test="recovery-codes"
                >
                    <p class="text-sm">
                        {{
                            t(
                                'Store these recovery codes somewhere safe. Each one signs you in once if you lose your device; they are not shown again.',
                            )
                        }}
                    </p>
                    <ul class="grid gap-1 font-mono text-sm sm:grid-cols-2">
                        <li v-for="code in twoFactor.recoveryCodes" :key="code">
                            {{ code }}
                        </li>
                    </ul>
                </div>

                <div class="flex flex-wrap gap-3">
                    <Form
                        v-bind="regenerateRecoveryCodes.form()"
                        :options="{ preserveScroll: true }"
                        v-slot="{ processing }"
                    >
                        <Button
                            variant="outline"
                            :disabled="processing"
                            data-test="regenerate-recovery-codes"
                        >
                            {{ t('Regenerate recovery codes') }}
                        </Button>
                    </Form>
                    <Form
                        v-bind="disableTwoFactor.form()"
                        :options="{ preserveScroll: true }"
                        v-slot="{ processing }"
                    >
                        <Button
                            variant="destructive"
                            :disabled="processing"
                            data-test="disable-two-factor"
                        >
                            {{ t('Disable two-factor authentication') }}
                        </Button>
                    </Form>
                </div>
                <p
                    v-if="twoFactor.requiredBy.length > 0"
                    class="text-xs text-muted-foreground"
                >
                    {{
                        t(
                            'Disabling it blocks your access to the organizations that require it.',
                        )
                    }}
                </p>
            </template>

            <template v-else-if="twoFactor.pending">
                <p class="text-sm">
                    {{
                        t(
                            'Scan this QR code with your authenticator app, or enter the setup key, then type the code it shows.',
                        )
                    }}
                </p>
                <div class="flex flex-wrap items-start gap-6">
                    <!-- SVG generated server-side by Fortify from the user's own secret. -->
                    <div
                        class="rounded-md bg-white p-2"
                        data-test="two-factor-qr"
                        v-html="twoFactor.qrCodeSvg"
                    />
                    <div class="grid gap-1 text-sm">
                        <span class="text-muted-foreground">{{
                            t('Setup key')
                        }}</span>
                        <code
                            class="font-mono break-all"
                            data-test="two-factor-setup-key"
                            >{{ twoFactor.setupKey }}</code
                        >
                    </div>
                </div>

                <Form
                    v-bind="confirmTwoFactor.form()"
                    :options="{ preserveScroll: true }"
                    error-bag="confirmTwoFactorAuthentication"
                    reset-on-error
                    class="flex flex-wrap items-end gap-3"
                    v-slot="{ errors, processing }"
                >
                    <div class="grid gap-2">
                        <Label for="two-factor-code">{{
                            t('Authentication code')
                        }}</Label>
                        <Input
                            id="two-factor-code"
                            name="code"
                            inputmode="numeric"
                            pattern="[0-9]*"
                            maxlength="6"
                            autocomplete="one-time-code"
                            required
                            class="w-40"
                        />
                        <InputError :message="errors.code" />
                    </div>
                    <Button
                        :disabled="processing"
                        data-test="confirm-two-factor"
                    >
                        {{ t('Confirm') }}
                    </Button>
                </Form>
            </template>

            <Form
                v-else
                v-bind="enableTwoFactor.form()"
                :options="{ preserveScroll: true }"
                v-slot="{ processing }"
            >
                <Button :disabled="processing" data-test="enable-two-factor">
                    {{ t('Enable two-factor authentication') }}
                </Button>
            </Form>
        </div>
    </div>
</template>
