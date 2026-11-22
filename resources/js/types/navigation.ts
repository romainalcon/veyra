import type { InertiaLinkProps } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    /** Stable identifier, independent of the translated title. */
    id?: string;
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon;
    isActive?: boolean;
    badge?: number;
};

/** Public site link contributed by an optional extension (ADR-032). */
export type PublicNavItem = {
    id: string;
    title: string;
    href: string;
    placement: 'header' | 'footer';
};
