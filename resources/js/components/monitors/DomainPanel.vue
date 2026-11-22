<script setup lang="ts">
import { CalendarClock, CalendarX } from '@lucide/vue';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { formatDateTime } from '@/lib/monitoring';
import type { MonitorDomain } from '@/types';
import { useTranslations } from '@/lib/i18n';

const { t, tc } = useTranslations();

const props = defineProps<{
    domain: MonitorDomain;
}>();

const label = computed(() => {
    const domain = props.domain;

    if (domain.status === 'unknown' || domain.days_left === null) {
        return t('Unknown');
    }

    if (domain.status === 'expired') {
        return t('Expired');
    }

    if (domain.days_left < 1) {
        return t('Expires today');
    }

    return tc('Expires in :count day|Expires in :count days', domain.days_left);
});
</script>

<template>
    <section class="space-y-4 rounded-lg border p-4" data-test="domain-panel">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="flex items-center gap-2 text-sm font-medium">
                <CalendarX
                    v-if="domain.status === 'expired'"
                    class="size-4 text-destructive"
                />
                <CalendarClock
                    v-else
                    class="size-4"
                    :class="
                        domain.status === 'expiring'
                            ? 'text-warning'
                            : 'text-muted-foreground'
                    "
                />
                {{ t('Domain registration') }}
            </h2>
            <Badge
                v-if="domain.status === 'expired'"
                variant="destructive"
                data-test="domain-status"
                >{{ label }}</Badge
            >
            <Badge
                v-else-if="domain.status === 'expiring'"
                class="bg-status-degraded text-status-degraded-foreground"
                data-test="domain-status"
                >{{ label }}</Badge
            >
            <Badge v-else variant="secondary" data-test="domain-status">{{
                label
            }}</Badge>
        </div>

        <dl
            v-if="domain.expires_at"
            class="grid gap-4 text-sm sm:grid-cols-4"
            data-test="domain-details"
        >
            <div class="min-w-0">
                <dt class="text-muted-foreground">{{ t('Domain') }}</dt>
                <dd class="mt-1 truncate">
                    {{ domain.domain ?? domain.host }}
                </dd>
            </div>
            <div class="min-w-0">
                <dt class="text-muted-foreground">{{ t('Registrar') }}</dt>
                <dd class="mt-1 truncate">{{ domain.registrar ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">{{ t('Expires') }}</dt>
                <dd class="mt-1">{{ formatDateTime(domain.expires_at) }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">{{ t('Last checked') }}</dt>
                <dd class="mt-1">{{ formatDateTime(domain.checked_at) }}</dd>
            </div>
        </dl>
        <p
            v-else-if="domain.error"
            class="text-sm text-muted-foreground"
            data-test="domain-error"
        >
            {{ domain.error }}
        </p>
        <p
            v-else
            class="text-sm text-muted-foreground"
            data-test="domain-empty"
        >
            {{
                t(
                    'Not checked yet. The registry (RDAP) is queried at most once a day.',
                )
            }}
        </p>
        <p
            v-if="domain.expires_at && domain.error"
            class="text-xs text-muted-foreground"
        >
            {{ t('Last lookup failed:') }} {{ domain.error }}
        </p>
        <p class="text-xs text-muted-foreground">
            {{
                t(
                    "The monitor's notification channels are warned before the domain expires and when it has expired, once per expiry date.",
                )
            }}
        </p>
    </section>
</template>
