import { describe, expect, it } from 'vitest';
import { shouldResync } from '@/lib/realtime';

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
