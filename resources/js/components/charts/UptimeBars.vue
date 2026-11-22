<script setup lang="ts">
import { formatUptime, uptimeTone } from '@/lib/analytics';
import { useTranslations } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { UptimeDay } from '@/types';

type Day = Pick<UptimeDay, 'day' | 'uptime' | 'coverage'>;

defineProps<{ days: Day[] }>();

const toneClass: Record<ReturnType<typeof uptimeTone>, string> = {
    // No data is drawn as a short hatched stub, never as a full green bar.
    none: 'mt-auto h-1/3 bg-[repeating-linear-gradient(135deg,var(--muted-foreground)_0_1px,transparent_1px_4px)] opacity-40',
    good: 'bg-status-up',
    warning: 'bg-status-degraded',
    bad: 'bg-status-down',
};

const { t, tc } = useTranslations();

const describe = (day: Day) =>
    day.uptime !== null && day.coverage !== null && day.coverage < 0.9
        ? t(':day: :uptime (partial data, :percent% of expected checks)', {
              day: day.day,
              uptime: formatUptime(day.uptime),
              percent: Math.round(day.coverage * 100),
          })
        : `${day.day}: ${formatUptime(day.uptime)}`;
</script>

<template>
    <div class="space-y-1">
        <div
            class="flex h-8 items-stretch gap-px"
            role="img"
            :aria-label="
                t('Daily uptime over the last :count days', {
                    count: days.length,
                })
            "
            data-test="uptime-bars"
        >
            <div
                v-for="day in days"
                :key="day.day"
                :class="
                    cn(
                        'min-w-0 flex-1 rounded-[2px]',
                        toneClass[uptimeTone(day.uptime)],
                        day.uptime !== null &&
                            day.coverage !== null &&
                            day.coverage < 0.9 &&
                            'opacity-60',
                    )
                "
                :title="describe(day)"
                :data-tone="uptimeTone(day.uptime)"
            />
        </div>
        <div class="flex justify-between text-xs text-muted-foreground">
            <span>{{ tc(':count day ago|:count days ago', days.length) }}</span>
            <span>{{ t('Today') }}</span>
        </div>
    </div>
</template>
