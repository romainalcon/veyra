<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { usePublicNavigation } from '@/composables/usePublicNavigation';
import { useTranslations } from '@/lib/i18n';

const page = usePage();
const system = computed(() => page.props.system);
const { t } = useTranslations();
const footerLinks = usePublicNavigation('footer');
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
        <template v-for="link in footerLinks" :key="link.id">
            <span aria-hidden="true">·</span>
            <Link :href="link.href" class="hover:underline">{{
                link.title
            }}</Link>
        </template>
    </footer>
</template>
