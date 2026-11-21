<script setup lang="ts">
import { Monitor, Moon, Sun } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useAppearance } from '@/composables/useAppearance';
import type { Appearance } from '@/types';
import { useTranslations } from '@/lib/i18n';

const { t } = useTranslations();

const { appearance, resolvedAppearance, updateAppearance } = useAppearance();

const options = [
    { value: 'light', icon: Sun, label: 'Light' },
    { value: 'dark', icon: Moon, label: 'Dark' },
    { value: 'system', icon: Monitor, label: 'System' },
] as const;

const triggerIcon = computed(() =>
    resolvedAppearance.value === 'dark' ? Moon : Sun,
);

const select = (value: unknown) => {
    if (value === 'light' || value === 'dark' || value === 'system') {
        updateAppearance(value as Appearance);
    }
};
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button
                variant="ghost"
                size="icon"
                :aria-label="t('Change theme')"
                data-test="appearance-toggle"
            >
                <component :is="triggerIcon" class="size-4" />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-36">
            <DropdownMenuRadioGroup
                :model-value="appearance"
                @update:model-value="select"
            >
                <DropdownMenuRadioItem
                    v-for="option in options"
                    :key="option.value"
                    :value="option.value"
                    class="gap-2"
                >
                    <component :is="option.icon" class="size-4" />
                    {{ t(option.label) }}
                </DropdownMenuRadioItem>
            </DropdownMenuRadioGroup>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
