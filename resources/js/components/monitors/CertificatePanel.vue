<script setup lang="ts">
import { ShieldAlert, ShieldCheck, ShieldX } from '@lucide/vue';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { formatDateTime } from '@/lib/monitoring';
import type { MonitorCertificate } from '@/types';
import { useTranslations } from '@/lib/i18n';

const { t, tc } = useTranslations();

const props = defineProps<{
    certificate: MonitorCertificate | null;
}>();

const label = computed(() => {
    const certificate = props.certificate;

    if (!certificate) {
        return '';
    }

    if (certificate.status === 'expired') {
        return t('Expired');
    }

    if (certificate.days_left < 1) {
        return t('Expires today');
    }

    return tc(
        'Expires in :count day|Expires in :count days',
        certificate.days_left,
    );
});
</script>

<template>
    <section
        class="space-y-4 rounded-lg border p-4"
        data-test="certificate-panel"
    >
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="flex items-center gap-2 text-sm font-medium">
                <ShieldX
                    v-if="certificate?.status === 'expired'"
                    class="size-4 text-destructive"
                />
                <ShieldAlert
                    v-else-if="certificate?.status === 'expiring'"
                    class="size-4 text-warning"
                />
                <ShieldCheck v-else class="size-4 text-muted-foreground" />
                {{ t('TLS certificate') }}
            </h2>
            <template v-if="certificate">
                <Badge
                    v-if="certificate.status === 'expired'"
                    variant="destructive"
                    data-test="certificate-status"
                    >{{ label }}</Badge
                >
                <Badge
                    v-else-if="certificate.status === 'expiring'"
                    class="bg-status-degraded text-status-degraded-foreground"
                    data-test="certificate-status"
                    >{{ label }}</Badge
                >
                <Badge
                    v-else
                    variant="secondary"
                    data-test="certificate-status"
                    >{{ label }}</Badge
                >
            </template>
        </div>

        <dl
            v-if="certificate"
            class="grid gap-4 text-sm sm:grid-cols-4"
            data-test="certificate-details"
        >
            <div class="min-w-0">
                <dt class="text-muted-foreground">{{ t('Issued to') }}</dt>
                <dd class="mt-1 truncate">{{ certificate.subject ?? '—' }}</dd>
            </div>
            <div class="min-w-0">
                <dt class="text-muted-foreground">{{ t('Issuer') }}</dt>
                <dd class="mt-1 truncate">{{ certificate.issuer ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">{{ t('Valid') }}</dt>
                <dd class="mt-1">
                    {{ formatDateTime(certificate.not_before) }} →
                    {{ formatDateTime(certificate.not_after) }}
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground">{{ t('Last seen') }}</dt>
                <dd class="mt-1">
                    {{ formatDateTime(certificate.checked_at) }}
                </dd>
            </div>
        </dl>
        <p
            v-else
            class="text-sm text-muted-foreground"
            data-test="certificate-empty"
        >
            {{
                t(
                    'No certificate seen yet. It is read on the next successful HTTPS connection to this host.',
                )
            }}
        </p>
        <p class="text-xs text-muted-foreground">
            {{
                t(
                    "The monitor's notification channels are warned before expiry and when the certificate has expired, once per certificate.",
                )
            }}
        </p>
    </section>
</template>
