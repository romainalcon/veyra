<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import type { MonitorCheck } from '@/types';

defineProps<{ check: MonitorCheck | null }>();
</script>

<template>
    <Badge v-if="!check" variant="outline">No check yet</Badge>
    <Badge
        v-else-if="check.success"
        class="bg-emerald-600 text-white hover:bg-emerald-600 dark:bg-emerald-500"
    >
        OK{{ check.status_code ? ` · ${check.status_code}` : '' }}
    </Badge>
    <Badge v-else variant="destructive">
        {{
            check.status_code
                ? `Failed · ${check.status_code}`
                : (check.error_label ?? 'Failed')
        }}
    </Badge>
</template>
