<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { isoToLocalInput, localInputToIso } from '@/lib/datetime';
import type { MaintenanceMonitorOption, MaintenanceWindow } from '@/types';
import type { RouteFormDefinition } from '@/wayfinder';
import { useTranslations } from '@/lib/i18n';

const { t } = useTranslations();

const props = defineProps<{
    action: RouteFormDefinition<'post'>;
    monitors: MaintenanceMonitorOption[];
    maxDurationHours: number;
    window?: MaintenanceWindow;
    submitLabel: string;
}>();

const defaultStart = () => {
    const start = new Date(Date.now() + 60 * 60 * 1000);
    start.setMinutes(0, 0, 0);

    return start.toISOString();
};

const started = computed(
    () => props.window !== undefined && props.window.status === 'active',
);

const startsLocal = ref(
    isoToLocalInput(props.window?.starts_at ?? defaultStart()),
);
const endsLocal = ref(
    isoToLocalInput(
        props.window?.ends_at ??
            new Date(
                new Date(defaultStart()).getTime() + 60 * 60 * 1000,
            ).toISOString(),
    ),
);
const selectedMonitors = ref<string[]>(
    props.window?.monitors.map((monitor) => monitor.id) ?? [],
);

const timeZone = Intl.DateTimeFormat().resolvedOptions().timeZone;
</script>

<template>
    <Form
        v-bind="action"
        :options="{ preserveScroll: true }"
        class="space-y-6"
        v-slot="{ errors, processing }"
    >
        <div class="grid gap-2">
            <Label for="title">{{ t('Title') }}</Label>
            <Input
                id="title"
                name="title"
                required
                maxlength="120"
                :placeholder="t('Database upgrade')"
                :default-value="window?.title"
            />
            <InputError :message="errors.title" />
        </div>

        <div class="grid gap-2">
            <Label for="description">{{ t('Description (optional)') }}</Label>
            <textarea
                id="description"
                name="description"
                rows="3"
                maxlength="2000"
                class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                :placeholder="
                    t('What is being done and what users may notice.')
                "
                :value="window?.description ?? ''"
            />
            <p class="text-xs text-muted-foreground">
                {{ t('May be shown publicly on status pages.') }}
            </p>
            <InputError :message="errors.description" />
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label for="starts_at_local">{{ t('Starts') }}</Label>
                <Input
                    id="starts_at_local"
                    v-model="startsLocal"
                    type="datetime-local"
                    required
                    :disabled="started"
                />
                <input
                    type="hidden"
                    name="starts_at"
                    :value="localInputToIso(startsLocal)"
                />
                <InputError :message="errors.starts_at" />
            </div>
            <div class="grid gap-2">
                <Label for="ends_at_local">{{ t('Ends') }}</Label>
                <Input
                    id="ends_at_local"
                    v-model="endsLocal"
                    type="datetime-local"
                    required
                />
                <input
                    type="hidden"
                    name="ends_at"
                    :value="localInputToIso(endsLocal)"
                />
                <InputError :message="errors.ends_at" />
            </div>
        </div>
        <p class="-mt-3 text-xs text-muted-foreground">
            {{
                t('Times in :zone; at most :hours hours.', {
                    zone: timeZone,
                    hours: maxDurationHours,
                })
            }}
            <template v-if="started">
                {{ t('A running window keeps its start.') }}</template
            >
        </p>

        <fieldset class="space-y-3">
            <legend class="text-sm font-medium">{{ t('Monitors') }}</legend>
            <p
                v-if="monitors.length === 0"
                class="text-xs text-muted-foreground"
            >
                {{ t('No monitors in this organization yet.') }}
            </p>
            <div class="grid gap-2 sm:grid-cols-2">
                <label
                    v-for="monitor in monitors"
                    :key="monitor.id"
                    class="flex items-center gap-3 text-sm"
                >
                    <input
                        v-model="selectedMonitors"
                        type="checkbox"
                        name="monitor_ids[]"
                        :value="monitor.id"
                        class="size-4 accent-primary"
                    />
                    <span class="truncate">{{ monitor.name }}</span>
                </label>
            </div>
            <InputError
                :message="errors.monitor_ids ?? errors['monitor_ids.0']"
            />
        </fieldset>

        <Button
            type="submit"
            :disabled="processing || selectedMonitors.length === 0"
            data-test="save-maintenance-button"
        >
            <Spinner v-if="processing" />
            {{ submitLabel }}
        </Button>
    </Form>
</template>
