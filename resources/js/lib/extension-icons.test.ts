import { Puzzle, Shield } from '@lucide/vue';
import { describe, expect, it } from 'vitest';
import { extensionIcon } from '@/lib/extension-icons';

describe('extensionIcon', () => {
    it('maps the icons extensions may use', () => {
        expect(extensionIcon('shield')).toBe(Shield);
    });

    it('falls back to the puzzle icon for unknown names', () => {
        expect(extensionIcon('rocket')).toBe(Puzzle);
    });
});
