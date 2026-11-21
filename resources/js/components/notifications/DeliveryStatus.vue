<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import type { Delivery } from '@/types';

defineProps<{
    delivery: Pick<Delivery, 'status' | 'attempts' | 'last_error'>;
}>();
</script>

<template>
    <span class="inline-flex flex-wrap items-center gap-2">
        <Badge
            v-if="delivery.status === 'sent'"
            class="bg-emerald-600 text-white hover:bg-emerald-600"
            >Sent</Badge
        >
        <Badge v-else-if="delivery.status === 'failed'" variant="destructive"
            >Failed</Badge
        >
        <Badge v-else variant="secondary">
            Pending{{
                delivery.attempts ? ` (retry ${delivery.attempts})` : ''
            }}
        </Badge>
        <span
            v-if="delivery.last_error"
            class="text-xs text-muted-foreground"
            >{{ delivery.last_error }}</span
        >
    </span>
</template>
