import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import StatusBadge from '@/components/monitors/StatusBadge.vue';
import type { MonitorStatus } from '@/types';

const statuses: [MonitorStatus, string][] = [
    ['up', 'Up'],
    ['degraded', 'Degraded'],
    ['down', 'Down'],
    ['unknown', 'Unknown'],
    ['maintenance', 'Maintenance'],
];

describe('StatusBadge', () => {
    it.each(statuses)('renders the %s state', (status, label) => {
        const wrapper = mount(StatusBadge, { props: { status } });

        expect(wrapper.text()).toBe(label);
        expect(wrapper.attributes('data-status')).toBe(status);
        expect(wrapper.classes()).toContain(
            status === 'unknown'
                ? 'bg-status-unknown-soft'
                : `bg-status-${status}`,
        );
    });

    it('gives every state its own icon, so colour is never the only cue', () => {
        const icons = statuses.map(([status]) => {
            const svg = mount(StatusBadge, { props: { status } }).find('svg');

            expect(svg.attributes('aria-hidden')).toBe('true');

            return svg.html().replace(/data-icon="\w+"/, '');
        });

        expect(new Set(icons).size).toBe(statuses.length);
    });

    it('never presents unknown as a failure', () => {
        const wrapper = mount(StatusBadge, { props: { status: 'unknown' } });

        expect(wrapper.classes().join(' ')).not.toContain('status-down');
    });
});
