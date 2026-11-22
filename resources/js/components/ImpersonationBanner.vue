<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { UserRoundCog } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/lib/i18n';

const page = usePage();
const { t } = useTranslations();
const impersonation = computed(() => page.props.auth?.impersonation ?? null);
const stopping = ref(false);

function stop(): void {
    if (impersonation.value === null) {
        return;
    }

    router.post(
        impersonation.value.stopUrl,
        {},
        {
            onStart: () => (stopping.value = true),
            onFinish: () => (stopping.value = false),
        },
    );
}
</script>

<template>
    <div
        v-if="impersonation"
        role="status"
        class="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-status-down/40 bg-status-down-soft px-6 py-3 text-sm text-status-down-soft-foreground"
        data-test="impersonation-banner"
    >
        <UserRoundCog class="size-4 shrink-0" />
        <p class="min-w-0 flex-1">
            <span class="font-medium">
                {{
                    t('You are acting as :name.', {
                        name: page.props.auth.user?.name ?? '',
                    })
                }}
            </span>
            {{
                t('Started by :operator; every change is recorded.', {
                    operator: impersonation.operator,
                })
            }}
        </p>
        <Button
            size="sm"
            variant="outline"
            :disabled="stopping"
            data-test="impersonation-stop"
            @click="stop"
        >
            {{ t('Stop impersonating') }}
        </Button>
    </div>
</template>
