<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <!-- Veyra pulse glyph (ADR-035): a single heartbeat on a flat line. -->
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2.25"
        stroke-linecap="round"
        stroke-linejoin="round"
        :class="className"
        v-bind="$attrs"
        aria-hidden="true"
    >
        <path d="M2.5 12.5h4.25l2.25-6 4.5 12 2.5-7.5 1.25 1.5h4.25" />
    </svg>
</template>
