<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslations } from '@/lib/i18n';
import type {
    EscalationLimits,
    EscalationPolicySummary,
    NotificationChannelSummary,
} from '@/types';
import type { RouteFormDefinition } from '@/wayfinder';

const { t } = useTranslations();

/**
 * Ordered steps (delay since the incident started + channels). The server
 * validates that delays strictly increase and that channels belong to the
 * organization (ADR-045).
 */
const props = defineProps<{
    action: RouteFormDefinition<'post'>;
    channels: NotificationChannelSummary[];
    limits: EscalationLimits;
    policy?: EscalationPolicySummary;
}>();

const emit = defineEmits<{ saved: [] }>();

type DraftStep = { key: number; delay: number; channelIds: string[] };

let nextKey = 0;
const steps = ref<DraftStep[]>(
    props.policy?.steps.map((step) => ({
        key: nextKey++,
        delay: step.delay_minutes,
        channelIds: step.channels.map((channel) => channel.id),
    })) ?? [{ key: nextKey++, delay: 15, channelIds: [] }],
);

const formId = props.policy?.id ?? 'new';

const addStep = () => {
    const last = steps.value[steps.value.length - 1];

    steps.value.push({
        key: nextKey++,
        delay: Math.min((last?.delay ?? 0) + 15, props.limits.maxDelayMinutes),
        channelIds: [],
    });
};

const removeStep = (key: number) => {
    steps.value = steps.value.filter((step) => step.key !== key);
};

const reset = () => {
    if (props.policy === undefined) {
        steps.value = [{ key: nextKey++, delay: 15, channelIds: [] }];
    }

    emit('saved');
};
</script>

<template>
    <Form
        v-bind="action"
        :options="{ preserveScroll: true }"
        :reset-on-success="policy === undefined"
        class="space-y-4"
        v-slot="{ errors, processing }"
        data-test="escalation-policy-form"
        @success="reset"
    >
        <div class="grid gap-2 sm:max-w-sm">
            <Label :for="`policy-name-${formId}`">{{ t('Name') }}</Label>
            <Input
                :id="`policy-name-${formId}`"
                name="name"
                required
                maxlength="100"
                :default-value="policy?.name"
                :placeholder="t('Production on-call')"
            />
            <InputError :message="errors.name" />
        </div>

        <ol class="space-y-3">
            <li
                v-for="(step, index) in steps"
                :key="step.key"
                class="space-y-3 rounded-md border p-3"
                data-test="escalation-step"
            >
                <div class="flex flex-wrap items-end gap-3">
                    <p class="text-sm font-medium">
                        {{ t('Step :number', { number: index + 1 }) }}
                    </p>
                    <div class="grid gap-1">
                        <Label
                            :for="`delay-${formId}-${step.key}`"
                            class="text-xs text-muted-foreground"
                            >{{ t('After (minutes down)') }}</Label
                        >
                        <Input
                            :id="`delay-${formId}-${step.key}`"
                            v-model.number="step.delay"
                            type="number"
                            :name="`steps[${index}][delay_minutes]`"
                            min="1"
                            :max="limits.maxDelayMinutes"
                            required
                            class="w-28"
                        />
                    </div>
                    <Button
                        v-if="steps.length > 1"
                        type="button"
                        variant="ghost"
                        size="sm"
                        class="ml-auto"
                        :aria-label="
                            t('Remove step :number', { number: index + 1 })
                        "
                        @click="removeStep(step.key)"
                    >
                        <Trash2 class="size-4" />
                    </Button>
                </div>
                <InputError :message="errors[`steps.${index}.delay_minutes`]" />

                <fieldset class="space-y-2">
                    <legend class="text-xs text-muted-foreground">
                        {{ t('Notify') }}
                    </legend>
                    <div class="flex flex-wrap gap-x-6 gap-y-2 text-sm">
                        <label
                            v-for="channel in channels"
                            :key="channel.id"
                            class="flex items-center gap-2"
                        >
                            <input
                                v-model="step.channelIds"
                                type="checkbox"
                                :name="`steps[${index}][channel_ids][]`"
                                :value="channel.id"
                                class="size-4 accent-primary"
                            />
                            {{ channel.name }}
                        </label>
                    </div>
                    <InputError
                        :message="
                            errors[`steps.${index}.channel_ids`] ??
                            errors[`steps.${index}.channel_ids.0`]
                        "
                    />
                </fieldset>
            </li>
        </ol>
        <InputError :message="errors.steps" />

        <Button
            v-if="steps.length < limits.maxSteps"
            type="button"
            variant="outline"
            size="sm"
            data-test="add-escalation-step"
            @click="addStep"
        >
            <Plus class="size-4" />
            {{ t('Add a step') }}
        </Button>

        <label class="flex items-center gap-2 text-sm">
            <input type="hidden" name="is_default" value="0" />
            <input
                type="checkbox"
                name="is_default"
                value="1"
                :checked="policy?.is_default ?? false"
                class="size-4 accent-primary"
            />
            {{ t('Use for monitors without a policy of their own') }}
        </label>

        <div>
            <Button :disabled="processing" data-test="save-escalation-policy">
                {{ policy ? t('Save policy') : t('Add policy') }}
            </Button>
        </div>
    </Form>
</template>
