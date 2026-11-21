<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Plus, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type {
    StatusPageComponentInput,
    StatusPageDetail,
    StatusPageFormOptions,
} from '@/types';
import type { RouteFormDefinition } from '@/wayfinder';
import { useTranslations } from '@/lib/i18n';

const { t } = useTranslations();

const props = defineProps<
    StatusPageFormOptions & {
        action: RouteFormDefinition<'post'>;
        statusPage?: StatusPageDetail;
        submitLabel: string;
    }
>();

const title = ref(props.statusPage?.title ?? '');
const slug = ref(props.statusPage?.slug ?? '');
const slugEdited = ref(props.statusPage !== undefined);
const isPublished = ref(props.statusPage?.is_published ?? false);
const showUptime = ref(props.statusPage?.show_uptime ?? true);
type EditableComponent = Omit<StatusPageComponentInput, 'group_name'> & {
    group_name: string;
};

const components = ref<EditableComponent[]>(
    props.statusPage?.components.map((component) => ({
        ...component,
        group_name: component.group_name ?? '',
    })) ?? [],
);
const monitorToAdd = ref('');

const slugify = (value: string) =>
    value
        .normalize('NFKD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 60);

const onTitleInput = () => {
    if (!slugEdited.value) {
        slug.value = slugify(title.value);
    }
};

const monitorName = (id: string) =>
    props.monitors.find((monitor) => monitor.id === id)?.name ?? t('Unknown');

const availableMonitors = computed(() =>
    props.monitors
        .filter(
            (monitor) =>
                !components.value.some(
                    (component) => component.monitor_id === monitor.id,
                ),
        )
        .map((monitor) => ({ value: monitor.id, label: monitor.name })),
);

const addComponent = () => {
    if (!monitorToAdd.value) {
        return;
    }

    components.value.push({
        monitor_id: monitorToAdd.value,
        display_name: monitorName(monitorToAdd.value),
        group_name: '',
    });
    monitorToAdd.value = '';
};

const move = (index: number, offset: number) => {
    const target = index + offset;

    if (target < 0 || target >= components.value.length) {
        return;
    }

    const [item] = components.value.splice(index, 1);
    components.value.splice(target, 0, item);
};

const remove = (index: number) => components.value.splice(index, 1);
</script>

<template>
    <Form
        v-bind="action"
        :options="{ preserveScroll: true }"
        class="space-y-6"
        v-slot="{ errors, processing }"
    >
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label for="title">{{ t('Title') }}</Label>
                <Input
                    id="title"
                    v-model="title"
                    name="title"
                    required
                    maxlength="120"
                    :placeholder="t('Acme status')"
                    @input="onTitleInput"
                />
                <InputError :message="errors.title" />
            </div>
            <div class="grid gap-2">
                <Label for="slug">{{ t('Public address') }}</Label>
                <div class="flex items-center gap-1 text-sm">
                    <span class="text-muted-foreground">/status/</span>
                    <Input
                        id="slug"
                        v-model="slug"
                        name="slug"
                        required
                        minlength="3"
                        maxlength="60"
                        pattern="[a-z0-9](?:[a-z0-9\-]*[a-z0-9])?"
                        placeholder="acme"
                        @input="slugEdited = true"
                    />
                </div>
                <InputError :message="errors.slug" />
            </div>
        </div>

        <div class="grid gap-2">
            <Label for="description">{{ t('Description (optional)') }}</Label>
            <textarea
                id="description"
                name="description"
                rows="2"
                maxlength="2000"
                class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                :value="statusPage?.description ?? ''"
            />
            <InputError :message="errors.description" />
        </div>

        <div class="flex flex-col gap-3 sm:flex-row sm:gap-8">
            <label class="flex items-center gap-3 text-sm">
                <input type="hidden" name="is_published" value="0" />
                <input
                    v-model="isPublished"
                    type="checkbox"
                    name="is_published"
                    value="1"
                    class="size-4 accent-primary"
                    data-test="publish-checkbox"
                />
                <span>{{
                    t('Published (anyone with the link can view it)')
                }}</span>
            </label>
            <label class="flex items-center gap-3 text-sm">
                <input type="hidden" name="show_uptime" value="0" />
                <input
                    v-model="showUptime"
                    type="checkbox"
                    name="show_uptime"
                    value="1"
                    class="size-4 accent-primary"
                />
                <span>{{ t('Show 90-day uptime') }}</span>
            </label>
        </div>

        <fieldset class="space-y-3">
            <legend class="text-sm font-medium">{{ t('Components') }}</legend>
            <p class="text-xs text-muted-foreground">
                {{
                    t(
                        'Each component shows one monitor under a public name. Monitor names, URLs and incident causes are never published.',
                    )
                }}
            </p>

            <ul
                v-if="components.length"
                class="divide-y rounded-lg border"
                data-test="components-editor"
            >
                <li
                    v-for="(component, index) in components"
                    :key="component.monitor_id"
                    class="grid gap-2 p-3 sm:grid-cols-[1fr_1fr_auto] sm:items-start"
                >
                    <input
                        type="hidden"
                        :name="`components[${index}][monitor_id]`"
                        :value="component.monitor_id"
                    />
                    <div class="grid gap-1">
                        <Label
                            :for="`component-name-${index}`"
                            class="text-xs text-muted-foreground"
                            >{{
                                t('Public name · :monitor', {
                                    monitor: monitorName(component.monitor_id),
                                })
                            }}</Label
                        >
                        <Input
                            :id="`component-name-${index}`"
                            v-model="component.display_name"
                            :name="`components[${index}][display_name]`"
                            required
                            maxlength="100"
                        />
                        <InputError
                            :message="
                                errors[`components.${index}.display_name`] ??
                                errors[`components.${index}.monitor_id`]
                            "
                        />
                    </div>
                    <div class="grid gap-1">
                        <Label
                            :for="`component-group-${index}`"
                            class="text-xs text-muted-foreground"
                            >{{ t('Group (optional)') }}</Label
                        >
                        <Input
                            :id="`component-group-${index}`"
                            v-model="component.group_name"
                            :name="`components[${index}][group_name]`"
                            maxlength="100"
                            :placeholder="t('Core services')"
                        />
                    </div>
                    <div class="flex gap-1 sm:pt-5">
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            :disabled="index === 0"
                            :aria-label="t('Move up')"
                            @click="move(index, -1)"
                        >
                            <ArrowUp class="size-4" />
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            :disabled="index === components.length - 1"
                            :aria-label="t('Move down')"
                            @click="move(index, 1)"
                        >
                            <ArrowDown class="size-4" />
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            :aria-label="t('Remove')"
                            @click="remove(index)"
                        >
                            <X class="size-4" />
                        </Button>
                    </div>
                </li>
            </ul>
            <p
                v-else
                class="rounded-lg border border-dashed p-4 text-center text-sm text-muted-foreground"
            >
                {{ t('No components yet.') }}
            </p>

            <div
                v-if="
                    availableMonitors.length &&
                    components.length < maxComponents
                "
                class="flex gap-2"
            >
                <NativeSelect
                    id="monitor-to-add"
                    v-model="monitorToAdd"
                    :options="[
                        { value: '', label: t('Select a monitor…') },
                        ...availableMonitors,
                    ]"
                    :aria-label="t('Monitor to add')"
                />
                <Button
                    type="button"
                    variant="outline"
                    :disabled="!monitorToAdd"
                    data-test="add-component-button"
                    @click="addComponent"
                >
                    <Plus class="size-4" />
                    {{ t('Add') }}
                </Button>
            </div>
            <InputError :message="errors.components" />
        </fieldset>

        <Button
            type="submit"
            :disabled="processing"
            data-test="save-status-page-button"
        >
            <Spinner v-if="processing" />
            {{ submitLabel }}
        </Button>
    </Form>
</template>
