import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import PublicStatusLabel from '@/components/status-pages/PublicStatusLabel.vue';
import type { PublicComponentStatus } from '@/types';

describe('PublicStatusLabel', () => {
    it.each<[PublicComponentStatus, string]>([
        ['operational', 'Operational'],
        ['degraded', 'Degraded performance'],
        ['outage', 'Outage'],
        ['maintenance', 'Under maintenance'],
        ['no_data', 'No data'],
    ])('renders %s', (status, label) => {
        const wrapper = mount(PublicStatusLabel, { props: { status } });

        expect(wrapper.text()).toBe(label);
    });

    it('never presents missing data as operational', () => {
        const wrapper = mount(PublicStatusLabel, {
            props: { status: 'no_data' },
        });

        expect(wrapper.text()).not.toContain('Operational');
        expect(wrapper.html()).not.toContain('status-up');
    });
});
