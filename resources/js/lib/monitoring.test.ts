import { describe, expect, it } from 'vitest';
import {
    formatDuration,
    formatInterval,
    parseExpectedStatuses,
} from '@/lib/monitoring';

describe('parseExpectedStatuses', () => {
    it('parses codes and ranges like the server', () => {
        expect(parseExpectedStatuses('200-299, 301')).toEqual([
            { from: 200, to: 299 },
            { from: 301, to: 301 },
        ]);
    });

    it('rejects invalid expressions', () => {
        for (const expression of ['', 'ok', '20', '600', '299-200']) {
            expect(parseExpectedStatuses(expression)).toBeNull();
        }
    });
});

describe('formatting helpers', () => {
    it('formats durations and intervals', () => {
        expect(formatDuration(null)).toBe('—');
        expect(formatDuration(250)).toBe('250 ms');
        expect(formatDuration(1500)).toBe('1.50 s');
        expect(formatInterval(300)).toBe('5 min');
        expect(formatInterval(3600)).toBe('1 h');
    });
});
