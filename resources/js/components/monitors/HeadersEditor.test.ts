import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import HeadersEditor from '@/components/monitors/HeadersEditor.vue';

type Header = { name: string; value: string };

function mountEditor(initial: Header[], errors?: Record<string, string>) {
    const wrapper = mount(HeadersEditor, {
        props: {
            modelValue: initial,
            errors,
            'onUpdate:modelValue': (value: Header[]) =>
                wrapper.setProps({ modelValue: value }),
        },
    });

    return wrapper;
}

describe('HeadersEditor', () => {
    it('masks header values', () => {
        const wrapper = mountEditor([{ name: 'Authorization', value: 'x' }]);

        expect(
            wrapper.get('input[name="headers[0][value]"]').attributes('type'),
        ).toBe('password');
    });

    it('adds and removes header rows', async () => {
        const wrapper = mountEditor([{ name: 'Authorization', value: 'x' }]);

        const addButton = wrapper
            .findAll('button')
            .find((button) => button.text().includes('Add header'));
        await addButton?.trigger('click');

        expect(wrapper.findAll('input[name$="[name]"]')).toHaveLength(2);

        await wrapper
            .get('button[aria-label="Remove header 1"]')
            .trigger('click');

        expect(wrapper.findAll('input[name$="[name]"]')).toHaveLength(1);
    });

    it('shows field errors', () => {
        const wrapper = mountEditor([{ name: 'Host', value: 'x' }], {
            'headers.0.name': 'The host header cannot be set.',
        });

        expect(wrapper.text()).toContain('The host header cannot be set.');
    });
});
