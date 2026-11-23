<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Tag } from '@lucide/vue';
import { computed } from 'vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { useTranslations } from '@/lib/i18n';

/** Filters the current page by one tag through the `tag` query parameter (ADR-047). */
const props = defineProps<{
    tags: string[];
    current: string | null;
    href: string;
}>();

const { t } = useTranslations();

const options = computed(() => [
    { value: '', label: t('All tags') },
    ...props.tags.map((tag) => ({ value: tag, label: tag })),
]);

const selected = computed({
    get: () => props.current ?? '',
    set: (value: string) =>
        router.get(props.href, value === '' ? {} : { tag: value }, {
            preserveScroll: true,
            preserveState: true,
        }),
});
</script>

<template>
    <label
        v-if="tags.length > 0 || current"
        class="flex items-center gap-2 text-sm text-muted-foreground"
    >
        <Tag class="size-4" aria-hidden="true" />
        <span class="sr-only">{{ t('Filter by tag') }}</span>
        <NativeSelect
            v-model="selected"
            :options="options"
            data-test="tag-filter"
        />
    </label>
</template>
