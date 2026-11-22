<script setup lang="ts">
import { useId } from 'vue';

defineOptions({
    inheritAttrs: false,
});

/*
 * Veyra mark (ADR-035): the pulse glyph knocked out of a rounded square, so
 * it reads on any background in the current text colour (`text-primary` by
 * default).
 */
const maskId = `veyra-mark-${useId()}`;
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 32 32"
        class="text-primary"
        v-bind="$attrs"
        aria-hidden="true"
    >
        <defs>
            <mask :id="maskId">
                <rect width="32" height="32" fill="white" />
                <path
                    d="M6 16.5h5l2.6-7 5.2 14 2.9-8.75 1.45 1.75H26"
                    fill="none"
                    stroke="black"
                    stroke-width="2.75"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                />
            </mask>
        </defs>
        <rect
            width="32"
            height="32"
            rx="8"
            fill="currentColor"
            :mask="`url(#${maskId})`"
        />
    </svg>
</template>
