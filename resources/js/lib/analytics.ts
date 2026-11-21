import type { UptimeFigure } from '@/types';

/**
 * Uptime is computed over observed checks; `null` means "no data", which
 * must never be displayed as 100 % (nor 0 %).
 */
export function formatUptime(uptime: number | null): string {
    if (uptime === null) {
        return 'No data';
    }

    if (uptime === 100) {
        return '100%';
    }

    return `${uptime
        .toFixed(uptime >= 99 ? 3 : 2)
        .replace(/0+$/, '')
        .replace(/\.$/, '')}%`;
}

export function uptimeTone(
    uptime: number | null,
): 'none' | 'good' | 'warning' | 'bad' {
    if (uptime === null) {
        return 'none';
    }

    if (uptime >= 99.9) {
        return 'good';
    }

    return uptime >= 99 ? 'warning' : 'bad';
}

export function coverageNote(figure: UptimeFigure): string | null {
    if (figure.uptime === null) {
        return 'No checks recorded in this period';
    }

    if (figure.partial && figure.coverage !== null) {
        return `Partial data (${Math.round(figure.coverage * 100)}% of expected checks)`;
    }

    return null;
}

export function formatBucketLabel(iso: string, range: string): string {
    const date = new Date(iso);

    return range === '24h' || range === '7d'
        ? date.toLocaleString(undefined, {
              month: range === '7d' ? 'short' : undefined,
              day: range === '7d' ? 'numeric' : undefined,
              hour: '2-digit',
              minute: '2-digit',
          })
        : date.toLocaleDateString(undefined, {
              month: 'short',
              day: 'numeric',
          });
}
