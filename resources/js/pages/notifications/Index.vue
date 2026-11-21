<script setup lang="ts">
import { Head, Link, router, setLayoutProps, usePage } from '@inertiajs/vue3';
import { Mail, MessageSquare, Send, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import ChannelForm from '@/components/notifications/ChannelForm.vue';
import DeliveryStatus from '@/components/notifications/DeliveryStatus.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useOrganizationRealtime } from '@/composables/useOrganizationRealtime';
import { formatDateTime } from '@/lib/monitoring';
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
    NotificationChannelSummary,
} from '@/types';

defineProps<{
    channels: NotificationChannelSummary[];
    deliveries: Delivery[];
    canManage: boolean;
    types: { value: ChannelType; label: string }[];
    maxRecipients: number;
}>();

const page = usePage();
const organization = computed(
    () => page.props.currentOrganization as CurrentOrganization,
);
const editing = ref<string | null>(null);

useOrganizationRealtime(organization.value.id, { only: ['deliveries'] });

setLayoutProps({
    breadcrumbs: [
        { title: 'Notifications', href: index(organization.value.slug) },
    ],
});

const sendTest = (channel: NotificationChannelSummary) =>
    router.visit(test([organization.value.slug, channel.id]), {
        preserveScroll: true,
    });

const remove = (channel: NotificationChannelSummary) =>
    router.visit(destroy([organization.value.slug, channel.id]), {
        preserveScroll: true,
    });
</script>

<template>
    <Head title="Notifications" />

    <div class="mx-auto w-full max-w-4xl space-y-10 px-4 py-6">
        <section class="space-y-4">
            <Heading
                title="Notification channels"
                description="Alerts for incident opened, resolved, acknowledged, plus one reminder when nobody acknowledges within 30 minutes."
            />

            <p
                v-if="channels.length === 0"
                class="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground"
                data-test="channels-empty-state"
            >
                No channels yet.
                <template v-if="canManage">Add one below.</template>
                <template v-else>Ask an admin to configure one.</template>
            </p>

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
                                    :is="
                                        channel.type === 'email'
                                            ? Mail
                                            : MessageSquare
                                    "
                                    class="size-4 text-muted-foreground"
                                />
                                {{ channel.name }}
                                <Badge
                                    v-if="!channel.enabled"
                                    variant="secondary"
                                    >Disabled</Badge
                                >
                                <Badge
                                    v-if="channel.is_default"
                                    variant="outline"
                                    >Default</Badge
                                >
                            </p>
                            <p class="truncate text-sm text-muted-foreground">
                                {{ channel.summary }} ·
                                {{ channel.monitors_count }} monitor(s)
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
                                Send test
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
                                {{ editing === channel.id ? 'Close' : 'Edit' }}
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
            <Heading variant="small" title="Add a channel" />
            <ChannelForm
                :action="store.form(organization.slug)"
                :types="types"
                :max-recipients="maxRecipients"
            />
        </section>

        <section class="space-y-3">
            <Heading variant="small" title="Recent deliveries" />
            <p
                v-if="deliveries.length === 0"
                class="text-sm text-muted-foreground"
            >
                No notifications sent yet.
            </p>
            <div v-else class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm" data-test="deliveries-table">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2 font-medium">When</th>
                            <th class="px-4 py-2 font-medium">Channel</th>
                            <th class="px-4 py-2 font-medium">Event</th>
                            <th class="px-4 py-2 font-medium">Status</th>
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
                                    >{{ delivery.event }} ·
                                    {{ delivery.monitor }}</Link
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
