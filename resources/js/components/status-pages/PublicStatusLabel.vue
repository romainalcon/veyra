<script setup lang="ts">
import { computed } from 'vue';
import { useTranslations } from '@/lib/i18n';
import { publicStatusStyle } from '@/lib/statusPage';
import type { PublicComponentStatus } from '@/types';

const props = defineProps<{ status: PublicComponentStatus }>();

const style = computed(() => publicStatusStyle(props.status));
const { t } = useTranslations();
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 text-sm"
        :data-status="status"
        data-test="public-status"
    >
        <span :class="['size-2 rounded-full', style.dot]" aria-hidden="true" />
        {{ t(style.label) }}
    </span>
</template>
