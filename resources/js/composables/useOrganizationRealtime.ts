import { useChannel, useConnectionStatus } from '@laravel/echo-vue';
import { router } from '@inertiajs/vue3';
import { watch } from 'vue';
import { isRealtimeConfigured, shouldResync } from '@/lib/realtime';
import type { ConnectionStatus } from '@/lib/realtime';

/**
 * Joins the organization's private channel (membership is verified server
 * side in routes/channels.php) and reloads page props over HTTP whenever the
 * socket recovers from a disconnection.
 */
export function useOrganizationRealtime(organizationId: string): void {
    if (!isRealtimeConfigured()) {
        return;
    }

    useChannel(`organizations.${organizationId}`, 'private');

    const status = useConnectionStatus();

    watch(status, (next, previous) => {
        if (
            shouldResync(
                previous as ConnectionStatus | undefined,
                next as ConnectionStatus,
            )
        ) {
            router.reload();
        }
    });
}
