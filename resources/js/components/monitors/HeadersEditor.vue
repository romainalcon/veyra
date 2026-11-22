<script setup lang="ts">
import { Plus, Trash2 } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTranslations } from '@/lib/i18n';

const { t } = useTranslations();

/**
 * Edits custom request headers. Values of stored headers are never sent to
 * the browser: in edit mode only their names are displayed until the user
 * chooses to replace them.
 */
const headers = defineModel<{ name: string; value: string }[]>({
    required: true,
});

defineProps<{
    errors?: Record<string, string | undefined>;
    max?: number;
}>();

const add = () => {
    headers.value = [...headers.value, { name: '', value: '' }];
};
const remove = (index: number) => {
    headers.value = headers.value.filter((_, position) => position !== index);
};
</script>

<template>
    <div class="space-y-2" data-test="headers-editor">
        <div
            v-for="(header, index) in headers"
            :key="index"
            class="flex flex-col gap-2 sm:flex-row sm:items-start"
        >
            <div class="flex-1">
                <Input
                    v-model="header.name"
                    :name="`headers[${index}][name]`"
                    placeholder="Authorization"
                    autocomplete="off"
                    :aria-label="
                        t('Header :number name', { number: index + 1 })
                    "
                />
                <p
                    v-if="errors?.[`headers.${index}.name`]"
                    class="mt-1 text-sm text-destructive-foreground"
                >
                    {{ errors[`headers.${index}.name`] }}
                </p>
            </div>
            <div class="flex-1">
                <Input
                    v-model="header.value"
                    :name="`headers[${index}][value]`"
                    type="password"
                    :placeholder="t('Value')"
                    autocomplete="off"
                    :aria-label="
                        t('Header :number value', { number: index + 1 })
                    "
                />
                <p
                    v-if="errors?.[`headers.${index}.value`]"
                    class="mt-1 text-sm text-destructive-foreground"
                >
                    {{ errors[`headers.${index}.value`] }}
                </p>
            </div>
            <Button
                type="button"
                variant="ghost"
                size="icon"
                :aria-label="t('Remove header :number', { number: index + 1 })"
                @click="remove(index)"
            >
                <Trash2 class="size-4" />
            </Button>
        </div>
        <Button
            type="button"
            variant="outline"
            size="sm"
            :disabled="headers.length >= (max ?? 10)"
            @click="add"
        >
            <Plus class="size-4" />
            {{ t('Add header') }}
        </Button>
    </div>
</template>
