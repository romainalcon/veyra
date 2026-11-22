import { currentLocale, t } from '@/lib/i18n';
import type { MaintenanceSeries } from '@/types';

/** ISO-8601 days in display order (Monday first). */
export const ISO_WEEKDAYS = [1, 2, 3, 4, 5, 6, 7] as const;

/** Localized weekday name of an ISO-8601 day (1 = Monday). */
export function weekdayName(
    isoDay: number,
    style: 'long' | 'short' = 'long',
): string {
    // 5 January 2026 is a Monday.
    const date = new Date(Date.UTC(2026, 0, 4 + isoDay, 12));

    return new Intl.DateTimeFormat(currentLocale(), {
        weekday: style,
        timeZone: 'UTC',
    }).format(date);
}

export function formatDuration(minutes: number): string {
    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    if (hours === 0) {
        return `${rest} min`;
    }

    return rest === 0 ? `${hours} h` : `${hours} h ${rest} min`;
}

/** "Every Monday, Wednesday at 03:00 for 1 h 30 min" and its variants. */
export function describeSeries(
    series: Pick<
        MaintenanceSeries,
        | 'frequency'
        | 'weekdays'
        | 'month_day'
        | 'start_time'
        | 'duration_minutes'
    >,
): string {
    const replacements = {
        time: series.start_time,
        duration: formatDuration(series.duration_minutes),
    };

    switch (series.frequency) {
        case 'daily':
            return t('Every day at :time for :duration', replacements);
        case 'weekly':
            return t('Every :days at :time for :duration', {
                ...replacements,
                days: [...series.weekdays]
                    .sort((a, b) => a - b)
                    .map((day) => weekdayName(day))
                    .join(', '),
            });
        case 'monthly':
            return t(
                (series.month_day ?? 1) >= 29
                    ? 'Monthly on day :day (or the last day) at :time for :duration'
                    : 'Monthly on day :day at :time for :duration',
                { ...replacements, day: series.month_day },
            );
    }
}

/** Today's date (Y-m-d) in the given IANA time zone. */
export function todayIn(timeZone: string, now: Date = new Date()): string {
    return new Intl.DateTimeFormat('en-CA', {
        timeZone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(now);
}
