import { configureEcho } from '@laravel/echo-vue';

/**
 * Real-time delivery is optional: when Reverb is not configured the UI keeps
 * working purely over HTTP. Reverb is never the source of truth.
 */
export function isRealtimeConfigured(): boolean {
    return Boolean(import.meta.env.VITE_REVERB_APP_KEY);
}

export function initializeRealtime(): void {
    if (!isRealtimeConfigured()) {
        return;
    }

    const scheme = import.meta.env.VITE_REVERB_SCHEME ?? 'https';
    const port = Number(import.meta.env.VITE_REVERB_PORT ?? 443);

    configureEcho({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: port,
        wssPort: port,
        forceTLS: scheme === 'https',
        enabledTransports: ['ws', 'wss'],
    });
}

export type ConnectionStatus =
    'connected' | 'disconnected' | 'connecting' | 'reconnecting' | 'failed';

/**
 * After a connection is re-established, events may have been missed: the
 * page must be refreshed from the HTTP source of truth.
 */
export function shouldResync(
    previous: ConnectionStatus | undefined,
    next: ConnectionStatus,
): boolean {
    return (
        next === 'connected' &&
        (previous === 'reconnecting' ||
            previous === 'disconnected' ||
            previous === 'failed')
    );
}
