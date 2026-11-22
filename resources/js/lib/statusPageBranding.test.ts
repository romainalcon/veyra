import { describe, expect, it } from 'vitest';
import {
    accentButtonStyle,
    assetUrl,
    brandingStyle,
    contrastRatio,
} from '@/lib/statusPageBranding';
import type { StatusPageBranding } from '@/types';

const branding = (
    overrides: Partial<StatusPageBranding> = {},
): StatusPageBranding => ({
    accent: '#4f46e5',
    accent_foreground: '#ffffff',
    text_light: true,
    text_dark: false,
    logo: null,
    favicon: null,
    ...overrides,
});

describe('status page branding', () => {
    it('computes WCAG contrast ratios like AccentColor.php', () => {
        expect(contrastRatio('#000000', '#ffffff')).toBeCloseTo(21, 1);
        expect(contrastRatio('#767676', '#ffffff')).toBeCloseTo(4.54, 2);
    });

    it('uses the accent as text only where the server found it readable', () => {
        expect(brandingStyle(branding())).toEqual({
            '--page-accent': '#4f46e5',
            '--page-accent-text-light': '#4f46e5',
        });
        expect(
            brandingStyle(branding({ text_light: false, text_dark: true })),
        ).toEqual({
            '--page-accent': '#4f46e5',
            '--page-accent-text-dark': '#4f46e5',
        });
    });

    it('leaves unbranded pages on the neutral theme', () => {
        const unbranded = branding({
            accent: null,
            accent_foreground: null,
        });

        expect(brandingStyle(unbranded)).toEqual({});
        expect(accentButtonStyle(unbranded)).toBeUndefined();
    });

    it('fills buttons with the accent and its chosen foreground', () => {
        expect(
            accentButtonStyle(
                branding({ accent: '#facc15', accent_foreground: '#000000' }),
            ),
        ).toEqual({ backgroundColor: '#facc15', color: '#000000' });
    });

    it('builds asset URLs for both the path and custom domain hosts', () => {
        expect(assetUrl('/status/acme', 'logo-abc.png')).toBe(
            '/status/acme/assets/logo-abc.png',
        );
        expect(assetUrl('', 'logo-abc.png')).toBe('/assets/logo-abc.png');
    });
});
