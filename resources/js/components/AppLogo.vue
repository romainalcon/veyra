<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import AppLogoMark from '@/components/AppLogoMark.vue';

const name = usePage().props.name;
</script>

<template>
    <AppLogoMark class="size-8 shrink-0 text-sidebar-primary" />
    <div class="ml-1 grid flex-1 text-left text-sm">
        <span
            class="mb-0.5 truncate leading-tight font-semibold tracking-tight"
            >{{ name }}</span
        >
    </div>
</template>
