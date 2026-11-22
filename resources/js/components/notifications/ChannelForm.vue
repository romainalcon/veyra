<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { RouteFormDefinition } from '@/wayfinder';
import type { ChannelType, NotificationChannelSummary } from '@/types';
import { useTranslations } from '@/lib/i18n';

const { t } = useTranslations();

/**
 * Secrets (webhook URLs) are never sent back to the browser: when editing a
 * Discord or webhook channel the field stays empty and only replaces the
 * stored URL when filled in.
 */
const props = defineProps<{
    action: RouteFormDefinition<'post'>;
    types: { value: ChannelType; label: string }[];
    channel?: NotificationChannelSummary;
    maxRecipients: number;
}>();

const emit = defineEmits<{ saved: [] }>();

const type = ref<ChannelType>(props.channel?.type ?? 'email');
const recipients = ref((props.channel?.recipients ?? []).join(', '));
const isEdit = computed(() => props.channel !== undefined);
const isWebhook = computed(() => type.value === 'webhook');

const urlTypes: ChannelType[] = [
    'discord',
    'slack',
    'webhook',
    'teams',
    'ntfy',
    'gotify',
];
const tokenTypes: ChannelType[] = ['ntfy', 'gotify', 'telegram', 'pagerduty'];
const hasUrl = computed(() => urlTypes.includes(type.value));
const hasToken = computed(() => tokenTypes.includes(type.value));
const hasChat = computed(() => type.value === 'telegram');

/**
 * Label and example of the URL field. Labels stay literal `t()` calls so the
 * translation coverage test sees them.
 */
const urlField = computed(() => {
    switch (type.value) {
        case 'discord':
            return {
                label: t('Discord webhook URL'),
                placeholder: 'https://discord.com/api/webhooks/…',
            };
        case 'slack':
            return {
                label: t('Slack webhook URL'),
                placeholder: 'https://hooks.slack.com/services/…',
            };
        case 'teams':
            return {
                label: t('Teams workflow URL'),
                placeholder: 'https://….logic.azure.com/workflows/…',
            };
        case 'ntfy':
            return {
                label: t('ntfy topic URL'),
                placeholder: 'https://ntfy.sh/my-alerts',
            };
        case 'gotify':
            return {
                label: t('Gotify server URL'),
                placeholder: 'https://gotify.example.com',
            };
        default:
            return {
                label: t('Endpoint URL'),
                placeholder: 'https://hooks.example.com/veyra',
            };
    }
});

/**
 * Tokens are secrets like webhook URLs: never pre-filled when editing.
 */
const tokenField = computed(() => {
    switch (type.value) {
        case 'ntfy':
            return {
                label: t('Access token (optional)'),
                placeholder: 'tk_…',
            };
        case 'gotify':
            return {
                label: t('Application token'),
                placeholder: 'A…',
            };
        case 'telegram':
            return {
                label: t('Bot token'),
                placeholder: '123456:ABC…',
            };
        default:
            return {
                label: t('Integration key'),
                placeholder: t('32-character Events API v2 key'),
            };
    }
});
</script>

<template>
    <Form
        v-bind="action"
        :options="{ preserveScroll: true }"
        class="space-y-4"
        v-slot="{ errors, processing }"
        @success="emit('saved')"
    >
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label :for="`name-${channel?.id ?? 'new'}`">{{
                    t('Name')
                }}</Label>
                <Input
                    :id="`name-${channel?.id ?? 'new'}`"
                    name="name"
                    required
                    maxlength="100"
                    :default-value="channel?.name"
                    :placeholder="t('On-call team')"
                />
                <InputError :message="errors.name" />
            </div>
            <div v-if="!isEdit" class="grid gap-2">
                <Label for="type">{{ t('Type') }}</Label>
                <NativeSelect
                    id="type"
                    name="type"
                    v-model="type"
                    :options="types"
                />
                <InputError :message="errors.type" />
            </div>
        </div>

        <div v-if="type === 'email'" class="grid gap-2">
            <Label :for="`recipients-${channel?.id ?? 'new'}`">{{
                t('Recipients')
            }}</Label>
            <Input
                :id="`recipients-${channel?.id ?? 'new'}`"
                name="recipients"
                v-model="recipients"
                placeholder="ops@example.com, oncall@example.com"
            />
            <p class="text-xs text-muted-foreground">
                {{
                    t('Up to :max addresses, separated by commas.', {
                        max: maxRecipients,
                    })
                }}
            </p>
            <InputError :message="errors.recipients" />
            <InputError :message="errors['recipients.0']" />
        </div>

        <div v-if="hasUrl" class="grid gap-2">
            <Label :for="`webhook-${channel?.id ?? 'new'}`">{{
                urlField.label
            }}</Label>
            <Input
                :id="`webhook-${channel?.id ?? 'new'}`"
                name="webhook_url"
                type="password"
                autocomplete="off"
                :placeholder="
                    isEdit
                        ? t('Leave empty to keep the current webhook')
                        : urlField.placeholder
                "
            />
            <p
                v-if="isWebhook && !isEdit"
                class="text-xs text-muted-foreground"
                data-test="webhook-hint"
            >
                {{
                    t(
                        'Receives a signed JSON POST for each notification. A signing secret is shown once the channel is created.',
                    )
                }}
            </p>
            <InputError :message="errors.webhook_url" />
        </div>

        <div v-if="hasToken" class="grid gap-2">
            <Label :for="`token-${channel?.id ?? 'new'}`">{{
                tokenField.label
            }}</Label>
            <Input
                :id="`token-${channel?.id ?? 'new'}`"
                name="token"
                type="password"
                autocomplete="off"
                :placeholder="
                    isEdit
                        ? t('Leave empty to keep the current token')
                        : tokenField.placeholder
                "
            />
            <p
                v-if="type === 'telegram' && !isEdit"
                class="text-xs text-muted-foreground"
                data-test="telegram-hint"
            >
                {{
                    t(
                        'Create a bot with @BotFather, add it to the chat, then enter the chat id.',
                    )
                }}
            </p>
            <InputError :message="errors.token" />
        </div>

        <div v-if="hasChat" class="grid gap-2">
            <Label :for="`chat-${channel?.id ?? 'new'}`">{{
                t('Chat id')
            }}</Label>
            <Input
                :id="`chat-${channel?.id ?? 'new'}`"
                name="chat_id"
                autocomplete="off"
                :placeholder="
                    isEdit
                        ? t('Leave empty to keep the current chat')
                        : '-1001234567890'
                "
            />
            <InputError :message="errors.chat_id" />
        </div>

        <p
            v-if="isEdit && type !== 'email'"
            class="text-xs text-muted-foreground"
        >
            {{ t('Current: :summary', { summary: channel?.summary }) }}
        </p>

        <div class="flex flex-wrap gap-6 text-sm">
            <label class="flex items-center gap-2">
                <input type="hidden" name="enabled" value="0" />
                <input
                    type="checkbox"
                    name="enabled"
                    value="1"
                    :checked="channel?.enabled ?? true"
                    class="size-4 accent-primary"
                />
                {{ t('Enabled') }}
            </label>
            <label class="flex items-center gap-2">
                <input type="hidden" name="is_default" value="0" />
                <input
                    type="checkbox"
                    name="is_default"
                    value="1"
                    :checked="channel?.is_default ?? false"
                    class="size-4 accent-primary"
                />
                {{ t('Select by default for new monitors') }}
            </label>
        </div>

        <Button :disabled="processing" data-test="save-channel-button">
            {{ isEdit ? t('Save channel') : t('Add channel') }}
        </Button>
    </Form>
</template>
