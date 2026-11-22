<script setup lang="ts">
import { Globe } from '@lucide/vue';
import { formatDateTime } from '@/lib/monitoring';
import type { MonitorDns } from '@/types';
import { useTranslations } from '@/lib/i18n';

const { t } = useTranslations();

defineProps<{
    dns: MonitorDns;
}>();
</script>

<template>
    <section class="space-y-4 rounded-lg border p-4" data-test="dns-panel">
        <h2 class="flex items-center gap-2 text-sm font-medium">
            <Globe class="size-4 text-muted-foreground" />
            {{ t('DNS query') }}
        </h2>

        <dl class="grid gap-4 text-sm sm:grid-cols-3">
            <div class="min-w-0">
                <dt class="text-muted-foreground">{{ t('Record') }}</dt>
                <dd class="mt-1 font-mono break-all">
                    {{ dns.record_type }} {{ dns.record_name }}
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground">{{ t('Resolver') }}</dt>
                <dd class="mt-1">{{ dns.resolver_label }}</dd>
            </div>
            <div class="min-w-0">
                <dt class="text-muted-foreground">
                    {{
                        dns.match_mode === 'exact'
                            ? t('Answers are exactly these values')
                            : t('Answers include these values')
                    }}
                </dt>
                <dd class="mt-1">
                    <ul
                        v-if="dns.expected_values.length"
                        class="space-y-0.5 font-mono break-all"
                    >
                        <li v-for="value in dns.expected_values" :key="value">
                            {{ value }}
                        </li>
                    </ul>
                    <span v-else class="text-muted-foreground">{{
                        t('Any answer')
                    }}</span>
                </dd>
            </div>
        </dl>

        <div class="text-sm">
            <h3 class="text-muted-foreground">
                {{ t('Last answers') }}
                <span v-if="dns.last_resolved_at" class="text-xs">
                    · {{ formatDateTime(dns.last_resolved_at) }}</span
                >
            </h3>
            <ul
                v-if="dns.last_answers && dns.last_answers.length"
                class="mt-1 space-y-0.5 font-mono break-all"
                data-test="dns-answers"
            >
                <li v-for="answer in dns.last_answers" :key="answer">
                    {{ answer }}
                </li>
            </ul>
            <p
                v-else-if="dns.last_answers"
                class="mt-1 text-muted-foreground"
                data-test="dns-no-answers"
            >
                {{ t('The name server returned no record of this type.') }}
            </p>
            <p
                v-else
                class="mt-1 text-muted-foreground"
                data-test="dns-pending"
            >
                {{ t('No answer received yet.') }}
            </p>
        </div>
    </section>
</template>
