<script setup lang="ts">
import { X } from '@lucide/vue';
import { computed, ref, useId } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { useTranslations } from '@/lib/i18n';

/**
 * Tag editor: type a tag and press Enter or comma; tags are lower-cased like
 * on the server (ADR-047). Submits `tags[]` plus `tags_submitted` so an empty
 * list still replaces the stored tags.
 */
const props = defineProps<{
    suggestions: string[];
    max: number;
    maxLength: number;
    error?: string;
}>();

const tags = defineModel<string[]>({ required: true });

const { t } = useTranslations();
const draft = ref('');
const listId = useId();

const normalize = (value: string) =>
    value.trim().replace(/\s+/g, ' ').toLowerCase();

const remaining = computed(() =>
    props.suggestions.filter((tag) => !tags.value.includes(tag)),
);

const add = () => {
    const tag = normalize(draft.value).slice(0, props.maxLength);
    draft.value = '';

    if (
        tag === '' ||
        tags.value.includes(tag) ||
        tags.value.length >= props.max
    ) {
        return;
    }

    tags.value = [...tags.value, tag];
};

const remove = (tag: string) => {
    tags.value = tags.value.filter((value) => value !== tag);
};

const onKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Enter' || event.key === ',') {
        event.preventDefault();
        add();
    } else if (
        event.key === 'Backspace' &&
        draft.value === '' &&
        tags.value.length > 0
    ) {
        remove(tags.value[tags.value.length - 1]);
    }
};
</script>

<template>
    <div class="grid gap-2">
        <input type="hidden" name="tags_submitted" value="1" />
        <input
            v-for="tag in tags"
            :key="tag"
            type="hidden"
            name="tags[]"
            :value="tag"
        />

        <div
            v-if="tags.length > 0"
            class="flex flex-wrap gap-1.5"
            data-test="tag-list"
        >
            <Badge
                v-for="tag in tags"
                :key="tag"
                variant="secondary"
                class="gap-1"
            >
                {{ tag }}
                <button
                    type="button"
                    class="rounded-sm opacity-70 hover:opacity-100 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :aria-label="t('Remove tag :tag', { tag })"
                    @click="remove(tag)"
                >
                    <X class="size-3" />
                </button>
            </Badge>
        </div>

        <Input
            id="tags"
            v-model="draft"
            :list="listId"
            :maxlength="maxLength"
            :disabled="tags.length >= max"
            :placeholder="
                tags.length >= max
                    ? t('At most :count tags', { count: max })
                    : t('e.g. production, eu-west')
            "
            autocomplete="off"
            @keydown="onKeydown"
            @blur="add"
        />
        <datalist :id="listId">
            <option v-for="tag in remaining" :key="tag" :value="tag" />
        </datalist>
        <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
    </div>
</template>
