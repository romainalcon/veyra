<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import type { RouteFormDefinition } from '@/wayfinder';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import HeadersEditor from '@/components/monitors/HeadersEditor.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { parseExpectedStatuses } from '@/lib/monitoring';
import type { MonitorDetail, MonitorFormOptions } from '@/types';

const props = defineProps<
    MonitorFormOptions & {
        action: RouteFormDefinition<'post'>;
        monitor?: MonitorDetail;
        submitLabel: string;
    }
>();

const isEdit = computed(() => props.monitor !== undefined);
const replaceHeaders = ref(!isEdit.value);

const httpMethod = ref<string>(props.monitor?.method ?? 'GET');
const interval = ref<string>(String(props.monitor?.interval_seconds ?? 60));
const expectedStatuses = ref(props.monitor?.expected_statuses ?? '200-299');
const followRedirects = ref(props.monitor?.follow_redirects ?? true);
const urlPlaceholder = 'https://example.com/health';
const defaultUrl = props.monitor?.url ?? 'https://';
const headers = ref<{ name: string; value: string }[]>([]);
const selectedChannels = ref<string[]>(
    props.monitor?.notification_channel_ids ??
        props.channels
            .filter((channel) => channel.is_default)
            .map((channel) => channel.id),
);

const statusesValid = computed(
    () => parseExpectedStatuses(expectedStatuses.value) !== null,
);

const intervalOptions = computed(() =>
    props.intervals.map((option) => ({
        value: String(option.value),
        label: option.label,
    })),
);

const methodOptions = computed(() =>
    props.methods.map((value) => ({ value, label: value })),
);
</script>

<template>
    <Form
        v-bind="action"
        :options="{ preserveScroll: true }"
        class="space-y-6"
        v-slot="{ errors, processing }"
    >
        <div class="grid gap-2">
            <Label for="name">Name</Label>
            <Input
                id="name"
                name="name"
                required
                maxlength="100"
                placeholder="Marketing website"
                :default-value="monitor?.name"
            />
            <InputError :message="errors.name" />
        </div>

        <div class="grid gap-2 sm:grid-cols-[8rem_1fr]">
            <div class="grid gap-2">
                <Label for="method">Method</Label>
                <NativeSelect
                    id="method"
                    name="method"
                    v-model="httpMethod"
                    :options="methodOptions"
                />
                <InputError :message="errors.method" />
            </div>
            <div class="grid gap-2">
                <Label for="url">URL</Label>
                <Input
                    id="url"
                    name="url"
                    inputmode="url"
                    required
                    maxlength="2048"
                    :placeholder="urlPlaceholder"
                    :default-value="defaultUrl"
                />
                <InputError :message="errors.url" />
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <div class="grid gap-2">
                <Label for="interval">Check every</Label>
                <NativeSelect
                    id="interval"
                    name="interval"
                    v-model="interval"
                    :options="intervalOptions"
                />
                <InputError :message="errors.interval" />
            </div>
            <div class="grid gap-2">
                <Label for="timeout">Timeout (seconds)</Label>
                <Input
                    id="timeout"
                    name="timeout"
                    type="number"
                    min="1"
                    max="30"
                    required
                    :default-value="monitor?.timeout_seconds ?? 10"
                />
                <InputError :message="errors.timeout" />
            </div>
            <div class="grid gap-2">
                <Label for="expected_statuses">Expected status codes</Label>
                <Input
                    id="expected_statuses"
                    name="expected_statuses"
                    v-model="expectedStatuses"
                    placeholder="200-299, 301"
                    :aria-invalid="!statusesValid"
                />
                <p
                    v-if="!statusesValid && !errors.expected_statuses"
                    class="text-xs text-muted-foreground"
                >
                    Use codes or ranges between 100 and 599, e.g. 200-299, 301.
                </p>
                <InputError :message="errors.expected_statuses" />
            </div>
        </div>

        <label for="follow_redirects" class="flex items-center gap-3 text-sm">
            <input type="hidden" name="follow_redirects" value="0" />
            <input
                id="follow_redirects"
                v-model="followRedirects"
                type="checkbox"
                name="follow_redirects"
                value="1"
                class="size-4 rounded border-input accent-primary"
            />
            <span>Follow redirects (up to 3, each one re-validated)</span>
        </label>

        <div class="space-y-3">
            <div>
                <Label>Custom headers</Label>
                <p class="text-xs text-muted-foreground">
                    Stored encrypted. Values are never displayed again after
                    saving.
                </p>
            </div>

            <input
                v-if="isEdit"
                type="hidden"
                name="headers_mode"
                :value="replaceHeaders ? 'replace' : 'keep'"
            />

            <div
                v-if="isEdit && !replaceHeaders"
                class="flex flex-col gap-3 rounded-md border p-3 sm:flex-row sm:items-center"
            >
                <p class="flex-1 text-sm text-muted-foreground">
                    <template v-if="monitor?.header_names.length">
                        Configured:
                        <span class="font-medium text-foreground">{{
                            monitor.header_names.join(', ')
                        }}</span>
                    </template>
                    <template v-else>No custom headers.</template>
                </p>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    data-test="replace-headers"
                    @click="replaceHeaders = true"
                >
                    Replace headers
                </Button>
            </div>
            <HeadersEditor v-else v-model="headers" :errors="errors" />
            <InputError :message="errors.headers" />
        </div>

        <fieldset class="space-y-3">
            <legend class="text-sm font-medium">Notification channels</legend>
            <input
                type="hidden"
                name="notification_channels_submitted"
                value="1"
            />
            <p
                v-if="channels.length === 0"
                class="text-xs text-muted-foreground"
            >
                No channels configured for this organization yet.
            </p>
            <label
                v-for="channel in channels"
                :key="channel.id"
                class="flex items-center gap-3 text-sm"
            >
                <input
                    v-model="selectedChannels"
                    type="checkbox"
                    name="notification_channel_ids[]"
                    :value="channel.id"
                    class="size-4 accent-primary"
                />
                <span>{{ channel.name }}</span>
                <span class="text-xs text-muted-foreground">
                    {{ channel.type === 'email' ? 'E-mail' : 'Discord'
                    }}{{ channel.enabled ? '' : ' · disabled' }}
                </span>
            </label>
            <InputError :message="errors.notification_channel_ids" />
        </fieldset>

        <Button
            type="submit"
            :disabled="processing"
            data-test="save-monitor-button"
        >
            <Spinner v-if="processing" />
            {{ submitLabel }}
        </Button>
    </Form>
</template>
