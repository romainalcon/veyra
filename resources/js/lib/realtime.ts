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
 * Events broadcast on `private-organizations.{id}` (identifiers only).
 */
export const REALTIME_EVENTS = [
    '.monitor.checked',
    '.monitor.state-changed',
    '.incident.updated',
] as const;

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

/**
 * Polling is the fallback whenever real-time delivery is unavailable.
 */
export function shouldPoll(
    configured: boolean,
    status: ConnectionStatus | undefined,
): boolean {
    return !configured || status !== 'connected';
}

/**
 * Coalesces bursts of events into a single reload.
 */
export function debounce(callback: () => void, waitMs: number) {
    let timer: ReturnType<typeof setTimeout> | undefined;

    const debounced = () => {
        if (timer !== undefined) {
            clearTimeout(timer);
        }

        timer = setTimeout(() => {
            timer = undefined;
            callback();
        }, waitMs);
    };

    debounced.cancel = () => {
        if (timer !== undefined) {
            clearTimeout(timer);
            timer = undefined;
        }
    };

    return debounced;
}
