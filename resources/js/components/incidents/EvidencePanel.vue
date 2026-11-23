<script setup lang="ts">
import { computed } from 'vue';
import { useTranslations } from '@/lib/i18n';
import { formatDateTime } from '@/lib/monitoring';
import type { IncidentEvidence } from '@/types';

const { t } = useTranslations();

const props = defineProps<{
    evidence: IncidentEvidence | null;
}>();

const headers = computed(() => Object.entries(props.evidence?.headers ?? {}));

const omittedLabel = computed(() => {
    switch (props.evidence?.body_omitted_reason) {
        case 'head':
            return t('HEAD requests have no response body.');
        case 'empty':
            return t('The response body was empty.');
        case 'binary':
            return t('The response body is not text and was not kept.');
        case 'encoded':
            return t(
                'The response body uses an encoding that could not be decoded.',
            );
        default:
            return null;
    }
});
</script>

<template>
    <section class="space-y-3" data-test="incident-evidence">
        <h2 class="text-base font-medium">{{ t('First failing response') }}</h2>
        <p
            v-if="!evidence"
            class="rounded-lg border border-dashed px-4 py-3 text-sm text-muted-foreground"
            data-test="evidence-empty"
        >
            {{
                t(
                    'No response was recorded: the check failed before the server answered (timeout, DNS, connection or TLS error), or the incident started before evidence was collected.',
                )
            }}
        </p>
        <div v-else class="space-y-3 rounded-lg border p-4 text-sm">
            <dl class="grid gap-3 sm:grid-cols-3">
                <div>
                    <dt class="text-muted-foreground">{{ t('Status') }}</dt>
                    <dd class="mt-1 font-medium" data-test="evidence-status">
                        {{ evidence.status_code }}
                        <span
                            v-if="evidence.http_version"
                            class="font-normal text-muted-foreground"
                            >· HTTP/{{ evidence.http_version }}</span
                        >
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">{{ t('Reason') }}</dt>
                    <dd class="mt-1">{{ evidence.error_label }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">{{ t('Checked') }}</dt>
                    <dd class="mt-1">
                        {{ formatDateTime(evidence.checked_at) }}
                    </dd>
                </div>
            </dl>

            <div v-if="headers.length" class="space-y-1">
                <h3 class="text-xs font-medium text-muted-foreground">
                    {{ t('Response headers') }}
                </h3>
                <table class="w-full text-xs" data-test="evidence-headers">
                    <tbody>
                        <tr v-for="[name, value] in headers" :key="name">
                            <th
                                scope="row"
                                class="w-40 py-0.5 pr-3 text-left align-top font-mono font-normal text-muted-foreground"
                            >
                                {{ name }}
                            </th>
                            <td class="py-0.5 font-mono break-all">
                                {{ value }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="space-y-1">
                <h3 class="text-xs font-medium text-muted-foreground">
                    {{ t('Response body') }}
                </h3>
                <pre
                    v-if="evidence.body_excerpt !== null"
                    class="max-h-80 overflow-auto rounded-md bg-muted p-3 font-mono text-xs break-all whitespace-pre-wrap"
                    data-test="evidence-body"
                    >{{ evidence.body_excerpt }}</pre>
                <p
                    v-else-if="omittedLabel"
                    class="text-xs text-muted-foreground"
                    data-test="evidence-body-omitted"
                >
                    {{ omittedLabel }}
                </p>
                <p
                    v-if="evidence.body_truncated"
                    class="text-xs text-muted-foreground"
                    data-test="evidence-truncated"
                >
                    {{ t('Only the first 4 KiB of the body are kept.') }}
                </p>
            </div>

            <p class="text-xs text-muted-foreground">
                {{
                    t(
                        'Only members see this. Cookies, credentials and headers outside a fixed list are never kept.',
                    )
                }}
            </p>
        </div>
    </section>
</template>
