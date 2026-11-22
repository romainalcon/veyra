import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { ComputedRef } from 'vue';
import type { PublicNavItem } from '@/types/navigation';

/**
 * Public site links registered by optional extensions for one placement
 * (ADR-032): none on the Community edition, nor on public status pages.
 */
export function usePublicNavigation(
    placement: PublicNavItem['placement'],
): ComputedRef<PublicNavItem[]> {
    const page = usePage();

    return computed(() =>
        (page.props.publicNavigation ?? []).filter(
            (item) => item.placement === placement,
        ),
    );
}
