<script setup lang="ts">
import { computed } from 'vue';
import { useTranslations } from '@/lib/i18n';
import { timingSegments } from '@/lib/timings';
import type { CheckTimings } from '@/types';

const { t } = useTranslations();

const props = withDefaults(
    defineProps<{
        timings: CheckTimings | null | undefined;
        /** One thin bar for table rows; the detailed view lists each phase. */
        compact?: boolean;
    }>(),
    { compact: false },
);

const segments = computed(() => timingSegments(props.timings));
const total = computed(() =>
    segments.value.reduce((sum, segment) => sum + segment.ms, 0),
);

/** Offset and width of each phase as a share of the total (waterfall). */
const bars = computed(() => {
    let offset = 0;

    return segments.value.map((segment) => {
        const share = total.value > 0 ? (segment.ms / total.value) * 100 : 0;
        const width = Math.min(
            Math.max(share, 0.5),
            Math.max(100 - offset, 0.5),
        );
        const bar = { ...segment, offset: Math.min(offset, 99.5), width };
        offset += share;

        return bar;
    });
});

const summary = computed(() =>
    segments.value
        .map((segment) => `${t(segment.label)} ${segment.ms} ms`)
        .join(', '),
);
</script>

<template>
    <span
        v-if="segments.length === 0"
        class="text-xs text-muted-foreground"
        data-test="timings-empty"
    >
        {{ compact ? '—' : t('No timing breakdown for this check.') }}
    </span>
    <div
        v-else-if="compact"
        class="flex h-2 w-24 overflow-hidden rounded-full bg-muted"
        role="img"
        :aria-label="summary"
        :title="summary"
        data-test="timings-compact"
    >
        <span
            v-for="bar in bars"
            :key="bar.phase"
            :class="bar.colorClass"
            :style="{ width: `${bar.width}%` }"
        />
    </div>
    <dl v-else class="space-y-1.5 text-xs" data-test="timings-waterfall">
        <div
            v-for="bar in bars"
            :key="bar.phase"
            class="grid grid-cols-[8rem_1fr_4rem] items-center gap-2"
            :data-test="`timing-${bar.phase}`"
        >
            <dt class="text-muted-foreground">{{ t(bar.label) }}</dt>
            <dd class="relative h-2 rounded-full bg-muted">
                <span
                    class="absolute inset-y-0 rounded-full"
                    :class="bar.colorClass"
                    :style="{ left: `${bar.offset}%`, width: `${bar.width}%` }"
                />
            </dd>
            <dd class="text-right tabular-nums">{{ bar.ms }} ms</dd>
        </div>
    </dl>
</template>
