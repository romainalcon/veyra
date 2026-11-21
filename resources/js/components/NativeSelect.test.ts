import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import NativeSelect from '@/components/NativeSelect.vue';

describe('NativeSelect', () => {
    const options = [
        { value: 'admin', label: 'Admin' },
        { value: 'member', label: 'Member' },
    ];

    it('renders the given options and forwards attributes', () => {
        const wrapper = mount(NativeSelect, {
            props: { options, modelValue: 'member' },
            attrs: { name: 'role' },
        });

        const select = wrapper.get('select');
        expect(select.attributes('name')).toBe('role');
        expect(wrapper.findAll('option').map((o) => o.text())).toEqual([
            'Admin',
            'Member',
        ]);
        expect((select.element as HTMLSelectElement).value).toBe('member');
    });

    it('emits the selected value', async () => {
        const wrapper = mount(NativeSelect, {
            props: { options, modelValue: 'member' },
        });

        await wrapper.get('select').setValue('admin');

        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['admin']);
    });
});
