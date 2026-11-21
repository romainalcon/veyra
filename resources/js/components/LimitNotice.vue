<script setup lang="ts">
import { CircleAlert } from '@lucide/vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { useOrganizationLimit } from '@/composables/useOrganizationLimit';
import { useTranslations } from '@/lib/i18n';
import type { LimitFeature } from '@/types';

const props = defineProps<{
    feature: LimitFeature;
    /** Translated plural noun, e.g. "active monitors". */
    resource: string;
    /** What to do when the limit is reached. */
    hint: string;
}>();

const { t } = useTranslations();
const { usage, reached } = useOrganizationLimit(props.feature);
</script>

<template>
    <template v-if="usage">
        <Alert v-if="reached" data-test="limit-reached">
            <CircleAlert />
            <AlertTitle>{{ t('Limit reached') }}</AlertTitle>
            <AlertDescription>
                <p>
                    {{
                        t(':used of :limit :resource', {
                            used: usage.used,
                            limit: usage.limit,
                            resource,
                        })
                    }}
                </p>
                <p>{{ hint }}</p>
            </AlertDescription>
        </Alert>
        <p v-else class="text-sm text-muted-foreground" data-test="limit-usage">
            {{
                t(':used of :limit :resource', {
                    used: usage.used,
                    limit: usage.limit,
                    resource,
                })
            }}
        </p>
    </template>
</template>
