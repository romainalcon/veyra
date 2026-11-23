<script setup lang="ts">
import EmptyState from '@/components/EmptyState.vue';
import { Head, Link, router, setLayoutProps, usePage } from '@inertiajs/vue3';
import {
    Bell,
    BellRing,
    Hash,
    Mail,
    MessageSquare,
    Send,
    Siren,
    Smartphone,
    Trash2,
    Users,
    Webhook,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import LimitNotice from '@/components/LimitNotice.vue';
import ChannelForm from '@/components/notifications/ChannelForm.vue';
import EscalationPolicyForm from '@/components/notifications/EscalationPolicyForm.vue';
import DeliveryStatus from '@/components/notifications/DeliveryStatus.vue';
import WebhookSecretPanel from '@/components/notifications/WebhookSecretPanel.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useOrganizationLimit } from '@/composables/useOrganizationLimit';
import { useOrganizationRealtime } from '@/composables/useOrganizationRealtime';
import { formatDateTime, notificationEventLabel } from '@/lib/monitoring';
import {
    destroy as destroyPolicy,
    store as storePolicy,
    update as updatePolicy,
} from '@/routes/organizations/escalation-policies';
import { show as showIncident } from '@/routes/organizations/incidents';
import {
    destroy,
    index,
    store,
    test,
    update,
} from '@/routes/organizations/notification-channels';
import type {
    ChannelType,
    CurrentOrganization,
    Delivery,
    EscalationLimits,
    EscalationPolicySummary,
    NotificationChannelSummary,
} from '@/types';
import { useTranslations } from '@/lib/i18n';

const { t, tc } = useTranslations();

defineProps<{
    channels: NotificationChannelSummary[];
    deliveries: Delivery[];
    canManage: boolean;
    types: { value: ChannelType; label: string }[];
    maxRecipients: number;
    escalationPolicies: EscalationPolicySummary[];
    canManageEscalation: boolean;
    escalationLimits: EscalationLimits;
}>();

const page = usePage();
const organization = computed(
    () => page.props.currentOrganization as CurrentOrganization,
);
const editing = ref<string | null>(null);
const editingPolicy = ref<string | null>(null);

const { reached: limitReached } = useOrganizationLimit('notification_channels');

useOrganizationRealtime(organization.value.id, { only: ['deliveries'] });

setLayoutProps({
    breadcrumbs: [
        { title: t('Notifications'), href: index(organization.value.slug) },
    ],
});

const channelIcons = {
    email: Mail,
    discord: MessageSquare,
    webhook: Webhook,
    slack: Hash,
    ntfy: Bell,
    gotify: Smartphone,
    telegram: Send,
    teams: Users,
    pagerduty: Siren,
};

const sendTest = (channel: NotificationChannelSummary) =>
    router.visit(test([organization.value.slug, channel.id]), {
        preserveScroll: true,
    });

const removePolicy = (policy: EscalationPolicySummary) =>
    router.visit(destroyPolicy([organization.value.slug, policy.id]), {
        preserveScroll: true,
    });

const remove = (channel: NotificationChannelSummary) =>
    router.visit(destroy([organization.value.slug, channel.id]), {
        preserveScroll: true,
    });
</script>

<template>
    <Head :title="t('Notifications')" />

    <div class="mx-auto w-full max-w-4xl space-y-10 px-4 py-6">
        <section class="space-y-4">
            <Heading
                :title="t('Notification channels')"
                :description="
                    t(
                        'Alerts for incident opened, resolved, acknowledged, plus one reminder when nobody acknowledges within 30 minutes.',
                    )
                "
            />

            <EmptyState
                v-if="channels.length === 0"
                :icon="BellRing"
                :title="t('No channels yet.')"
                :description="
                    canManage
                        ? t('Add one below.')
                        : t('Ask an admin to configure one.')
                "
                data-test="channels-empty-state"
            />

            <ul
                v-else
                class="divide-y rounded-lg border"
                data-test="channels-list"
            >
                <li
                    v-for="channel in channels"
                    :key="channel.id"
                    class="space-y-4 p-4"
                >
                    <div
                        class="flex flex-col gap-3 sm:flex-row sm:items-center"
                    >
                        <div class="min-w-0 flex-1">
                            <p
                                class="flex flex-wrap items-center gap-2 font-medium"
                            >
                                <component
                                    :is="channelIcons[channel.type]"
                                    class="size-4 text-muted-foreground"
                                />
                                {{ channel.name }}
                                <Badge
                                    v-if="!channel.enabled"
                                    variant="secondary"
                                    >{{ t('Disabled') }}</Badge
                                >
                                <Badge
                                    v-if="channel.is_default"
                                    variant="outline"
                                    >{{ t('Default') }}</Badge
                                >
                            </p>
                            <p class="truncate text-sm text-muted-foreground">
                                {{ channel.summary }} ·
                                {{
                                    tc(
                                        ':count monitor|:count monitors',
                                        channel.monitors_count,
                                    )
                                }}
                            </p>
                        </div>
                        <div v-if="canManage" class="flex flex-wrap gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                data-test="test-channel-button"
                                @click="sendTest(channel)"
                            >
                                <Send class="size-4" />
                                {{ t('Send test') }}
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                @click="
                                    editing =
                                        editing === channel.id
                                            ? null
                                            : channel.id
                                "
                            >
                                {{
                                    editing === channel.id
                                        ? t('Close')
                                        : t('Edit')
                                }}
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                :aria-label="`Delete ${channel.name}`"
                                @click="remove(channel)"
                            >
                                <Trash2 class="size-4" />
                            </Button>
                        </div>
                    </div>
                    <WebhookSecretPanel
                        v-if="canManage && channel.type === 'webhook'"
                        :channel="channel"
                        :organization-slug="organization.slug"
                        :can-manage="canManage"
                    />
                    <ChannelForm
                        v-if="canManage && editing === channel.id"
                        :action="update.form([organization.slug, channel.id])"
                        :types="types"
                        :channel="channel"
                        :max-recipients="maxRecipients"
                        @saved="editing = null"
                    />
                </li>
            </ul>
        </section>

        <section v-if="canManage" class="space-y-4">
            <Heading variant="small" :title="t('Add a channel')" />
            <LimitNotice
                feature="notification_channels"
                :resource="t('notification channels')"
                :hint="t('Delete a channel before adding another.')"
            />
            <ChannelForm
                v-if="!limitReached"
                :action="store.form(organization.slug)"
                :types="types"
                :max-recipients="maxRecipients"
            />
        </section>

        <section class="space-y-4" data-test="escalation-section">
            <Heading
                variant="small"
                :title="t('Escalation policies')"
                :description="
                    t(
                        'When an incident stays unacknowledged, each step notifies more channels. Acknowledging or recovering stops the escalation.',
                    )
                "
            />
            <p
                v-if="escalationPolicies.length === 0"
                class="text-sm text-muted-foreground"
            >
                {{ t('No escalation policy yet.') }}
            </p>
            <ul
                v-else
                class="divide-y rounded-lg border"
                data-test="escalation-policies-list"
            >
                <li
                    v-for="policy in escalationPolicies"
                    :key="policy.id"
                    class="space-y-4 p-4"
                >
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                        <div class="min-w-0 flex-1 space-y-1">
                            <p
                                class="flex flex-wrap items-center gap-2 font-medium"
                            >
                                {{ policy.name }}
                                <Badge
                                    v-if="policy.is_default"
                                    variant="outline"
                                    >{{ t('Default') }}</Badge
                                >
                            </p>
                            <ol
                                class="space-y-0.5 text-sm text-muted-foreground"
                            >
                                <li
                                    v-for="(step, index) in policy.steps"
                                    :key="index"
                                >
                                    {{
                                        t(':minutes min → :channels', {
                                            minutes: step.delay_minutes,
                                            channels:
                                                step.channels
                                                    .map((c) => c.name)
                                                    .join(', ') || '—',
                                        })
                                    }}
                                </li>
                            </ol>
                            <p class="text-xs text-muted-foreground">
                                {{
                                    tc(
                                        ':count monitor|:count monitors',
                                        policy.monitors_count,
                                    )
                                }}
                            </p>
                        </div>
                        <div
                            v-if="canManageEscalation"
                            class="flex flex-wrap gap-2"
                        >
                            <Button
                                variant="outline"
                                size="sm"
                                @click="
                                    editingPolicy =
                                        editingPolicy === policy.id
                                            ? null
                                            : policy.id
                                "
                            >
                                {{
                                    editingPolicy === policy.id
                                        ? t('Close')
                                        : t('Edit')
                                }}
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                :aria-label="`Delete ${policy.name}`"
                                @click="removePolicy(policy)"
                            >
                                <Trash2 class="size-4" />
                            </Button>
                        </div>
                    </div>
                    <EscalationPolicyForm
                        v-if="
                            canManageEscalation && editingPolicy === policy.id
                        "
                        :action="
                            updatePolicy.form([organization.slug, policy.id])
                        "
                        :channels="channels"
                        :limits="escalationLimits"
                        :policy="policy"
                        @saved="editingPolicy = null"
                    />
                </li>
            </ul>

            <template v-if="canManageEscalation && channels.length > 0">
                <Heading
                    variant="small"
                    :title="t('Add an escalation policy')"
                />
                <EscalationPolicyForm
                    :action="storePolicy.form(organization.slug)"
                    :channels="channels"
                    :limits="escalationLimits"
                />
            </template>
        </section>

        <section class="space-y-3">
            <Heading variant="small" :title="t('Recent deliveries')" />
            <p
                v-if="deliveries.length === 0"
                class="text-sm text-muted-foreground"
            >
                {{ t('No notifications sent yet.') }}
            </p>
            <div v-else class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm" data-test="deliveries-table">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2 font-medium">
                                {{ t('When') }}
                            </th>
                            <th class="px-4 py-2 font-medium">
                                {{ t('Channel') }}
                            </th>
                            <th class="px-4 py-2 font-medium">
                                {{ t('Event') }}
                            </th>
                            <th class="px-4 py-2 font-medium">
                                {{ t('Status') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="delivery in deliveries" :key="delivery.id">
                            <td class="px-4 py-2 whitespace-nowrap">
                                {{
                                    formatDateTime(delivery.created_at ?? null)
                                }}
                            </td>
                            <td class="px-4 py-2">{{ delivery.channel }}</td>
                            <td class="px-4 py-2">
                                <Link
                                    v-if="delivery.incident_id"
                                    :href="
                                        showIncident([
                                            organization.slug,
                                            delivery.incident_id,
                                        ])
                                    "
                                    class="hover:underline"
                                    >{{
                                        notificationEventLabel(delivery.event)
                                    }}
                                    · {{ delivery.monitor }}</Link
                                >
                                <span v-else
                                    >{{
                                        notificationEventLabel(delivery.event)
                                    }}
                                    · {{ delivery.monitor }}</span
                                >
                            </td>
                            <td class="px-4 py-2">
                                <DeliveryStatus :delivery="delivery" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
