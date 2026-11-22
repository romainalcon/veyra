<script setup lang="ts">
import { Form, usePage } from '@inertiajs/vue3';
import { Check, Copy, KeyRound } from '@lucide/vue';
import { computed, ref } from 'vue';
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
import { store as regenerate } from '@/routes/organizations/notification-channels/signing-secret';
import type { NotificationChannelSummary } from '@/types';
import { useTranslations } from '@/lib/i18n';

const { t } = useTranslations();

const props = defineProps<{
    channel: NotificationChannelSummary;
    organizationSlug: string;
    canManage: boolean;
}>();

const page = usePage();

/**
 * Flashed once after creation or regeneration (ADR-033); the server never
 * sends it again.
 */
const secret = computed(() => {
    const flashed = (
        page.flash as
            | { webhookSecret?: { channel_id: string; secret: string } }
            | undefined
    )?.webhookSecret;

    return flashed?.channel_id === props.channel.id ? flashed.secret : null;
});
const copied = ref(false);

const verification = [
    'signed  = `${webhook-id}.${webhook-timestamp}.${raw body}`',
    'key     = base64_decode(secret without "whsec_")',
    'v1,     + base64(HMAC-SHA256(key, signed)) must equal webhook-signature',
].join('\n');

const copy = async () => {
    if (!secret.value) {
        return;
    }

    try {
        await navigator.clipboard.writeText(secret.value);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    } catch {
        copied.value = false;
    }
};
</script>

<template>
    <div class="space-y-3 text-sm" data-test="webhook-secret-panel">
        <div v-if="secret" class="space-y-2" data-test="webhook-secret">
            <p class="font-medium">{{ t('Signing secret') }}</p>
            <div class="flex flex-col gap-2 sm:flex-row">
                <code
                    class="min-w-0 flex-1 rounded-md border bg-muted/40 px-3 py-2 break-all"
                    >{{ secret }}</code
                >
                <Button variant="outline" size="sm" @click="copy">
                    <Check v-if="copied" />
                    <Copy v-else />
                    {{ copied ? t('Copied') : t('Copy') }}
                </Button>
            </div>
            <p class="text-xs text-muted-foreground">
                {{
                    t(
                        'Copy it now: it will not be shown again. Your endpoint uses it to verify that deliveries come from Veyra.',
                    )
                }}
            </p>
        </div>

        <details data-test="webhook-verification">
            <summary class="cursor-pointer font-medium">
                {{ t('Verifying deliveries') }}
            </summary>
            <div class="mt-2 space-y-2 text-muted-foreground">
                <p>
                    {{
                        t(
                            'Each delivery is a JSON POST signed with the Standard Webhooks scheme: use any Standard Webhooks library, or compute the signature yourself. Reject deliveries whose timestamp is more than 5 minutes old, and use webhook-id to ignore duplicates.',
                        )
                    }}
                </p>
                <pre
                    class="overflow-x-auto rounded-md border bg-muted/40 px-3 py-2 text-xs text-foreground"
                ><code>{{ verification }}</code></pre>
            </div>
        </details>

        <Dialog v-if="canManage">
            <DialogTrigger as-child>
                <Button
                    variant="outline"
                    size="sm"
                    data-test="regenerate-webhook-secret"
                >
                    <KeyRound />
                    {{ t('New signing secret') }}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <Form
                    v-bind="regenerate.form([organizationSlug, channel.id])"
                    :options="{ preserveScroll: true }"
                    class="space-y-6"
                    v-slot="{ processing }"
                >
                    <DialogHeader class="space-y-3">
                        <DialogTitle>{{
                            t('Issue a new signing secret?')
                        }}</DialogTitle>
                        <DialogDescription>
                            {{
                                t(
                                    'Deliveries are signed with the new secret immediately. Update your endpoint, or it will reject them.',
                                )
                            }}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter class="gap-2">
                        <DialogClose as-child>
                            <Button variant="secondary">{{
                                t('Cancel')
                            }}</Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            :disabled="processing"
                            data-test="confirm-regenerate-webhook-secret"
                        >
                            {{ t('Issue new secret') }}
                        </Button>
                    </DialogFooter>
                </Form>
            </DialogContent>
        </Dialog>
    </div>
</template>
