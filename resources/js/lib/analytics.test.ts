import { describe, expect, it } from 'vitest';
import { coverageNote, formatUptime, uptimeTone } from '@/lib/analytics';

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
