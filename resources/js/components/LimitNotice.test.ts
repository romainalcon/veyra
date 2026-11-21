import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import LimitNotice from '@/components/LimitNotice.vue';
import type { LimitUsage } from '@/types';

const page = vi.hoisted(() => ({
    props: {
        currentOrganization: {
            limits: {} as Record<string, LimitUsage>,
        },
    },
}));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<Record<string, unknown>>()),
    usePage: () => page,
}));

const mountNotice = () =>
    mount(LimitNotice, {
        props: {
            feature: 'monitors',
            resource: 'active monitors',
            hint: 'Pause a monitor first.',
        },
    });

describe('LimitNotice', () => {
    beforeEach(() => {
        page.props.currentOrganization.limits = {};
    });

    it('renders nothing when the feature is unlimited (Community)', () => {
        expect(mountNotice().text()).toBe('');
    });

    it('shows the usage below the limit', () => {
        page.props.currentOrganization.limits = {
            monitors: { used: 2, limit: 5 },
        };

        const wrapper = mountNotice();

        expect(wrapper.find('[data-test="limit-usage"]').text()).toBe(
            '2 of 5 active monitors',
        );
        expect(wrapper.find('[data-test="limit-reached"]').exists()).toBe(
            false,
        );
    });

    it('explains what to do once the limit is reached or exceeded', () => {
        page.props.currentOrganization.limits = {
            monitors: { used: 6, limit: 5 },
        };

        const reached = mountNotice().find('[data-test="limit-reached"]');

        expect(reached.text()).toContain('Limit reached');
        expect(reached.text()).toContain('6 of 5 active monitors');
        expect(reached.text()).toContain('Pause a monitor first.');
    });
});
