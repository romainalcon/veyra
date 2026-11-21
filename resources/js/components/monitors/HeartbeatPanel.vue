<script setup lang="ts">
import { Form, usePage } from '@inertiajs/vue3';
import { Check, Copy, KeyRound } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { formatDateTime } from '@/lib/monitoring';
import { store as regenerate } from '@/routes/organizations/monitors/ping-token';
import type { MonitorDetail } from '@/types';

const props = defineProps<{
    monitor: MonitorDetail;
    organizationSlug: string;
    canUpdate: boolean;
}>();

const page = usePage();

/** Flashed once after creation or regeneration; never stored by the server. */
const pingUrl = computed(
    () => (page.flash as { pingUrl?: string } | undefined)?.pingUrl ?? null,
);
const heartbeat = computed(() => props.monitor.heartbeat);
const copied = ref(false);

const copy = async () => {
    if (!pingUrl.value) {
        return;
    }

    try {
        await navigator.clipboard.writeText(pingUrl.value);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    } catch {
        copied.value = false;
    }
};
</script>

<template>
    <section
        v-if="heartbeat"
        class="space-y-4 rounded-lg border p-4"
        data-test="heartbeat-panel"
    >
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-sm font-medium">Ping URL</h2>
            <p class="text-sm text-muted-foreground">
                Last ping:
                <span class="text-foreground">{{
                    heartbeat.last_ping_at
                        ? formatDateTime(heartbeat.last_ping_at)
                        : 'never'
                }}</span>
                <Badge
                    v-if="heartbeat.last_ping_at && heartbeat.last_ping_failed"
                    variant="destructive"
                    class="ml-2"
                    >Failure reported</Badge
                >
            </p>
        </div>

        <div v-if="pingUrl" class="space-y-2" data-test="ping-url">
            <div class="flex flex-col gap-2 sm:flex-row">
                <code
                    class="min-w-0 flex-1 rounded-md border bg-muted/40 px-3 py-2 text-sm break-all"
                    >{{ pingUrl }}</code
                >
                <Button variant="outline" size="sm" @click="copy">
                    <Check v-if="copied" />
                    <Copy v-else />
                    {{ copied ? 'Copied' : 'Copy' }}
                </Button>
            </div>
            <p class="text-xs text-muted-foreground">
                Copy it now: it is not stored and will not be shown again. Call
                it with GET, POST or HEAD when the job succeeds, or append
                <code>/fail</code> to report a failure.
            </p>
        </div>
        <p v-else class="text-sm text-muted-foreground">
            <code class="break-all"
                >{{ heartbeat.ping_url_prefix }}••••••••</code
            >
            — the secret part is only shown when the URL is issued.
        </p>

        <Dialog v-if="canUpdate">
            <DialogTrigger as-child>
                <Button
                    variant="outline"
                    size="sm"
                    data-test="regenerate-ping-url"
                >
                    <KeyRound />
                    New ping URL
                </Button>
            </DialogTrigger>
            <DialogContent>
                <Form
                    v-bind="regenerate.form([organizationSlug, monitor.id])"
                    :options="{ preserveScroll: true }"
                    class="space-y-6"
                    v-slot="{ processing }"
                >
                    <DialogHeader class="space-y-3">
                        <DialogTitle>Issue a new ping URL?</DialogTitle>
                        <DialogDescription>
                            The current URL stops working immediately. Update
                            every job that pings it.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter class="gap-2">
                        <DialogClose as-child>
                            <Button variant="secondary">Cancel</Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            :disabled="processing"
                            data-test="confirm-regenerate-ping-url"
                        >
                            Issue new URL
                        </Button>
                    </DialogFooter>
                </Form>
            </DialogContent>
        </Dialog>
    </section>
</template>
