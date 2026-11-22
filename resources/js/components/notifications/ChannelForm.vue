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
const webhookPlaceholder = computed(() =>
    isWebhook.value
        ? 'https://hooks.example.com/veyra'
        : 'https://discord.com/api/webhooks/…',
);
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

        <div v-else class="grid gap-2">
            <Label :for="`webhook-${channel?.id ?? 'new'}`">{{
                isWebhook ? t('Endpoint URL') : t('Discord webhook URL')
            }}</Label>
            <Input
                :id="`webhook-${channel?.id ?? 'new'}`"
                name="webhook_url"
                type="password"
                autocomplete="off"
                :placeholder="
                    isEdit
                        ? t('Leave empty to keep the current webhook')
                        : webhookPlaceholder
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
            <p v-if="isEdit" class="text-xs text-muted-foreground">
                {{ t('Current: :summary', { summary: channel?.summary }) }}
            </p>
            <InputError :message="errors.webhook_url" />
        </div>

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
