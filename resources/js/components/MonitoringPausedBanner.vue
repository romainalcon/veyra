<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { PauseCircle } from '@lucide/vue';
import { computed } from 'vue';

const page = usePage();
const monitoring = computed(() => page.props.system?.monitoring);

const reasons = computed(() =>
    (monitoring.value?.reasons ?? [])
        .map((reason) =>
            reason === 'scheduler' ? 'the scheduler' : 'the Horizon workers',
        )
        .join(' and '),
);
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
            <span class="font-medium">Checks are paused</span> because
            {{ reasons }} {{ monitoring.reasons.length > 1 ? 'are' : 'is' }} not
            running. Monitor states show <strong>Unknown</strong>: this is not
            an outage of your services.
        </p>
    </div>
</template>
