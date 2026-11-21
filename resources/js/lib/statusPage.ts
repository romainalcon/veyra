import type { PublicComponentStatus } from '@/types';

/*
 * `label` and `headline` are English source strings: translate them with t()
 * where they are rendered.
 */

export const publicStatusStyles: Record<
    PublicComponentStatus,
    { label: string; headline: string; dot: string; banner: string }
> = {
    operational: {
        label: 'Operational',
        headline: 'All systems operational',
        dot: 'bg-emerald-500',
        banner: 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-950/40 dark:text-emerald-300',
    },
    degraded: {
        label: 'Degraded performance',
        headline: 'Some systems are degraded',
        dot: 'bg-amber-500',
        banner: 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-300',
    },
    outage: {
        label: 'Outage',
        headline: 'Some systems are experiencing an outage',
        dot: 'bg-red-500',
        banner: 'border-red-200 bg-red-50 text-red-800 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-300',
    },
    maintenance: {
        label: 'Under maintenance',
        headline: 'Planned maintenance in progress',
        dot: 'bg-sky-500',
        banner: 'border-sky-200 bg-sky-50 text-sky-800 dark:border-sky-900/50 dark:bg-sky-950/40 dark:text-sky-300',
    },
    no_data: {
        label: 'No data',
        headline: 'Status currently unavailable',
        dot: 'bg-muted-foreground/40',
        banner: 'border-border bg-muted text-muted-foreground',
    },
};

export function publicStatusStyle(status: PublicComponentStatus) {
    return publicStatusStyles[status] ?? publicStatusStyles.no_data;
}
