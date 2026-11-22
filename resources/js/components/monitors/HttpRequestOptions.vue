<script setup lang="ts">
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { HttpMethod, KeywordMode, MonitorDetail } from '@/types';
import { useTranslations } from '@/lib/i18n';

const { t } = useTranslations();

/**
 * Request body, basic auth, keyword assertion and latency threshold of an
 * HTTP monitor. The stored body and password are never sent to the browser:
 * in edit mode they are kept unless the user chooses to replace them.
 */
const props = defineProps<{
    method: string;
    monitor?: MonitorDetail;
    contentTypes: string[];
    keywordModes: KeywordMode[];
    maxBodyBytes: number;
    errors: Record<string, string | undefined>;
}>();

const isEdit = computed(() => props.monitor !== undefined);
const allowsBody = computed(() =>
    (['POST', 'PUT', 'PATCH'] as HttpMethod[]).includes(
        props.method as HttpMethod,
    ),
);
const hasResponseBody = computed(() => props.method !== 'HEAD');

const replaceBody = ref(!isEdit.value || props.monitor?.body === null);
const replaceAuth = ref(
    !isEdit.value || props.monitor?.basic_auth_username === null,
);
const contentType = ref(
    props.monitor?.body?.content_type ?? props.contentTypes[0] ?? '',
);
const keywordMode = ref<string>(props.monitor?.keyword_mode ?? 'contains');
const keywordCaseSensitive = ref(
    props.monitor?.keyword_case_sensitive ?? false,
);

const contentTypeOptions = computed(() =>
    props.contentTypes.map((value) => ({ value, label: value })),
);
const keywordModeOptions = computed(() =>
    props.keywordModes.map((value) => ({
        value,
        label: value === 'contains' ? t('Must contain') : t('Must not contain'),
    })),
);

const textareaClass =
    'w-full rounded-md border border-input bg-transparent px-3 py-2 font-mono text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';
</script>

<template>
    <div class="space-y-6">
        <div v-if="allowsBody" class="space-y-3" data-test="request-body">
            <div>
                <Label for="body">{{ t('Request body') }}</Label>
                <p class="text-xs text-muted-foreground">
                    {{
                        t(
                            'Optional, up to :size KiB. Stored encrypted and never displayed again after saving.',
                            { size: Math.round(maxBodyBytes / 1024) },
                        )
                    }}
                </p>
            </div>
            <input
                v-if="isEdit"
                type="hidden"
                name="body_mode"
                :value="replaceBody ? 'replace' : 'keep'"
            />
            <div
                v-if="!replaceBody && monitor?.body"
                class="flex flex-col gap-3 rounded-md border p-3 sm:flex-row sm:items-center"
            >
                <p class="flex-1 text-sm text-muted-foreground">
                    {{ t('Configured:') }}
                    <span class="font-medium text-foreground">{{
                        t(':type, :bytes bytes', {
                            type: monitor.body.content_type ?? '',
                            bytes: monitor.body.bytes ?? 0,
                        })
                    }}</span>
                </p>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    data-test="replace-body"
                    @click="replaceBody = true"
                >
                    {{ t('Replace body') }}
                </Button>
            </div>
            <template v-else>
                <div class="grid gap-2 sm:max-w-xs">
                    <Label for="body_content_type">{{
                        t('Content type')
                    }}</Label>
                    <NativeSelect
                        id="body_content_type"
                        name="body_content_type"
                        v-model="contentType"
                        :options="contentTypeOptions"
                    />
                    <InputError :message="errors.body_content_type" />
                </div>
                <textarea
                    id="body"
                    name="body"
                    rows="5"
                    spellcheck="false"
                    autocomplete="off"
                    :class="textareaClass"
                    placeholder='{"ping": true}'
                />
            </template>
            <InputError :message="errors.body" />
        </div>

        <div class="space-y-3" data-test="basic-auth">
            <div>
                <Label for="basic_auth_username">{{
                    t('Basic authentication')
                }}</Label>
                <p class="text-xs text-muted-foreground">
                    {{
                        t(
                            'Optional. The password is stored encrypted and only sent to the monitored host.',
                        )
                    }}
                </p>
            </div>
            <input
                v-if="isEdit"
                type="hidden"
                name="auth_mode"
                :value="replaceAuth ? 'replace' : 'keep'"
            />
            <div
                v-if="!replaceAuth"
                class="flex flex-col gap-3 rounded-md border p-3 sm:flex-row sm:items-center"
            >
                <p class="flex-1 text-sm text-muted-foreground">
                    {{ t('Configured for user') }}
                    <span class="font-medium text-foreground">{{
                        monitor?.basic_auth_username
                    }}</span>
                </p>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    data-test="replace-auth"
                    @click="replaceAuth = true"
                >
                    {{ t('Change or remove') }}
                </Button>
            </div>
            <div v-else class="grid gap-2 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Input
                        id="basic_auth_username"
                        name="basic_auth_username"
                        maxlength="255"
                        autocomplete="off"
                        :placeholder="t('User name')"
                        :aria-label="t('User name')"
                    />
                    <InputError :message="errors.basic_auth_username" />
                </div>
                <div class="grid gap-2">
                    <Input
                        id="basic_auth_password"
                        name="basic_auth_password"
                        type="password"
                        maxlength="255"
                        autocomplete="new-password"
                        :placeholder="t('Password')"
                        :aria-label="t('Password')"
                    />
                    <InputError :message="errors.basic_auth_password" />
                </div>
            </div>
        </div>

        <div v-if="hasResponseBody" class="space-y-3" data-test="keyword">
            <div>
                <Label for="keyword">{{ t('Content check') }}</Label>
                <p class="text-xs text-muted-foreground">
                    {{
                        t(
                            'Optional. The check fails if the response body does not meet this condition (first 1 MiB only).',
                        )
                    }}
                </p>
            </div>
            <div class="grid gap-2 sm:grid-cols-[12rem_1fr]">
                <NativeSelect
                    id="keyword_mode"
                    name="keyword_mode"
                    v-model="keywordMode"
                    :options="keywordModeOptions"
                    :aria-label="t('Condition')"
                />
                <Input
                    id="keyword"
                    name="keyword"
                    maxlength="255"
                    autocomplete="off"
                    :placeholder="t('Keyword, e.g. healthy')"
                    :default-value="monitor?.keyword ?? ''"
                />
            </div>
            <InputError :message="errors.keyword" />
            <label
                for="keyword_case_sensitive"
                class="flex items-center gap-3 text-sm"
            >
                <input type="hidden" name="keyword_case_sensitive" value="0" />
                <input
                    id="keyword_case_sensitive"
                    v-model="keywordCaseSensitive"
                    type="checkbox"
                    name="keyword_case_sensitive"
                    value="1"
                    class="size-4 rounded border-input accent-primary"
                />
                <span>{{ t('Case-sensitive') }}</span>
            </label>
        </div>

        <div class="grid gap-2 sm:max-w-xs">
            <Label for="latency_threshold_ms">{{
                t('Latency threshold (ms)')
            }}</Label>
            <Input
                id="latency_threshold_ms"
                name="latency_threshold_ms"
                type="number"
                min="100"
                max="30000"
                step="1"
                :placeholder="t('None')"
                :default-value="monitor?.latency_threshold_ms ?? undefined"
            />
            <p class="text-xs text-muted-foreground">
                {{
                    t(
                        'Optional. Slower responses mark the monitor degraded after 3 in a row; they never make it down.',
                    )
                }}
            </p>
            <InputError :message="errors.latency_threshold_ms" />
        </div>
    </div>
</template>
