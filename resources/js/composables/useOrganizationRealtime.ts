import { useConnectionStatus, useEcho } from '@laravel/echo-vue';
import { router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import {
    debounce,
    isRealtimeConfigured,
    REALTIME_EVENTS,
    shouldPoll,
    shouldResync,
} from '@/lib/realtime';
import type { ConnectionStatus } from '@/lib/realtime';

export type RealtimeOptions = {
    /** Page props to reload when an update arrives. */
    only: string[];
    /** Optional filter, e.g. only react to events for one monitor. */
    filter?: (payload: Record<string, string>) => boolean;
    pollIntervalMs?: number;
};

/**
 * Joins the organization's private channel (membership is verified server
 * side) and refreshes the given props over HTTP on every update, after a
 * reconnection, and by polling whenever the socket is unavailable.
 */
export function useOrganizationRealtime(
    organizationId: string,
    options: RealtimeOptions,
): void {
    const configured = isRealtimeConfigured();
    const reload = debounce(() => router.reload({ only: options.only }), 500);
    const status = ref<ConnectionStatus | undefined>(undefined);

    if (configured) {
        useEcho<Record<string, string>>(
            `organizations.${organizationId}`,
            [...REALTIME_EVENTS],
            (payload) => {
                if (!options.filter || options.filter(payload)) {
                    reload();
                }
            },
        );

        const connection = useConnectionStatus();

        watch(
            connection,
            (next, previous) => {
                status.value = next as ConnectionStatus;

                if (
                    shouldResync(
                        previous as ConnectionStatus | undefined,
                        next as ConnectionStatus,
                    )
                ) {
                    reload();
                }
            },
            { immediate: true },
        );
    }

    const polling = computed(() => shouldPoll(configured, status.value));
    let timer: ReturnType<typeof setInterval> | undefined;

    const syncPolling = () => {
        if (polling.value && timer === undefined) {
            timer = setInterval(
                () => router.reload({ only: options.only }),
                options.pollIntervalMs ?? 30000,
            );
        } else if (!polling.value && timer !== undefined) {
            clearInterval(timer);
            timer = undefined;
        }
    };

    onMounted(syncPolling);
    watch(polling, syncPolling);
    onBeforeUnmount(() => {
        reload.cancel();

        if (timer !== undefined) {
            clearInterval(timer);
        }
    });
}
