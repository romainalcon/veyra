import { afterEach, describe, expect, it } from 'vitest';
import { setLocale } from '@/lib/i18n';
import {
    describeSeries,
    formatDuration,
    todayIn,
    weekdayName,
} from '@/lib/maintenance';

afterEach(() => setLocale('en'));

describe('weekdayName', () => {
    it('maps ISO days, Monday first', () => {
        expect(weekdayName(1)).toBe('Monday');
        expect(weekdayName(7)).toBe('Sunday');
    });

    it('follows the active locale', () => {
        setLocale('fr');
        expect(weekdayName(3)).toBe('mercredi');
    });
});

describe('formatDuration', () => {
    it('formats minutes and hours', () => {
        expect(formatDuration(45)).toBe('45 min');
        expect(formatDuration(120)).toBe('2 h');
        expect(formatDuration(90)).toBe('1 h 30 min');
    });
});

describe('describeSeries', () => {
    const base = { start_time: '03:00', duration_minutes: 90, month_day: null };

    it('describes daily, weekly and monthly rules', () => {
        expect(
            describeSeries({ ...base, frequency: 'daily', weekdays: [] }),
        ).toBe('Every day at 03:00 for 1 h 30 min');
        expect(
            describeSeries({ ...base, frequency: 'weekly', weekdays: [3, 1] }),
        ).toBe('Every Monday, Wednesday at 03:00 for 1 h 30 min');
        expect(
            describeSeries({
                ...base,
                frequency: 'monthly',
                weekdays: [],
                month_day: 15,
            }),
        ).toBe('Monthly on day 15 at 03:00 for 1 h 30 min');
        expect(
            describeSeries({
                ...base,
                frequency: 'monthly',
                weekdays: [],
                month_day: 31,
            }),
        ).toBe('Monthly on day 31 (or the last day) at 03:00 for 1 h 30 min');
    });

    it('is translated', () => {
        setLocale('fr');
        expect(
            describeSeries({ ...base, frequency: 'daily', weekdays: [] }),
        ).toBe('Tous les jours à 03:00 pendant 1 h 30 min');
    });
});

describe('todayIn', () => {
    it('returns the local date of the time zone', () => {
        const instant = new Date('2026-11-22T23:30:00Z');

        expect(todayIn('UTC', instant)).toBe('2026-11-22');
        expect(todayIn('Europe/Paris', instant)).toBe('2026-11-23');
        expect(todayIn('America/New_York', instant)).toBe('2026-11-22');
    });
});
