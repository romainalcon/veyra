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
        <component
            :is="style.icon"
            :class="['size-4 shrink-0', style.text]"
            aria-hidden="true"
        />
        {{ t(style.label) }}
    </span>
</template>
