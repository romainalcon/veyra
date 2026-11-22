import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

/*
 * The theme tokens in resources/css/app.css must keep every text/background
 * pair readable (WCAG AA, ADR-035). This test parses the CSS so it cannot
 * drift from what the browser actually renders.
 */
const css = readFileSync(join(__dirname, '../../css/app.css'), 'utf8');

type Oklch = [number, number, number];

function block(selector: string): Record<string, Oklch> {
    const start = css.indexOf(`${selector} {`);
    const body = css.slice(start, css.indexOf('}', start));
    const tokens: Record<string, Oklch> = {};

    for (const match of body.matchAll(
        /--([\w-]+):\s*oklch\(([\d.]+) ([\d.]+) ([\d.]+)\)/g,
    )) {
        tokens[match[1]] = [
            Number(match[2]),
            Number(match[3]),
            Number(match[4]),
        ];
    }

    return tokens;
}

/** Relative luminance of an OKLCH colour, gamut-clipped to sRGB. */
function luminance([lightness, chroma, hue]: Oklch): number {
    const radians = (hue * Math.PI) / 180;
    const a = chroma * Math.cos(radians);
    const b = chroma * Math.sin(radians);
    const l = (lightness + 0.3963377774 * a + 0.2158037573 * b) ** 3;
    const m = (lightness - 0.1055613458 * a - 0.0638541728 * b) ** 3;
    const s = (lightness - 0.0894841775 * a - 1.291485548 * b) ** 3;
    const clip = (value: number) => Math.min(1, Math.max(0, value));
    const red = clip(4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s);
    const green = clip(-1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s);
    const blue = clip(-0.0041960863 * l - 0.7034186147 * m + 1.707614701 * s);

    return 0.2126 * red + 0.7152 * green + 0.0722 * blue;
}

function contrast(first: Oklch, second: Oklch): number {
    const [light, dark] = [luminance(first), luminance(second)].sort(
        (x, y) => y - x,
    );

    return (light + 0.05) / (dark + 0.05);
}

const statuses = ['up', 'degraded', 'down', 'maintenance', 'unknown'];

/** [foreground, background] pairs used together as text. */
const textPairs: [string, string][] = [
    ['foreground', 'background'],
    ['card-foreground', 'card'],
    ['muted-foreground', 'background'],
    ['muted-foreground', 'muted'],
    ['muted-foreground', 'card'],
    ['primary-foreground', 'primary'],
    ['primary', 'background'],
    ['accent-foreground', 'accent'],
    ['destructive-foreground', 'background'],
    ['destructive-foreground', 'card'],
    ['sidebar-foreground', 'sidebar'],
    ['sidebar-accent-foreground', 'sidebar-accent'],
    ['sidebar-primary-foreground', 'sidebar-primary'],
    ...statuses.flatMap((status): [string, string][] => [
        [`status-${status}-foreground`, `status-${status}`],
        [`status-${status}-soft-foreground`, `status-${status}-soft`],
        [`status-${status}-soft-foreground`, 'background'],
        [`status-${status}-soft-foreground`, 'card'],
    ]),
];

describe.each([':root', '.dark'])('theme %s', (selector) => {
    const tokens = block(selector);

    it.each(textPairs)('%s on %s meets WCAG AA', (foreground, background) => {
        expect(tokens[foreground], foreground).toBeDefined();
        expect(tokens[background], background).toBeDefined();
        expect(
            contrast(tokens[foreground], tokens[background]),
        ).toBeGreaterThanOrEqual(4.5);
    });

    it.each(statuses)(
        'status %s stands out from the page as a graphic (3:1)',
        (status) => {
            expect(
                contrast(tokens[`status-${status}`], tokens.background),
            ).toBeGreaterThanOrEqual(3);
        },
    );
});
