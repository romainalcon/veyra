import { afterEach, describe, expect, it } from 'vitest';
import { currentLocale, setLocale, t, tc } from '@/lib/i18n';

describe('i18n', () => {
    afterEach(async () => {
        await setLocale('en');
    });

    it('returns the English source string without a dictionary', () => {
        expect(t('Monitors')).toBe('Monitors');
        expect(t('Down for :duration', { duration: '5m' })).toBe('Down for 5m');
    });

    it('translates with the French dictionary', async () => {
        await setLocale('fr');

        expect(currentLocale()).toBe('fr');
        expect(t('Monitors')).toBe('Moniteurs');
        expect(document.documentElement.lang).toBe('fr');
    });

    it('accepts regional variants and falls back to English for unknown languages', async () => {
        await setLocale('fr-CA');
        expect(currentLocale()).toBe('fr');

        await setLocale('xx');
        expect(currentLocale()).toBe('en');
        expect(t('Monitors')).toBe('Monitors');
    });

    it('applies Laravel-style replacements in a single pass', () => {
        expect(t(':name and :Name and :NAME', { name: 'api' })).toBe(
            'api and Api and API',
        );
        expect(t(':a :b', { a: ':b', b: 'x' })).toBe(':b x');
    });

    it('chooses plural forms per language', async () => {
        expect(tc(':count day|:count days', 1)).toBe('1 day');
        expect(tc(':count day|:count days', 0)).toBe('0 days');

        await setLocale('fr');
        expect(tc(':count day|:count days', 0)).toBe('0 jour');
        expect(tc(':count day|:count days', 2)).toBe('2 jours');
    });
});
