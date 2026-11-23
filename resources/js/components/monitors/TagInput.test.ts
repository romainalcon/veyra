import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import TagInput from '@/components/monitors/TagInput.vue';

const mountInput = (modelValue: string[], max = 3) =>
    mount(TagInput, {
        props: {
            modelValue,
            'onUpdate:modelValue': () => {},
            suggestions: ['production'],
            max,
            maxLength: 32,
        },
    });

describe('TagInput', () => {
    it('submits the tags with the submitted flag, even when empty', () => {
        const empty = mountInput([]);
        expect(empty.find('input[name="tags_submitted"]').exists()).toBe(true);
        expect(empty.findAll('input[name="tags[]"]')).toHaveLength(0);

        const wrapper = mountInput(['api', 'eu']);
        expect(
            wrapper
                .findAll('input[name="tags[]"]')
                .map((input) => (input.element as HTMLInputElement).value),
        ).toEqual(['api', 'eu']);
    });

    it('adds normalized tags on Enter and ignores duplicates', async () => {
        const wrapper = mountInput(['api']);
        const input = wrapper.get('input#tags');

        await input.setValue('  EU   West ');
        await input.trigger('keydown', { key: 'Enter' });
        await input.setValue('API');
        await input.trigger('keydown', { key: 'Enter' });

        const updates = wrapper.emitted('update:modelValue') ?? [];
        expect(updates).toEqual([[['api', 'eu west']]]);
    });

    it('removes a tag and stops at the maximum', async () => {
        const wrapper = mountInput(['a', 'b', 'c'], 3);

        expect(wrapper.get('input#tags').attributes('disabled')).toBeDefined();

        await wrapper.get('button[aria-label]').trigger('click');
        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([['b', 'c']]);
    });
});
