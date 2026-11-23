import { currentLocale } from '@/lib/i18n';

/**
 * A release date of the changelog (`YYYY-MM-DD`, a calendar day), in the
 * interface language. Read as UTC so that no time zone shifts the day.
 */
export function formatReleaseDate(date: string | null): string | null {
    if (date === null || !/^\d{4}-\d{2}-\d{2}$/.test(date)) {
        return null;
    }

    return new Date(`${date}T00:00:00Z`).toLocaleDateString(currentLocale(), {
        dateStyle: 'long',
        timeZone: 'UTC',
    });
}
