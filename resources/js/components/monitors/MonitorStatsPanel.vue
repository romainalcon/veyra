<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import LatencyChart from '@/components/charts/LatencyChart.vue';
import UptimeBars from '@/components/charts/UptimeBars.vue';
import { Button } from '@/components/ui/button';
import { coverageNote, formatUptime } from '@/lib/analytics';
import type { MonitorStats, StatsRange } from '@/types';
import { useTranslations } from '@/lib/i18n';

const { t } = useTranslations();

defineProps<{
    stats: MonitorStats;
    range: StatsRange;
    rangeHref: (range: StatsRange) => string;
}>();

const periods = ['24h', '7d', '30d'] as const;
const ranges: StatsRange[] = ['24h', '7d', '30d', '90d'];
</script>

<template>
    <section class="space-y-4" data-test="monitor-stats">
        <div class="grid gap-4 sm:grid-cols-3">
            <div
                v-for="period in periods"
                :key="period"
                class="rounded-lg border p-4"
            >
                <p class="text-sm text-muted-foreground">
                    {{ t('Uptime :period', { period }) }}
                </p>
                <p class="mt-1 text-2xl font-semibold">
                    {{ formatUptime(stats.uptime[period].uptime) }}
                </p>
                <p
                    v-if="coverageNote(stats.uptime[period])"
                    class="mt-1 text-xs text-muted-foreground"
                >
                    {{ coverageNote(stats.uptime[period]) }}
                </p>
            </div>
        </div>

        <div class="space-y-2 rounded-lg border p-4">
            <h2 class="text-sm font-medium">{{ t('Daily uptime') }}</h2>
            <UptimeBars :days="stats.days" />
        </div>

        <div class="space-y-3 rounded-lg border p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sm font-medium">{{ t('Response time') }}</h2>
                <nav class="flex gap-1" :aria-label="t('Chart range')">
                    <Button
                        v-for="option in ranges"
                        :key="option"
                        :variant="option === range ? 'default' : 'ghost'"
                        size="sm"
                        as-child
                    >
                        <Link
                            :href="rangeHref(option)"
                            :only="['stats', 'range']"
                            preserve-scroll
                            preserve-state
                            >{{ option }}</Link
                        >
                    </Button>
                </nav>
            </div>
            <LatencyChart :points="stats.latency" :range="range" />
        </div>
    </section>
</template>
