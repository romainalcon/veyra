<script setup lang="ts">
import VisAxis from '@unovis/vue/components/axis';
import VisStackedBar from '@unovis/vue/components/stacked-bar';
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
import type { IncidentDay } from '@/types';

const props = defineProps<{ days: IncidentDay[] }>();

type Datum = { x: number; incidents: number; downtime: number };

const data = computed<Datum[]>(() =>
    props.days.map((day, index) => ({
        x: index,
        incidents: day.incidents,
        downtime: day.downtime_minutes,
    })),
);

const config = {
    incidents: { label: 'Incidents', color: 'var(--chart-1)' },
    downtime: { label: 'Downtime (min)', color: 'var(--chart-3)' },
} satisfies ChartConfig;

const tickFormat = (index: number) => {
    const day = props.days[Math.round(index)];

    return day ? formatBucketLabel(day.day, '30d') : '';
};

const tooltip = componentToString(config, ChartTooltipContent, {
    labelFormatter: (x: number | Date) => tickFormat(Number(x)),
});
</script>

<template>
    <ChartContainer :config="config" class="aspect-auto h-48" cursor>
        <VisXYContainer :data="data" :margin="{ left: 4, right: 4 }">
            <VisStackedBar
                :x="(d: Datum) => d.x"
                :y="(d: Datum) => d.incidents"
                :color="config.incidents.color"
                :bar-padding="0.2"
                :rounded-corners="2"
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
                :num-ticks="3"
                :tick-line="false"
                :domain-line="false"
            />
            <ChartTooltip />
            <ChartCrosshair
                :template="tooltip"
                :color="config.incidents.color"
            />
        </VisXYContainer>
    </ChartContainer>
</template>
