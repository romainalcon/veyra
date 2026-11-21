import { currentLocale, t } from '@/lib/i18n';

/**
 * Client-side mirror of App\Domain\Monitoring\DTOs\ExpectedStatuses::parse.
 * Used for instant feedback only; the server remains authoritative.
 */
export function parseExpectedStatuses(
    expression: string,
): { from: number; to: number }[] | null {
    const parts = expression
        .split(',')
        .map((part) => part.trim())
        .filter((part) => part !== '');

    if (parts.length === 0 || parts.length > 20) {
        return null;
    }

    const ranges: { from: number; to: number }[] = [];

    for (const part of parts) {
        const match = /^(\d{3})(?:\s*-\s*(\d{3}))?$/.exec(part);

        if (!match) {
            return null;
        }

        const from = Number(match[1]);
        const to = match[2] ? Number(match[2]) : from;

        if (from < 100 || to > 599 || from > to) {
            return null;
        }

        ranges.push({ from, to });
    }

    return ranges;
}

export function formatDuration(ms: number | null): string {
    if (ms === null) {
        return '—';
    }

    return ms >= 1000 ? `${(ms / 1000).toFixed(2)} s` : `${ms} ms`;
}

export function formatInterval(seconds: number): string {
    return seconds >= 3600 ? `${seconds / 3600} h` : `${seconds / 60} min`;
}

export function formatDateTime(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString(currentLocale()) : '—';
}

export function formatElapsed(seconds: number): string {
    if (seconds < 60) {
        return `${seconds}s`;
    }

    const minutes = Math.floor(seconds / 60);

    if (minutes < 60) {
        return `${minutes}m`;
    }

    const hours = Math.floor(minutes / 60);

    return hours < 24
        ? `${hours}h ${minutes % 60}m`
        : t(':daysd :hoursh', {
              days: Math.floor(hours / 24),
              hours: hours % 24,
          });
}

const notificationEventLabels: Record<string, string> = {
    opened: 'Down',
    resolved: 'Resolved',
    acknowledged: 'Acknowledged',
    reminder: 'Still down',
    test: 'Test',
    certificate_expiring: 'Certificate expires soon',
    certificate_expired: 'Certificate expired',
};

/**
 * Label of a notification delivery event (`opened`, `certificate_expiring`…).
 */
export function notificationEventLabel(event: string): string {
    const label = notificationEventLabels[event];

    return label ? t(label) : event;
}
