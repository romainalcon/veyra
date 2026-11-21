import { afterEach, describe, expect, it } from 'vitest';
import { coverageNote, formatUptime, uptimeTone } from '@/lib/analytics';
import { setLocale } from '@/lib/i18n';

describe('formatUptime', () => {
    it('never shows missing data as a percentage', () => {
        expect(formatUptime(null)).toBe('No data');
    });

    it('formats percentages', () => {
        expect(formatUptime(100)).toBe('100%');
        expect(formatUptime(99.95)).toBe('99.95%');
        expect(formatUptime(98.5)).toBe('98.5%');
    });
});

describe('uptimeTone', () => {
    it('maps uptime to tones', () => {
        expect(uptimeTone(null)).toBe('none');
        expect(uptimeTone(99.95)).toBe('good');
        expect(uptimeTone(99.5)).toBe('warning');
        expect(uptimeTone(90)).toBe('bad');
    });
});

describe('coverageNote', () => {
    it('explains missing and partial data', () => {
        expect(
            coverageNote({
                uptime: null,
                coverage: null,
                partial: false,
                checks: 0,
            }),
        ).toBe('No checks recorded in this period');
        expect(
            coverageNote({
                uptime: 100,
                coverage: 0.42,
                partial: true,
                checks: 10,
            }),
        ).toBe('Partial data (42% of expected checks)');
        expect(
            coverageNote({
                uptime: 100,
                coverage: 1,
                partial: false,
                checks: 10,
            }),
        ).toBeNull();
    });
});

describe('formatUptime in French', () => {
    afterEach(() => setLocale('en'));

    it('uses the French decimal separator and spacing', () => {
        setLocale('fr');

        expect(formatUptime(99.95)).toMatch(/^99,95\s%$/);
        expect(formatUptime(null)).toBe('Aucune donnée');
    });
});
