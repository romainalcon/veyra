<script setup lang="ts">
import {
    Form,
    Head,
    Link,
    router,
    setLayoutProps,
    usePage,
} from '@inertiajs/vue3';
import { CheckCheck } from '@lucide/vue';
import { computed } from 'vue';
import IncidentController from '@/actions/App/Http/Controllers/Incidents/IncidentController';
import InputError from '@/components/InputError.vue';
import DeliveryStatus from '@/components/notifications/DeliveryStatus.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useOrganizationRealtime } from '@/composables/useOrganizationRealtime';
import {
    formatDateTime,
    formatElapsed,
    notificationEventLabel,
} from '@/lib/monitoring';
import { acknowledge, index, show } from '@/routes/organizations/incidents';
import { show as showMonitor } from '@/routes/organizations/monitors';
import type { CurrentOrganization, Delivery, IncidentDetail } from '@/types';
import { useTranslations } from '@/lib/i18n';

const { t } = useTranslations();

const props = defineProps<{
    incident: IncidentDetail;
    deliveries: Delivery[];
    can: { acknowledge: boolean; addNote: boolean; postPublicUpdate: boolean };
    noteMaxLength: number;
    publicUpdateMaxLength: number;
}>();

const page = usePage();
const organization = computed(
    () => page.props.currentOrganization as CurrentOrganization,
);
const params = computed(
    () => [organization.value.slug, props.incident.id] as [string, string],
);

useOrganizationRealtime(organization.value.id, {
    only: ['incident', 'deliveries'],
    filter: (payload) =>
        payload.incident_id === props.incident.id ||
        payload.monitor_id === props.incident.monitor?.id,
});

setLayoutProps({
    breadcrumbs: [
        { title: t('Incidents'), href: index(organization.value.slug) },
        { title: t('Incident'), href: show(params.value) },
    ],
});

const acknowledgeIncident = () =>
    router.visit(acknowledge(params.value), { preserveScroll: true });
</script>

<template>
    <Head :title="t('Incident')" />

    <div class="mx-auto w-full max-w-4xl space-y-6 px-4 py-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start">
            <div class="min-w-0 flex-1 space-y-1">
                <h1
                    class="flex flex-wrap items-center gap-2 text-xl font-semibold tracking-tight"
                >
                    <Badge
                        :variant="
                            incident.status === 'open'
                                ? 'destructive'
                                : 'secondary'
                        "
                    >
                        {{
                            incident.status === 'open'
                                ? t('Open')
                                : t('Resolved')
                        }}
                    </Badge>
                    <Link
                        v-if="incident.monitor && !incident.monitor.deleted"
                        :href="
                            showMonitor([
                                organization.slug,
                                incident.monitor.id,
                            ])
                        "
                        class="hover:underline"
                        >{{ incident.monitor.name }}</Link
                    >
                    <span v-else>{{
                        incident.monitor?.name ?? t('Deleted monitor')
                    }}</span>
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{ incident.cause }}
                </p>
            </div>
            <Button
                v-if="can.acknowledge && !incident.acknowledged_at"
                variant="outline"
                size="sm"
                data-test="acknowledge-button"
                @click="acknowledgeIncident"
            >
                <CheckCheck class="size-4" />
                {{ t('Acknowledge') }}
            </Button>
        </div>

        <dl class="grid gap-4 rounded-lg border p-4 text-sm sm:grid-cols-4">
            <div>
                <dt class="text-muted-foreground">{{ t('Started') }}</dt>
                <dd class="mt-1">{{ formatDateTime(incident.started_at) }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">{{ t('Resolved') }}</dt>
                <dd class="mt-1">
                    {{
                        incident.resolved_at
                            ? formatDateTime(incident.resolved_at)
                            : t('Ongoing')
                    }}
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground">{{ t('Duration') }}</dt>
                <dd class="mt-1">
                    {{ formatElapsed(incident.duration_seconds) }}
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground">
                    {{ t('Acknowledged') }}
                </dt>
                <dd class="mt-1">
                    <template v-if="incident.acknowledged_at">
                        {{ incident.acknowledged_by ?? t('Someone') }},
                        {{ formatDateTime(incident.acknowledged_at) }}
                    </template>
                    <template v-else>{{ t('Not yet') }}</template>
                </dd>
            </div>
        </dl>

        <section class="space-y-3">
            <h2 class="text-base font-medium">{{ t('Timeline') }}</h2>
            <ol class="space-y-3 border-l pl-4" data-test="incident-timeline">
                <li
                    v-for="event in incident.events"
                    :key="event.id"
                    class="relative"
                >
                    <span
                        class="absolute top-1.5 -left-[21px] size-2.5 rounded-full border-2 border-background bg-muted-foreground"
                        aria-hidden="true"
                    />
                    <p class="text-sm">
                        <span class="font-medium">{{ event.label }}</span>
                        <span v-if="event.user" class="text-muted-foreground">
                            · {{ event.user }}</span
                        >
                        <span class="text-muted-foreground">
                            · {{ formatDateTime(event.created_at) }}</span
                        >
                    </p>
                    <p
                        v-if="event.message"
                        class="mt-1 text-sm whitespace-pre-line text-muted-foreground"
                    >
                        {{ event.message }}
                    </p>
                </li>
            </ol>
        </section>

        <section v-if="deliveries.length" class="space-y-3">
            <h2 class="text-base font-medium">{{ t('Notifications') }}</h2>
            <ul
                class="divide-y rounded-lg border text-sm"
                data-test="incident-deliveries"
            >
                <li
                    v-for="delivery in deliveries"
                    :key="delivery.id"
                    class="flex flex-wrap items-center gap-3 px-4 py-2"
                >
                    <span class="font-medium">{{ delivery.channel }}</span>
                    <span class="text-muted-foreground">{{
                        notificationEventLabel(delivery.event)
                    }}</span>
                    <DeliveryStatus :delivery="delivery" />
                </li>
            </ul>
        </section>

        <section v-if="can.addNote" class="space-y-3">
            <Form
                v-bind="IncidentController.addNote.form(params)"
                :options="{ preserveScroll: true }"
                reset-on-success
                class="space-y-3"
                v-slot="{ errors, processing }"
            >
                <Label for="message">{{ t('Add a note') }}</Label>
                <textarea
                    id="message"
                    name="message"
                    rows="3"
                    required
                    :maxlength="noteMaxLength"
                    class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                    :placeholder="t('What did you find? What is being done?')"
                />
                <InputError :message="errors.message" />
                <Button :disabled="processing" data-test="add-note-button">
                    {{ t('Add note') }}
                </Button>
            </Form>
        </section>

        <section
            v-if="can.postPublicUpdate"
            class="space-y-3 rounded-lg border border-status-maintenance/40 p-4"
            data-test="public-update-section"
        >
            <Form
                v-bind="IncidentController.postPublicUpdate.form(params)"
                :options="{ preserveScroll: true }"
                reset-on-success
                class="space-y-3"
                v-slot="{ errors, processing }"
            >
                <div>
                    <Label for="public_message">{{
                        t('Post a public update')
                    }}</Label>
                    <p class="text-xs text-muted-foreground">
                        {{
                            t(
                                'Shown on every published status page that includes this monitor. Internal notes above are never public.',
                            )
                        }}
                    </p>
                </div>
                <textarea
                    id="public_message"
                    name="public_message"
                    rows="3"
                    required
                    :maxlength="publicUpdateMaxLength"
                    class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                    :placeholder="
                        t('We are investigating elevated error rates.')
                    "
                />
                <InputError :message="errors.public_message" />
                <Button
                    variant="outline"
                    :disabled="processing"
                    data-test="post-public-update-button"
                >
                    {{ t('Publish update') }}
                </Button>
            </Form>
        </section>
    </div>
</template>
