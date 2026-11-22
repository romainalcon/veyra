import {
    CircleCheck,
    CircleHelp,
    CircleX,
    TriangleAlert,
    Wrench,
    type LucideIcon,
} from '@lucide/vue';
import type { PublicComponentStatus } from '@/types';

/*
 * `label` and `headline` are English source strings: translate them with t()
 * where they are rendered. Each status has its own icon so the state never
 * depends on colour alone (ADR-035).
 */

export const publicStatusStyles: Record<
    PublicComponentStatus,
    {
        label: string;
        headline: string;
        icon: LucideIcon;
        text: string;
        banner: string;
    }
> = {
    operational: {
        label: 'Operational',
        headline: 'All systems operational',
        icon: CircleCheck,
        text: 'text-status-up-soft-foreground',
        banner: 'border-status-up/30 bg-status-up-soft text-status-up-soft-foreground',
    },
    degraded: {
        label: 'Degraded performance',
        headline: 'Some systems are degraded',
        icon: TriangleAlert,
        text: 'text-status-degraded-soft-foreground',
        banner: 'border-status-degraded/40 bg-status-degraded-soft text-status-degraded-soft-foreground',
    },
    outage: {
        label: 'Outage',
        headline: 'Some systems are experiencing an outage',
        icon: CircleX,
        text: 'text-status-down-soft-foreground',
        banner: 'border-status-down/30 bg-status-down-soft text-status-down-soft-foreground',
    },
    maintenance: {
        label: 'Under maintenance',
        headline: 'Planned maintenance in progress',
        icon: Wrench,
        text: 'text-status-maintenance-soft-foreground',
        banner: 'border-status-maintenance/30 bg-status-maintenance-soft text-status-maintenance-soft-foreground',
    },
    no_data: {
        label: 'No data',
        headline: 'Status currently unavailable',
        icon: CircleHelp,
        text: 'text-muted-foreground',
        banner: 'border-border bg-muted text-muted-foreground',
    },
};

export function publicStatusStyle(status: PublicComponentStatus) {
    return publicStatusStyles[status] ?? publicStatusStyles.no_data;
}
