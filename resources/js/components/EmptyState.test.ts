import { mount } from '@vue/test-utils';
import { Activity } from '@lucide/vue';
import { describe, expect, it } from 'vitest';
import EmptyState from '@/components/EmptyState.vue';

describe('EmptyState', () => {
    it('renders the title, description and action', () => {
        const wrapper = mount(EmptyState, {
            props: {
                icon: Activity,
                title: 'No monitors yet',
                description: 'Add an endpoint.',
            },
            slots: { default: '<a href="/create">Create</a>' },
            attrs: { 'data-test': 'monitors-empty-state' },
        });

        expect(wrapper.attributes('data-test')).toBe('monitors-empty-state');
        expect(wrapper.find('h2').text()).toBe('No monitors yet');
        expect(wrapper.text()).toContain('Add an endpoint.');
        expect(wrapper.find('a').attributes('href')).toBe('/create');
        expect(wrapper.find('svg').attributes('aria-hidden')).toBe('true');
    });

    it('omits the heading and the action area when not given', () => {
        const wrapper = mount(EmptyState, {
            props: { icon: Activity, description: 'Nothing planned.' },
        });

        expect(wrapper.find('h2').exists()).toBe(false);
        expect(wrapper.find('.mt-4').exists()).toBe(false);
    });

    it('is neutral unless told otherwise', () => {
        const neutral = mount(EmptyState, { props: { icon: Activity } });
        const success = mount(EmptyState, {
            props: { icon: Activity, tone: 'success' },
        });

        expect(neutral.find('[data-tone]').attributes('data-tone')).toBe(
            'neutral',
        );
        expect(neutral.html()).not.toContain('status-up');
        expect(success.html()).toContain('bg-status-up-soft');
    });
});
