<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { PauseCircle } from '@lucide/vue';
import { computed } from 'vue';
import { useTranslations } from '@/lib/i18n';

const page = usePage();
const { t } = useTranslations();
const monitoring = computed(() => page.props.system?.monitoring);

/*
 * Whole sentences per case, so that each language can phrase them freely.
 */
const message = computed(() => {
    const reasons = monitoring.value?.reasons ?? [];

    if (reasons.length > 1) {
        return t(
            'because the scheduler and the Horizon workers are not running.',
        );
    }

    return reasons[0] === 'scheduler'
        ? t('because the scheduler is not running.')
        : t('because the Horizon workers are not running.');
});
</script>

<template>
    <div
        v-if="monitoring?.paused"
        role="status"
        class="flex items-start gap-3 border-b border-amber-300 bg-amber-50 px-6 py-3 text-sm text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-200"
        data-test="monitoring-paused-banner"
    >
        <PauseCircle class="mt-0.5 size-4 shrink-0" />
        <p>
            <span class="font-medium">{{ t('Checks are paused') }}</span>
            {{ message }}
            {{
                t(
                    'Monitor states show Unknown: this is not an outage of your services.',
                )
            }}
        </p>
    </div>
</template>
