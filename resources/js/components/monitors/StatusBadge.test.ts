import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import StatusBadge from '@/components/monitors/StatusBadge.vue';
import type { MonitorStatus } from '@/types';

describe('StatusBadge', () => {
    it.each<[MonitorStatus, string]>([
        ['up', 'Up'],
        ['degraded', 'Degraded'],
        ['down', 'Down'],
        ['unknown', 'Unknown'],
    ])('renders the %s state', (status, label) => {
        const wrapper = mount(StatusBadge, { props: { status } });

        expect(wrapper.text()).toBe(label);
        expect(wrapper.attributes('data-status')).toBe(status);
    });

    it('never presents unknown as a failure', () => {
        const wrapper = mount(StatusBadge, { props: { status: 'unknown' } });

        expect(wrapper.classes().join(' ')).not.toContain('red');
    });
});
