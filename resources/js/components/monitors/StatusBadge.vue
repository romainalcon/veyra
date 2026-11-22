<script setup lang="ts">
import {
    Check,
    CircleHelp,
    TriangleAlert,
    Wrench,
    X,
    type LucideIcon,
} from '@lucide/vue';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { useTranslations } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { MonitorStatus } from '@/types';

const { t } = useTranslations();

const props = defineProps<{ status: MonitorStatus }>();

/*
 * Colour, icon and label all carry the state (ADR-035), so it never depends
 * on colour alone.
 */
const styles: Record<
    MonitorStatus,
    { label: string; icon: LucideIcon; class: string }
> = {
    up: {
        label: 'Up',
        icon: Check,
        class: 'bg-status-up text-status-up-foreground',
    },
    degraded: {
        label: 'Degraded',
        icon: TriangleAlert,
        class: 'bg-status-degraded text-status-degraded-foreground',
    },
    down: {
        label: 'Down',
        icon: X,
        class: 'bg-status-down text-status-down-foreground',
    },
    unknown: {
        label: 'Unknown',
        icon: CircleHelp,
        class: 'bg-status-unknown-soft text-status-unknown-soft-foreground border-border',
    },
    maintenance: {
        label: 'Maintenance',
        icon: Wrench,
        class: 'bg-status-maintenance text-status-maintenance-foreground',
    },
};

const style = computed(() => styles[props.status] ?? styles.unknown);
</script>

<template>
    <Badge
        :class="cn('gap-1', style.class)"
        :data-status="status"
        data-test="status-badge"
    >
        <component
            :is="style.icon"
            class="size-3"
            stroke-width="2.75"
            aria-hidden="true"
            :data-icon="status"
        />
        {{ t(style.label) }}
    </Badge>
</template>
