<script setup lang="ts">
import { CurveType } from '@unovis/ts/types/curve';
import VisArea from '@unovis/vue/components/area';
import VisAxis from '@unovis/vue/components/axis';
import VisLine from '@unovis/vue/components/line';
import VisXYContainer from '@unovis/vue/containers/xy-container';
import { computed } from 'vue';
import type { ChartConfig } from '@/components/ui/chart';
import {
    ChartContainer,
    ChartCrosshair,
    ChartTooltip,
    ChartTooltipContent,
    componentToString,
} from '@/components/ui/chart';
import { formatBucketLabel } from '@/lib/analytics';
import type { LatencyPoint } from '@/types';
import { useTranslations } from '@/lib/i18n';

const { t } = useTranslations();

const props = defineProps<{
    points: LatencyPoint[];
    range: string;
}>();

type Datum = { x: number; avg: number | undefined; max: number | undefined };

const data = computed<Datum[]>(() =>
    props.points.map((point, index) => ({
        x: index,
        avg: point.latency_avg ?? undefined,
        max: point.latency_max ?? undefined,
    })),
);

const hasData = computed(() =>
    props.points.some((point) => point.latency_avg !== null),
);

const hasMaintenance = computed(() =>
    props.points.some((point) => point.maintenance > 0),
);

const config = {
    avg: { label: t('Average (ms)'), color: 'var(--chart-2)' },
    max: { label: t('Max (ms)'), color: 'var(--chart-1)' },
} satisfies ChartConfig;

const tickFormat = (index: number) => {
    const point = props.points[Math.round(index)];

    return point ? formatBucketLabel(point.t, props.range) : '';
};

const tooltip = componentToString(config, ChartTooltipContent, {
    labelFormatter: (x: number | Date) => tickFormat(Number(x)),
});
</script>

<template>
    <div
        v-if="!hasData"
        class="flex h-56 items-center justify-center rounded-lg border border-dashed text-sm text-muted-foreground"
        data-test="latency-empty"
    >
        {{ t('No successful checks in this period.') }}
    </div>
    <ChartContainer v-else :config="config" class="aspect-auto h-56" cursor>
        <VisXYContainer
            :data="data"
            :margin="{ left: 4, right: 4 }"
            :y-domain="[0, undefined]"
        >
            <VisArea
                :x="(d: Datum) => d.x"
                :y="(d: Datum) => d.avg"
                :color="config.avg.color"
                :opacity="0.3"
                :curve-type="CurveType.MonotoneX"
            />
            <VisLine
                :x="(d: Datum) => d.x"
                :y="[(d: Datum) => d.avg, (d: Datum) => d.max]"
                :color="[config.avg.color, config.max.color]"
                :curve-type="CurveType.MonotoneX"
            />
            <VisAxis
                type="x"
                :x="(d: Datum) => d.x"
                :tick-line="false"
                :domain-line="false"
                :grid-line="false"
                :num-ticks="6"
                :tick-format="tickFormat"
            />
            <VisAxis
                type="y"
                :num-ticks="4"
                :tick-line="false"
                :domain-line="false"
                :tick-format="(v: number) => `${v} ms`"
            />
            <ChartTooltip />
            <ChartCrosshair
                :template="tooltip"
                :color="[config.avg.color, config.max.color]"
            />
        </VisXYContainer>
    </ChartContainer>
    <div
        v-if="hasMaintenance"
        class="mt-2 space-y-1"
        data-test="latency-maintenance"
    >
        <div class="flex h-1.5 gap-px overflow-hidden rounded-full">
            <span
                v-for="point in points"
                :key="point.t"
                class="flex-1"
                :class="
                    point.maintenance > 0
                        ? 'bg-status-maintenance'
                        : 'bg-transparent'
                "
                :title="
                    point.maintenance > 0
                        ? t(':date: maintenance', {
                              date: tickFormat(points.indexOf(point)),
                          })
                        : undefined
                "
            />
        </div>
        <p class="text-xs text-muted-foreground">
            <span
                class="mr-1 inline-block size-2 rounded-full bg-status-maintenance"
                aria-hidden="true"
            />{{ t('Planned maintenance (excluded from latency and uptime)') }}
        </p>
    </div>
</template>
