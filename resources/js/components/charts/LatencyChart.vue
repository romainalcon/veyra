<script setup lang="ts">
import { CurveType } from '@unovis/ts/types/curve';
import VisArea from '@unovis/vue/components/area';
import VisAxis from '@unovis/vue/components/axis';
import VisLine from '@unovis/vue/components/line';
import VisXYContainer from '@unovis/vue/containers/xy-container';
import { computed, ref } from 'vue';
import type { ChartConfig } from '@/components/ui/chart';
import {
    ChartContainer,
    ChartCrosshair,
    ChartTooltip,
    ChartTooltipContent,
    componentToString,
} from '@/components/ui/chart';
import { formatBucketLabel } from '@/lib/analytics';
import type { LatencyPoint, TimingPhase } from '@/types';
import { timingPhases } from '@/lib/timings';
import { useTranslations } from '@/lib/i18n';

const { t } = useTranslations();

const props = defineProps<{
    points: LatencyPoint[];
    range: string;
}>();

type Datum = {
    x: number;
    avg: number | undefined;
    max: number | undefined;
} & Partial<Record<TimingPhase, number>>;

const data = computed<Datum[]>(() =>
    props.points.map((point, index) => ({
        x: index,
        avg: point.latency_avg ?? undefined,
        max: point.latency_max ?? undefined,
        dns: point.phases?.dns ?? undefined,
        connect: point.phases?.connect ?? undefined,
        tls: point.phases?.tls ?? undefined,
        ttfb: point.phases?.ttfb ?? undefined,
        transfer: point.phases?.transfer ?? undefined,
    })),
);

/** Stacked by phase (ADR-050): only phases measured in this period. */
const phases = computed(() =>
    timingPhases.filter(
        (
            entry,
        ): entry is (typeof timingPhases)[number] & { phase: TimingPhase } =>
            entry.phase !== 'redirect' &&
            props.points.some(
                (point) =>
                    (point.phases?.[entry.phase as TimingPhase] ?? null) !==
                    null,
            ),
    ),
);
const hasPhases = computed(() => phases.value.length > 0);
const mode = ref<'total' | 'phases'>('total');
const showPhases = computed(() => mode.value === 'phases' && hasPhases.value);

const phaseConfig = computed<ChartConfig>(() =>
    Object.fromEntries(
        phases.value.map((entry) => [
            entry.phase,
            { label: t(entry.label), color: entry.color },
        ]),
    ),
);
const phaseAccessors = computed(() =>
    phases.value.map(
        (entry) => (d: Datum) => d[entry.phase as TimingPhase] ?? 0,
    ),
);
const phaseColors = computed(() => phases.value.map((entry) => entry.color));
const phaseTooltip = computed(() =>
    componentToString(phaseConfig.value, ChartTooltipContent, {
        labelFormatter: (x: number | Date) => tickFormat(Number(x)),
    }),
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
    <div
        v-if="hasData && hasPhases"
        class="mb-2 flex justify-end gap-1 text-xs"
        role="group"
        :aria-label="t('Latency view')"
        data-test="latency-mode"
    >
        <button
            v-for="option in ['total', 'phases'] as const"
            :key="option"
            type="button"
            class="rounded-md px-2 py-1 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            :class="
                mode === option
                    ? 'bg-secondary text-secondary-foreground'
                    : 'text-muted-foreground hover:text-foreground'
            "
            :aria-pressed="mode === option"
            :data-test="`latency-mode-${option}`"
            @click="mode = option"
        >
            {{ option === 'total' ? t('Average and max') : t('By phase') }}
        </button>
    </div>
    <ChartContainer
        v-if="hasData && showPhases"
        :config="phaseConfig"
        class="aspect-auto h-56"
        cursor
        data-test="latency-phases"
    >
        <VisXYContainer
            :data="data"
            :margin="{ left: 4, right: 4 }"
            :y-domain="[0, undefined]"
        >
            <VisArea
                :x="(d: Datum) => d.x"
                :y="phaseAccessors"
                :color="phaseColors"
                :opacity="0.7"
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
            <ChartCrosshair :template="phaseTooltip" :color="phaseColors" />
        </VisXYContainer>
    </ChartContainer>
    <ChartContainer
        v-else-if="hasData"
        :config="config"
        class="aspect-auto h-56"
        cursor
    >
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
