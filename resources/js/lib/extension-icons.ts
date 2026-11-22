import { ChartColumn, CreditCard, Puzzle, Receipt, Shield } from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import type { ExtensionNavItem } from '@/types';

/** Icons extensions may use (OrganizationNavigation::ICONS, ADR-030). */
const extensionIcons: Record<ExtensionNavItem['icon'], LucideIcon> = {
    'credit-card': CreditCard,
    receipt: Receipt,
    chart: ChartColumn,
    puzzle: Puzzle,
    shield: Shield,
};

export function extensionIcon(icon: string): LucideIcon {
    return extensionIcons[icon as ExtensionNavItem['icon']] ?? Puzzle;
}
