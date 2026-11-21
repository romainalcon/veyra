<script setup lang="ts">
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import type { MonitorStatus } from '@/types';
import { useTranslations } from '@/lib/i18n';

const { t } = useTranslations();

const props = defineProps<{ status: MonitorStatus }>();

const styles: Record<MonitorStatus, { label: string; class: string }> = {
    up: {
        label: 'Up',
        class: 'bg-emerald-600 text-white hover:bg-emerald-600 dark:bg-emerald-500',
    },
    degraded: {
        label: 'Degraded',
        class: 'bg-amber-500 text-white hover:bg-amber-500 dark:bg-amber-500',
    },
    down: {
        label: 'Down',
        class: 'bg-red-600 text-white hover:bg-red-600 dark:bg-red-500',
    },
    unknown: {
        label: 'Unknown',
        class: 'bg-muted text-muted-foreground hover:bg-muted',
    },
    maintenance: {
        label: 'Maintenance',
        class: 'bg-sky-600 text-white hover:bg-sky-600 dark:bg-sky-500',
    },
};

const style = computed(() => styles[props.status] ?? styles.unknown);
</script>

<template>
    <Badge
        :class="cn('gap-1.5', style.class)"
        :data-status="status"
        data-test="status-badge"
    >
        <span class="size-1.5 rounded-full bg-current" aria-hidden="true" />
        {{ t(style.label) }}
    </Badge>
</template>
