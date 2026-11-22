import type { StatusPageBranding } from '@/types';

/**
 * CSS variables of a branded public page. Text colours are only set when the
 * server found the accent readable on that theme's background.
 */
export function brandingStyle(
    branding: StatusPageBranding,
): Record<string, string> {
    if (!branding.accent) {
        return {};
    }

    return {
        '--page-accent': branding.accent,
        ...(branding.text_light
            ? { '--page-accent-text-light': branding.accent }
            : {}),
        ...(branding.text_dark
            ? { '--page-accent-text-dark': branding.accent }
            : {}),
    };
}

/** Background and foreground for accent-filled controls. */
export function accentButtonStyle(
    branding: StatusPageBranding,
): Record<string, string> | undefined {
    if (!branding.accent || !branding.accent_foreground) {
        return undefined;
    }

    return {
        backgroundColor: branding.accent,
        color: branding.accent_foreground,
    };
}

/** URL of a branding file under the page's base path ('' on a custom domain). */
export function assetUrl(basePath: string, file: string): string {
    return `${basePath}/assets/${file}`;
}

/** WCAG 2.x contrast ratio between two #rrggbb colours (1 to 21). */
export function contrastRatio(first: string, second: string): number {
    const luminance = (hex: string) => {
        const [r, g, b] = [1, 3, 5].map((index) => {
            const value = parseInt(hex.slice(index, index + 2), 16) / 255;

            return value <= 0.04045
                ? value / 12.92
                : ((value + 0.055) / 1.055) ** 2.4;
        });

        return 0.2126 * r + 0.7152 * g + 0.0722 * b;
    };

    const a = luminance(first);
    const b = luminance(second);

    return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
}

/** sRGB equivalents of the public page backgrounds (see AccentColor.php). */
export const PAGE_BACKGROUNDS = { light: '#f9fafc', dark: '#0b0f18' } as const;
