<script setup lang="ts">
import { formatUptime, uptimeTone } from '@/lib/analytics';
import { cn } from '@/lib/utils';
import type { UptimeDay } from '@/types';

type Day = Pick<UptimeDay, 'day' | 'uptime' | 'coverage'>;

defineProps<{ days: Day[] }>();

const toneClass: Record<ReturnType<typeof uptimeTone>, string> = {
    none: 'bg-muted',
    good: 'bg-emerald-500',
    warning: 'bg-amber-500',
    bad: 'bg-red-500',
};

const describe = (day: Day) =>
    `${day.day}: ${formatUptime(day.uptime)}${day.uptime !== null && day.coverage !== null && day.coverage < 0.9 ? ` (partial data, ${Math.round(day.coverage * 100)}% of expected checks)` : ''}`;
</script>

<template>
    <div class="space-y-1">
        <div
            class="flex h-8 items-stretch gap-px"
            role="img"
            :aria-label="`Daily uptime over the last ${days.length} days`"
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
            <span>{{ days.length }} days ago</span>
            <span>Today</span>
        </div>
    </div>
</template>
