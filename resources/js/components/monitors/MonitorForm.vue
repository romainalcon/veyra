<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import type { RouteFormDefinition } from '@/wayfinder';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import HeadersEditor from '@/components/monitors/HeadersEditor.vue';
import HttpRequestOptions from '@/components/monitors/HttpRequestOptions.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { parseExpectedStatuses } from '@/lib/monitoring';
import type { MonitorDetail, MonitorFormOptions, MonitorType } from '@/types';
import { useTranslations } from '@/lib/i18n';

const { t } = useTranslations();

const props = defineProps<
    MonitorFormOptions & {
        action: RouteFormDefinition<'post'>;
        monitor?: MonitorDetail;
        submitLabel: string;
    }
>();

const isEdit = computed(() => props.monitor !== undefined);
const monitorType = ref<MonitorType>(props.monitor?.type ?? 'http');
const isHttp = computed(() => monitorType.value === 'http');
const isTcp = computed(() => monitorType.value === 'tcp');
const isHeartbeat = computed(() => monitorType.value === 'heartbeat');
const isDns = computed(() => monitorType.value === 'dns');
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

const escalationPolicyId = ref<string>(
    props.monitor?.escalation_policy_id ?? '',
);
const escalationOptions = computed(() => {
    const fallback = props.escalationPolicies.find(
        (policy) => policy.is_default,
    );

    return [
        {
            value: '',
            label: fallback
                ? t('Organization default (:name)', { name: fallback.name })
                : t('None'),
        },
        ...props.escalationPolicies.map((policy) => ({
            value: policy.id,
            label: policy.name,
        })),
    ];
});

const statusesValid = computed(
    () => parseExpectedStatuses(expectedStatuses.value) !== null,
);

const intervalOptions = computed(() =>
    (isHeartbeat.value ? props.heartbeatPeriods : props.intervals).map(
        (option) => ({
            value: String(option.value),
            label: option.label,
        }),
    ),
);

// Keep a valid choice when switching between probed monitors and heartbeats.
watch(intervalOptions, (options) => {
    if (!options.some((option) => option.value === interval.value)) {
        interval.value = isHeartbeat.value ? '3600' : '60';
    }
});

const graceMinutes = computed(() =>
    props.monitor?.heartbeat
        ? Math.round(props.monitor.heartbeat.grace_seconds / 60)
        : 5,
);

const currentTypeLabel = computed(
    () =>
        props.types.find((option) => option.value === monitorType.value)
            ?.label ?? monitorType.value,
);

const dnsRecordType = ref<string>(props.monitor?.dns?.record_type ?? 'A');
const dnsResolver = ref<string>(
    props.monitor?.dns?.resolver ?? props.dnsResolvers[0]?.value ?? '',
);
const dnsMatchMode = ref<string>(props.monitor?.dns?.match_mode ?? 'contains');
const dnsExpectedValues = (props.monitor?.dns?.expected_values ?? []).join(
    '\n',
);

const dnsRecordTypeOptions = computed(() =>
    props.dnsRecordTypes.map((value) => ({ value, label: value })),
);

const dnsResolverOptions = computed(() => {
    const options = [...props.dnsResolvers];
    const current = props.monitor?.dns;

    // A resolver disabled by the operator stays visible on the monitor using it.
    if (
        current &&
        !options.some((option) => option.value === current.resolver)
    ) {
        options.push({
            value: current.resolver,
            label: current.resolver_label,
        });
    }

    return options;
});

const dnsMatchModeOptions = computed(() =>
    props.dnsMatchModes.map((value) => ({
        value,
        label:
            value === 'exact'
                ? t('Answers are exactly these values')
                : t('Answers include these values'),
    })),
);

const dnsExpectedPlaceholder = computed(
    () =>
        ({
            A: '93.184.215.14',
            AAAA: '2606:2800:21f:cb07:6820:80da:af6b:8b2c',
            CNAME: 'example.netlify.app',
            MX: '10 mail.example.com',
            TXT: 'v=spf1 include:_spf.example.com',
            NS: 'ns1.example.net',
        })[dnsRecordType.value] ?? '',
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
        <fieldset v-if="!isEdit" class="grid gap-2">
            <legend class="text-sm font-medium">{{ t('Type') }}</legend>
            <div class="flex flex-wrap gap-2" role="radiogroup">
                <label
                    v-for="option in types"
                    :key="option.value"
                    class="flex cursor-pointer items-center gap-2 rounded-md border px-3 py-2 text-sm has-[:checked]:border-primary has-[:checked]:bg-primary/5"
                >
                    <input
                        v-model="monitorType"
                        type="radio"
                        name="type"
                        :value="option.value"
                        class="size-4 accent-primary"
                        :data-test="`type-${option.value}`"
                    />
                    {{ option.label }}
                </label>
            </div>
            <InputError :message="errors.type" />
        </fieldset>
        <p v-else class="text-sm text-muted-foreground">
            {{ t('Type:') }}
            <span class="font-medium text-foreground">{{
                currentTypeLabel
            }}</span>
            {{ t('(cannot be changed)') }}
        </p>

        <div class="grid gap-2">
            <Label for="name">{{ t('Name') }}</Label>
            <Input
                id="name"
                name="name"
                required
                maxlength="100"
                :placeholder="t('Marketing website')"
                :default-value="monitor?.name"
            />
            <InputError :message="errors.name" />
        </div>

        <p
            v-if="isHeartbeat"
            class="rounded-md border bg-muted/40 p-3 text-sm text-muted-foreground"
        >
            {{
                t(
                    'Your job calls a secret ping URL when it runs. If no ping arrives within the period plus the grace time, the heartbeat is reported down. The URL is shown once after saving.',
                )
            }}
        </p>

        <div v-if="isTcp" class="grid gap-2 sm:grid-cols-[1fr_8rem]">
            <div class="grid gap-2">
                <Label for="host">{{ t('Host') }}</Label>
                <Input
                    id="host"
                    name="host"
                    required
                    maxlength="255"
                    placeholder="mail.example.com"
                    autocomplete="off"
                    :default-value="monitor?.host ?? ''"
                />
                <InputError :message="errors.host" />
            </div>
            <div class="grid gap-2">
                <Label for="port">{{ t('Port') }}</Label>
                <Input
                    id="port"
                    name="port"
                    type="number"
                    min="1"
                    max="65535"
                    required
                    list="tcp-ports"
                    :default-value="monitor?.port ?? undefined"
                />
                <datalist id="tcp-ports">
                    <option
                        v-for="port in tcpPorts"
                        :key="port"
                        :value="port"
                    />
                </datalist>
                <InputError :message="errors.port" />
            </div>
            <p class="text-xs text-muted-foreground sm:col-span-2">
                {{
                    t(
                        'Veyra opens a TCP connection and closes it immediately; nothing is sent. Allowed ports on this instance: :ports.',
                        { ports: tcpPorts.join(', ') },
                    )
                }}
            </p>
        </div>

        <div v-if="isDns" class="space-y-4" data-test="dns-fields">
            <div class="grid gap-2 sm:grid-cols-[1fr_8rem]">
                <div class="grid gap-2">
                    <Label for="record_name">{{ t('Domain name') }}</Label>
                    <Input
                        id="record_name"
                        name="record_name"
                        required
                        maxlength="253"
                        placeholder="example.com"
                        autocomplete="off"
                        :default-value="monitor?.dns?.record_name ?? ''"
                    />
                    <InputError :message="errors.record_name" />
                </div>
                <div class="grid gap-2">
                    <Label for="record_type">{{ t('Record type') }}</Label>
                    <NativeSelect
                        id="record_type"
                        name="record_type"
                        v-model="dnsRecordType"
                        :options="dnsRecordTypeOptions"
                    />
                    <InputError :message="errors.record_type" />
                </div>
            </div>
            <div class="grid gap-2">
                <Label for="resolver">{{ t('Resolver') }}</Label>
                <NativeSelect
                    id="resolver"
                    name="resolver"
                    v-model="dnsResolver"
                    :options="dnsResolverOptions"
                />
                <p class="text-xs text-muted-foreground">
                    {{
                        t(
                            'Public resolvers show what visitors see; the system resolver is the one of the Veyra workers.',
                        )
                    }}
                </p>
                <InputError :message="errors.resolver" />
            </div>
            <div class="grid gap-2">
                <Label for="expected_values">{{
                    t('Expected values (optional)')
                }}</Label>
                <textarea
                    id="expected_values"
                    name="expected_values"
                    rows="3"
                    class="w-full rounded-md border border-input bg-transparent px-3 py-2 font-mono text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                    :placeholder="dnsExpectedPlaceholder"
                    :value="dnsExpectedValues"
                />
                <p class="text-xs text-muted-foreground">
                    {{
                        t(
                            'One value per line, at most :count. Without values, any answer counts as up.',
                            { count: maxDnsExpectedValues },
                        )
                    }}
                </p>
                <InputError :message="errors.expected_values" />
            </div>
            <div class="grid gap-2">
                <Label for="match_mode">{{ t('Comparison') }}</Label>
                <NativeSelect
                    id="match_mode"
                    name="match_mode"
                    v-model="dnsMatchMode"
                    :options="dnsMatchModeOptions"
                />
                <InputError :message="errors.match_mode" />
            </div>
        </div>

        <div v-if="isHttp" class="grid gap-2 sm:grid-cols-[8rem_1fr]">
            <div class="grid gap-2">
                <Label for="method">{{ t('Method') }}</Label>
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
                <Label for="interval">{{
                    isHeartbeat ? t('Expected every') : t('Check every')
                }}</Label>
                <NativeSelect
                    id="interval"
                    name="interval"
                    v-model="interval"
                    :options="intervalOptions"
                />
                <InputError :message="errors.interval" />
            </div>
            <div v-if="isHeartbeat" class="grid gap-2">
                <Label for="grace_minutes">{{
                    t('Grace time (minutes)')
                }}</Label>
                <Input
                    id="grace_minutes"
                    name="grace_minutes"
                    type="number"
                    min="1"
                    :max="maxGraceMinutes"
                    required
                    :default-value="graceMinutes"
                />
                <InputError :message="errors.grace_minutes" />
            </div>
            <div v-else class="grid gap-2">
                <Label for="timeout">{{ t('Timeout (seconds)') }}</Label>
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
            <div v-if="isHttp" class="grid gap-2">
                <Label for="expected_statuses">{{
                    t('Expected status codes')
                }}</Label>
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
                    {{
                        t(
                            'Use codes or ranges between 100 and 599, e.g. 200-299, 301.',
                        )
                    }}
                </p>
                <InputError :message="errors.expected_statuses" />
            </div>
        </div>

        <label
            v-if="isHttp"
            for="follow_redirects"
            class="flex items-center gap-3 text-sm"
        >
            <input type="hidden" name="follow_redirects" value="0" />
            <input
                id="follow_redirects"
                v-model="followRedirects"
                type="checkbox"
                name="follow_redirects"
                value="1"
                class="size-4 rounded border-input accent-primary"
            />
            <span>{{
                t('Follow redirects (up to 3, each one re-validated)')
            }}</span>
        </label>

        <div v-if="isHttp" class="space-y-3">
            <div>
                <Label>{{ t('Custom headers') }}</Label>
                <p class="text-xs text-muted-foreground">
                    {{
                        t(
                            'Stored encrypted. Values are never displayed again after saving.',
                        )
                    }}
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
                        {{ t('Configured:') }}
                        <span class="font-medium text-foreground">{{
                            monitor.header_names.join(', ')
                        }}</span>
                    </template>
                    <template v-else>{{ t('No custom headers.') }}</template>
                </p>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    data-test="replace-headers"
                    @click="replaceHeaders = true"
                >
                    {{ t('Replace headers') }}
                </Button>
            </div>
            <HeadersEditor v-else v-model="headers" :errors="errors" />
            <InputError :message="errors.headers" />
        </div>

        <HttpRequestOptions
            v-if="isHttp"
            :method="httpMethod"
            :monitor="monitor"
            :content-types="contentTypes"
            :keyword-modes="keywordModes"
            :max-body-bytes="maxBodyBytes"
            :errors="errors"
        />

        <fieldset class="space-y-3">
            <legend class="text-sm font-medium">
                {{ t('Notification channels') }}
            </legend>
            <input
                type="hidden"
                name="notification_channels_submitted"
                value="1"
            />
            <p
                v-if="channels.length === 0"
                class="text-xs text-muted-foreground"
            >
                {{ t('No channels configured for this organization yet.') }}
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
                    {{
                        channel.type === 'email'
                            ? t('E-mail')
                            : channel.type === 'webhook'
                              ? 'Webhook'
                              : 'Discord'
                    }}{{ channel.enabled ? '' : ` · ${t('disabled')}` }}
                </span>
            </label>
            <InputError :message="errors.notification_channel_ids" />
        </fieldset>

        <div class="grid gap-2">
            <Label for="escalation_policy_id">{{
                t('Escalation policy')
            }}</Label>
            <NativeSelect
                id="escalation_policy_id"
                v-model="escalationPolicyId"
                name="escalation_policy_id"
                :options="escalationOptions"
                data-test="escalation-policy-select"
            />
            <p class="text-xs text-muted-foreground">
                {{
                    t(
                        'Notifies more channels while an incident stays unacknowledged. Policies are managed on the notifications page.',
                    )
                }}
            </p>
            <InputError :message="errors.escalation_policy_id" />
        </div>

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
