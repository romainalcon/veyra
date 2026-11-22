<script setup lang="ts">
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslations } from '@/lib/i18n';
import { contrastRatio, PAGE_BACKGROUNDS } from '@/lib/statusPageBranding';

defineProps<{ disabled?: boolean; error?: string }>();
const model = defineModel<string>({ required: true });

const { t } = useTranslations();

const valid = computed(() => /^#[0-9a-f]{6}$/i.test(model.value));

/*
 * Mirrors the server decision (AccentColor): the accent is used as text only
 * where it reaches 4.5:1; buttons pick black or white automatically.
 */
const checks = computed(() => {
    if (!valid.value) {
        return null;
    }

    const color = model.value.toLowerCase();

    return {
        light: contrastRatio(color, PAGE_BACKGROUNDS.light),
        dark: contrastRatio(color, PAGE_BACKGROUNDS.dark),
        foreground:
            contrastRatio(color, '#000000') >= contrastRatio(color, '#ffffff')
                ? '#000000'
                : '#ffffff',
    };
});

const ratio = (value: number) => `${value.toFixed(1)}:1`;
</script>

<template>
    <fieldset class="space-y-2" data-test="accent-color-field">
        <legend class="text-sm font-medium">
            {{ t('Accent colour (optional)') }}
        </legend>
        <p v-if="disabled" class="text-xs text-muted-foreground">
            {{ t('Branding is not included in this organization’s plan.') }}
        </p>
        <div class="flex flex-wrap items-center gap-2">
            <input
                type="color"
                class="h-9 w-12 cursor-pointer rounded-md border bg-transparent p-1 disabled:cursor-not-allowed disabled:opacity-50"
                :value="valid ? model : '#4f46e5'"
                :disabled="disabled"
                :aria-label="t('Pick a colour')"
                @input="model = ($event.target as HTMLInputElement).value"
            />
            <Label for="accent_color" class="sr-only">{{
                t('Accent colour (optional)')
            }}</Label>
            <Input
                id="accent_color"
                v-model="model"
                name="accent_color"
                class="w-32 font-mono"
                maxlength="7"
                placeholder="#4f46e5"
                :disabled="disabled"
            />
            <Button
                v-if="model"
                type="button"
                variant="ghost"
                size="sm"
                :disabled="disabled"
                @click="model = ''"
            >
                {{ t('Remove') }}
            </Button>
            <span
                v-if="checks"
                class="inline-flex h-8 items-center rounded-md px-3 text-sm font-medium"
                :style="{ backgroundColor: model, color: checks.foreground }"
                data-test="accent-preview"
                >{{ t('Subscribe') }}</span
            >
        </div>
        <ul
            v-if="checks"
            class="space-y-1 text-xs text-muted-foreground"
            data-test="accent-contrast"
        >
            <li>
                {{
                    checks.light >= 4.5
                        ? t('Light theme: :ratio, used for links.', {
                              ratio: ratio(checks.light),
                          })
                        : t(
                              'Light theme: :ratio, too low for text; links keep the neutral colour.',
                              { ratio: ratio(checks.light) },
                          )
                }}
            </li>
            <li>
                {{
                    checks.dark >= 4.5
                        ? t('Dark theme: :ratio, used for links.', {
                              ratio: ratio(checks.dark),
                          })
                        : t(
                              'Dark theme: :ratio, too low for text; links keep the neutral colour.',
                              { ratio: ratio(checks.dark) },
                          )
                }}
            </li>
        </ul>
        <InputError :message="error" />
    </fieldset>
</template>
