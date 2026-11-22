<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslations } from '@/lib/i18n';
import {
    describeSeries,
    ISO_WEEKDAYS,
    todayIn,
    weekdayName,
} from '@/lib/maintenance';
import type {
    MaintenanceFrequency,
    MaintenanceMonitorOption,
    MaintenanceSeries,
} from '@/types';
import type { RouteFormDefinition } from '@/wayfinder';

const { t } = useTranslations();

const props = defineProps<{
    action: RouteFormDefinition<'post'> | RouteFormDefinition<'put'>;
    monitors: MaintenanceMonitorOption[];
    maxDurationHours: number;
    timezone: string;
    series?: MaintenanceSeries;
    submitLabel: string;
}>();

const frequency = ref<MaintenanceFrequency>(
    props.series?.frequency ?? 'weekly',
);
const weekdays = ref<number[]>(props.series?.weekdays ?? [1]);
const monthDay = ref<number>(props.series?.month_day ?? 1);
const startTime = ref(props.series?.start_time ?? '03:00');
const durationHours = ref(
    Math.floor((props.series?.duration_minutes ?? 60) / 60),
);
const durationMinutes = ref((props.series?.duration_minutes ?? 60) % 60);
const selectedMonitors = ref<string[]>(
    props.series?.monitors.map((monitor) => monitor.id) ?? [],
);

const totalMinutes = computed(
    () =>
        (Number(durationHours.value) || 0) * 60 +
        (Number(durationMinutes.value) || 0),
);

const frequencyOptions = computed(() => [
    { value: 'daily', label: t('Daily') },
    { value: 'weekly', label: t('Weekly') },
    { value: 'monthly', label: t('Monthly') },
]);

const summary = computed(() =>
    describeSeries({
        frequency: frequency.value,
        weekdays: weekdays.value,
        month_day: monthDay.value,
        start_time: startTime.value,
        duration_minutes: totalMinutes.value,
    }),
);

const canSubmit = computed(
    () =>
        selectedMonitors.value.length > 0 &&
        totalMinutes.value > 0 &&
        (frequency.value !== 'weekly' || weekdays.value.length > 0),
);
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
                :placeholder="t('Nightly backup')"
                :default-value="series?.title"
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
                :value="series?.description ?? ''"
            />
            <p class="text-xs text-muted-foreground">
                {{ t('May be shown publicly on status pages.') }}
            </p>
            <InputError :message="errors.description" />
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label for="frequency">{{ t('Repeats') }}</Label>
                <NativeSelect
                    id="frequency"
                    v-model="frequency"
                    name="frequency"
                    class="w-full"
                    :options="frequencyOptions"
                    data-test="series-frequency"
                />
                <InputError :message="errors.frequency" />
            </div>

            <div v-if="frequency === 'monthly'" class="grid gap-2">
                <Label for="month_day">{{ t('Day of the month') }}</Label>
                <Input
                    id="month_day"
                    v-model.number="monthDay"
                    name="month_day"
                    type="number"
                    min="1"
                    max="31"
                    required
                />
                <p class="text-xs text-muted-foreground">
                    {{
                        t(
                            'Shorter months use their last day (for example 28 or 30).',
                        )
                    }}
                </p>
                <InputError :message="errors.month_day" />
            </div>
        </div>

        <fieldset v-if="frequency === 'weekly'" class="space-y-2">
            <legend class="text-sm font-medium">{{ t('Days') }}</legend>
            <div class="flex flex-wrap gap-3">
                <label
                    v-for="day in ISO_WEEKDAYS"
                    :key="day"
                    class="flex items-center gap-2 text-sm"
                >
                    <input
                        v-model="weekdays"
                        type="checkbox"
                        name="weekdays[]"
                        :value="day"
                        class="size-4 accent-primary"
                    />
                    {{ weekdayName(day, 'short') }}
                </label>
            </div>
            <InputError :message="errors.weekdays ?? errors['weekdays.0']" />
        </fieldset>

        <div class="grid gap-4 sm:grid-cols-3">
            <div class="grid gap-2">
                <Label for="start_time">{{ t('Start time') }}</Label>
                <Input
                    id="start_time"
                    v-model="startTime"
                    name="start_time"
                    type="time"
                    required
                />
                <InputError :message="errors.start_time" />
            </div>
            <div class="grid gap-2">
                <Label for="duration_hours">{{ t('Duration (hours)') }}</Label>
                <Input
                    id="duration_hours"
                    v-model.number="durationHours"
                    type="number"
                    min="0"
                    :max="maxDurationHours"
                />
            </div>
            <div class="grid gap-2">
                <Label for="duration_minutes_part">{{
                    t('Duration (minutes)')
                }}</Label>
                <Input
                    id="duration_minutes_part"
                    v-model.number="durationMinutes"
                    type="number"
                    min="0"
                    max="59"
                    step="5"
                />
            </div>
            <input
                type="hidden"
                name="duration_minutes"
                :value="totalMinutes"
            />
        </div>
        <InputError class="-mt-3" :message="errors.duration_minutes" />

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label for="starts_on">{{ t('First day') }}</Label>
                <Input
                    id="starts_on"
                    name="starts_on"
                    type="date"
                    required
                    :default-value="series?.starts_on ?? todayIn(timezone)"
                />
                <InputError :message="errors.starts_on" />
            </div>
            <div class="grid gap-2">
                <Label for="ends_on">{{ t('Last day (optional)') }}</Label>
                <Input
                    id="ends_on"
                    name="ends_on"
                    type="date"
                    :default-value="series?.ends_on ?? ''"
                />
                <InputError :message="errors.ends_on" />
            </div>
        </div>

        <p
            class="-mt-3 rounded-md bg-muted px-3 py-2 text-sm"
            data-test="series-summary"
        >
            {{ summary }}
            <span class="block text-xs text-muted-foreground">
                {{
                    t(
                        'Times in the organization time zone (:zone); each occurrence lasts at most :hours hours.',
                        { zone: timezone, hours: maxDurationHours },
                    )
                }}
                <template v-if="series">
                    {{
                        t(
                            'Changes apply to future occurrences; running and past ones are kept.',
                        )
                    }}</template
                >
            </span>
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
            :disabled="processing || !canSubmit"
            data-test="save-series-button"
        >
            <Spinner v-if="processing" />
            {{ submitLabel }}
        </Button>
    </Form>
</template>
