<script setup lang="ts">
import AppFooter from '@/components/AppFooter.vue';
</script>

<template>
    <div class="flex min-h-svh flex-col bg-background text-foreground">
        <main class="mx-auto w-full max-w-3xl flex-1 px-4 py-8 sm:py-12">
            <slot />
        </main>
        <div class="mx-auto w-full max-w-3xl">
            <AppFooter />
        </div>
    </div>
</template>
