<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useTranslations } from '@/lib/i18n';

const page = usePage();
const system = computed(() => page.props.system);
const { t } = useTranslations();
</script>

<template>
    <footer
        class="mt-auto flex flex-wrap items-center gap-x-3 gap-y-1 px-6 py-4 text-xs text-muted-foreground"
    >
        <span>Veyra {{ system?.version }}</span>
        <span aria-hidden="true">·</span>
        <span>AGPL-3.0</span>
        <template v-if="system?.sourceUrl">
            <span aria-hidden="true">·</span>
            <a
                :href="system.sourceUrl"
                class="hover:underline"
                target="_blank"
                rel="noopener noreferrer"
                >{{ t('Source code') }}</a
            >
        </template>
    </footer>
</template>
