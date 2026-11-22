<script setup lang="ts">
import type { LucideIcon } from '@lucide/vue';
import { cn } from '@/lib/utils';

/*
 * Shared empty state: an icon, a title, an optional explanation and an
 * optional call to action in the default slot. `tone="success"` is for
 * "nothing wrong" states (no incidents), never for missing data.
 */
withDefaults(
    defineProps<{
        icon: LucideIcon;
        title?: string;
        description?: string;
        tone?: 'neutral' | 'success';
    }>(),
    { title: undefined, description: undefined, tone: 'neutral' },
);
</script>

<template>
    <div
        class="flex flex-1 flex-col items-center justify-center rounded-xl border border-dashed p-10 text-center"
    >
        <div
            :class="
                cn(
                    'mb-4 flex size-12 items-center justify-center rounded-full',
                    tone === 'success'
                        ? 'bg-status-up-soft text-status-up-soft-foreground'
                        : 'bg-accent text-accent-foreground',
                )
            "
            :data-tone="tone"
        >
            <component :is="icon" class="size-6" aria-hidden="true" />
        </div>
        <h2 v-if="title" class="text-lg font-semibold tracking-tight">
            {{ title }}
        </h2>
        <p
            v-if="description"
            :class="
                cn('max-w-sm text-sm text-muted-foreground', title && 'mt-1')
            "
        >
            {{ description }}
        </p>
        <div v-if="$slots.default" class="mt-4 flex flex-wrap gap-2">
            <slot />
        </div>
    </div>
</template>
