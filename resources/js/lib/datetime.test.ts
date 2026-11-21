import { describe, expect, it } from 'vitest';
import { isoToLocalInput, localInputToIso } from '@/lib/datetime';

describe('datetime-local helpers', () => {
    it('round-trips an instant through the local input format', () => {
        const iso = '2026-11-22T10:30:00.000Z';

        expect(localInputToIso(isoToLocalInput(iso))).toBe(iso);
    });

    it('returns empty strings for missing or invalid values', () => {
        expect(isoToLocalInput(null)).toBe('');
        expect(isoToLocalInput('not a date')).toBe('');
        expect(localInputToIso('')).toBe('');
        expect(localInputToIso('nope')).toBe('');
    });
});
