<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import type { Delivery } from '@/types';
import { useTranslations } from '@/lib/i18n';

const { t } = useTranslations();

defineProps<{
    delivery: Pick<Delivery, 'status' | 'attempts' | 'last_error'>;
}>();
</script>

<template>
    <span class="inline-flex flex-wrap items-center gap-2">
        <Badge
            v-if="delivery.status === 'sent'"
            class="bg-emerald-600 text-white hover:bg-emerald-600"
            >{{ t('Sent') }}</Badge
        >
        <Badge v-else-if="delivery.status === 'failed'" variant="destructive">{{
            t('Failed')
        }}</Badge>
        <Badge v-else variant="secondary">
            {{
                delivery.attempts
                    ? t('Pending (retry :attempt)', {
                          attempt: delivery.attempts,
                      })
                    : t('Pending')
            }}
        </Badge>
        <span
            v-if="delivery.last_error"
            class="text-xs text-muted-foreground"
            >{{ delivery.last_error }}</span
        >
    </span>
</template>
