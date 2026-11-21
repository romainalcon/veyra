import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { LimitFeature, LimitUsage } from '@/types';

/**
 * Usage of an organization limit (ADR-029). `usage` is null when the
 * feature is unlimited, which is always the case on the Community edition.
 * The server stays the authority: this only drives hints and disabled buttons.
 */
export function useOrganizationLimit(feature: LimitFeature) {
    const page = usePage();

    const usage = computed<LimitUsage | null>(
        () => page.props.currentOrganization?.limits?.[feature] ?? null,
    );

    const reached = computed(
        () => usage.value !== null && usage.value.used >= usage.value.limit,
    );

    return { usage, reached };
}
