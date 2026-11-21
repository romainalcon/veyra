import { currentLocale, t } from '@/lib/i18n';
import type { UptimeFigure } from '@/types';

/**
 * Uptime is computed over observed checks; `null` means "no data", which
 * must never be displayed as 100 % (nor 0 %).
 */
export function formatUptime(uptime: number | null): string {
    if (uptime === null) {
        return t('No data');
    }

    return new Intl.NumberFormat(currentLocale(), {
        style: 'percent',
        maximumFractionDigits: uptime === 100 ? 0 : uptime >= 99 ? 3 : 2,
    }).format(uptime / 100);
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
        return t('No checks recorded in this period');
    }

    if (figure.partial && figure.coverage !== null) {
        return t('Partial data (:percent% of expected checks)', {
            percent: Math.round(figure.coverage * 100),
        });
    }

    return null;
}

export function formatBucketLabel(iso: string, range: string): string {
    const date = new Date(iso);

    return range === '24h' || range === '7d'
        ? date.toLocaleString(currentLocale(), {
              month: range === '7d' ? 'short' : undefined,
              day: range === '7d' ? 'numeric' : undefined,
              hour: '2-digit',
              minute: '2-digit',
          })
        : date.toLocaleDateString(currentLocale(), {
              month: 'short',
              day: 'numeric',
          });
}
