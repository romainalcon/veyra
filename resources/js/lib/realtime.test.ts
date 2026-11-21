import { afterEach, describe, expect, it, vi } from 'vitest';
import { debounce, shouldPoll, shouldResync } from '@/lib/realtime';

describe('shouldResync', () => {
    it('resyncs after recovering from a lost connection', () => {
        expect(shouldResync('reconnecting', 'connected')).toBe(true);
        expect(shouldResync('disconnected', 'connected')).toBe(true);
        expect(shouldResync('failed', 'connected')).toBe(true);
    });

    it('does not resync on the initial connection', () => {
        expect(shouldResync(undefined, 'connected')).toBe(false);
        expect(shouldResync('connecting', 'connected')).toBe(false);
    });

    it('does not resync while disconnected', () => {
        expect(shouldResync('connected', 'reconnecting')).toBe(false);
        expect(shouldResync('connected', 'disconnected')).toBe(false);
    });
});

describe('shouldPoll', () => {
    it('polls when real-time is not configured', () => {
        expect(shouldPoll(false, 'connected')).toBe(true);
    });

    it('polls only while the socket is not connected', () => {
        expect(shouldPoll(true, 'connected')).toBe(false);
        expect(shouldPoll(true, 'reconnecting')).toBe(true);
        expect(shouldPoll(true, undefined)).toBe(true);
    });
});

describe('debounce', () => {
    afterEach(() => vi.useRealTimers());

    it('coalesces bursts into one call', () => {
        vi.useFakeTimers();
        const callback = vi.fn();
        const debounced = debounce(callback, 500);

        debounced();
        debounced();
        vi.advanceTimersByTime(499);
        debounced();
        vi.advanceTimersByTime(500);

        expect(callback).toHaveBeenCalledTimes(1);
    });

    it('can be cancelled', () => {
        vi.useFakeTimers();
        const callback = vi.fn();
        const debounced = debounce(callback, 500);

        debounced();
        debounced.cancel();
        vi.advanceTimersByTime(1000);

        expect(callback).not.toHaveBeenCalled();
    });
});
