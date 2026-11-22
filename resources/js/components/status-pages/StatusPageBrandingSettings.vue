<script setup lang="ts">
import { Form, router } from '@inertiajs/vue3';
import { ImageUp, Trash2 } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslations } from '@/lib/i18n';
import { destroy, store } from '@/routes/organizations/status-pages/assets';
import type { StatusPageAudience, StatusPageAssetKind } from '@/types';

const props = defineProps<{
    params: [string, string];
    assets: StatusPageAudience['assets'];
    limits: StatusPageAudience['limits'];
    canBrand: boolean;
}>();

const { t } = useTranslations();

const kinds: { kind: StatusPageAssetKind; label: string; hint: string }[] = [
    {
        kind: 'logo',
        label: 'Logo',
        hint: 'PNG, JPEG or WebP, up to :size KB and :pixels pixels.',
    },
    {
        kind: 'favicon',
        label: 'Favicon',
        hint: 'PNG, ICO or WebP, up to :size KB and :pixels pixels.',
    },
];

const args = (kind: StatusPageAssetKind) =>
    [...props.params, kind] as [string, string, string];

const remove = (kind: StatusPageAssetKind) =>
    router.visit(destroy(args(kind)), { preserveScroll: true });
</script>

<template>
    <section
        class="space-y-4 rounded-lg border p-4"
        data-test="branding-settings"
    >
        <div>
            <h2 class="text-base font-medium">{{ t('Branding') }}</h2>
            <p class="text-sm text-muted-foreground">
                {{
                    t(
                        'Your logo and favicon on the public page. SVG files are not accepted.',
                    )
                }}
            </p>
            <p v-if="!canBrand" class="mt-1 text-sm text-muted-foreground">
                {{ t('Branding is not included in this organization’s plan.') }}
            </p>
        </div>

        <div
            v-for="item in kinds"
            :key="item.kind"
            class="flex flex-col gap-3 sm:flex-row sm:items-start"
            :data-test="`asset-${item.kind}`"
        >
            <div
                class="flex size-16 shrink-0 items-center justify-center rounded-md border bg-muted/40"
            >
                <img
                    v-if="assets[item.kind]"
                    :src="assets[item.kind]!.url"
                    :alt="t(item.label)"
                    class="max-h-14 max-w-14 object-contain"
                />
                <ImageUp
                    v-else
                    class="size-5 text-muted-foreground"
                    aria-hidden="true"
                />
            </div>
            <Form
                v-if="canBrand"
                v-bind="store.form(args(item.kind))"
                :options="{ preserveScroll: true }"
                reset-on-success
                class="grid flex-1 gap-1"
                v-slot="{ errors, processing }"
            >
                <Label :for="`asset-file-${item.kind}`">{{
                    t(item.label)
                }}</Label>
                <div class="flex flex-col gap-2 sm:flex-row">
                    <Input
                        :id="`asset-file-${item.kind}`"
                        type="file"
                        name="file"
                        required
                        :accept="limits[item.kind].accept"
                    />
                    <Button
                        type="submit"
                        variant="outline"
                        :disabled="processing"
                    >
                        <Spinner v-if="processing" />
                        {{ t('Upload') }}
                    </Button>
                </div>
                <p class="text-xs text-muted-foreground">
                    {{
                        t(item.hint, {
                            size: limits[item.kind].max_kb,
                            pixels: limits[item.kind].max_dimension,
                        })
                    }}
                </p>
                <InputError :message="errors.file ?? errors.limit" />
            </Form>
            <p v-else class="flex-1 text-sm">{{ t(item.label) }}</p>
            <Button
                v-if="assets[item.kind]"
                type="button"
                variant="ghost"
                size="sm"
                :data-test="`remove-${item.kind}`"
                @click="remove(item.kind)"
            >
                <Trash2 class="size-4" />
                {{ t('Remove') }}
            </Button>
        </div>
    </section>
</template>
