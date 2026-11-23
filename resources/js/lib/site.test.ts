import { afterEach, describe, expect, it } from 'vitest';
import { setLocale } from '@/lib/i18n';
import { formatReleaseDate } from '@/lib/site';

describe('formatReleaseDate', () => {
    afterEach(() => setLocale('en'));

    it('formats a calendar day without shifting it across time zones', () => {
        setLocale('en');

        expect(formatReleaseDate('2026-11-23')).toBe('November 23, 2026');
        expect(formatReleaseDate('2026-01-01')).toBe('January 1, 2026');
    });

    it('follows the interface language', () => {
        setLocale('fr');

        expect(formatReleaseDate('2026-11-23')).toBe('23 novembre 2026');
    });

    it('returns null for a missing or malformed date', () => {
        expect(formatReleaseDate(null)).toBeNull();
        expect(formatReleaseDate('23/11/2026')).toBeNull();
    });
});
