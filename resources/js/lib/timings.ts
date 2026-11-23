import type { CheckTimings } from '@/types';

export type TimingSegment = {
    phase: keyof CheckTimings;
    label: string;
    ms: number;
    colorClass: string;
};

/** Phases in the order they happen, with their chart colors (ADR-050). */
export const timingPhases: {
    phase: keyof CheckTimings;
    label: string;
    colorClass: string;
    color: string;
}[] = [
    {
        phase: 'redirect',
        label: 'Redirects',
        colorClass: 'bg-chart-5',
        color: 'var(--chart-5)',
    },
    {
        phase: 'dns',
        label: 'DNS',
        colorClass: 'bg-chart-1',
        color: 'var(--chart-1)',
    },
    {
        phase: 'connect',
        label: 'Connect',
        colorClass: 'bg-chart-2',
        color: 'var(--chart-2)',
    },
    {
        phase: 'tls',
        label: 'TLS',
        colorClass: 'bg-chart-3',
        color: 'var(--chart-3)',
    },
    {
        phase: 'ttfb',
        label: 'Time to first byte',
        colorClass: 'bg-chart-4',
        color: 'var(--chart-4)',
    },
    {
        phase: 'transfer',
        label: 'Transfer',
        colorClass: 'bg-primary',
        color: 'var(--primary)',
    },
];

/** Measured phases only; an empty list means no breakdown is available. */
export function timingSegments(
    timings: CheckTimings | null | undefined,
): TimingSegment[] {
    if (!timings) {
        return [];
    }

    return timingPhases
        .filter(
            ({ phase }) =>
                timings[phase] !== null && timings[phase] !== undefined,
        )
        .filter(
            ({ phase }) => phase !== 'redirect' || (timings.redirect ?? 0) > 0,
        )
        .map(({ phase, label, colorClass }) => ({
            phase,
            label,
            ms: timings[phase] as number,
            colorClass,
        }));
}
