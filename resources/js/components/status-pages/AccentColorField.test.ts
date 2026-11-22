import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import AccentColorField from '@/components/status-pages/AccentColorField.vue';

describe('AccentColorField', () => {
    it('previews the accent with a readable foreground and contrast notes', () => {
        const wrapper = mount(AccentColorField, {
            props: { modelValue: '#facc15' },
        });

        const preview = wrapper.get('[data-test="accent-preview"]');
        expect(preview.attributes('style')).toContain('color: rgb(0, 0, 0)');

        const notes = wrapper.get('[data-test="accent-contrast"]').text();
        expect(notes).toContain('Light theme');
        expect(notes).toContain('too low for text');
        expect(notes).toContain('Dark theme');
    });

    it('shows no preview for an empty or invalid colour', () => {
        for (const modelValue of ['', '#12', 'red']) {
            const wrapper = mount(AccentColorField, { props: { modelValue } });

            expect(wrapper.find('[data-test="accent-preview"]').exists()).toBe(
                false,
            );
        }
    });

    it('can be locked when branding is not included', () => {
        const wrapper = mount(AccentColorField, {
            props: { modelValue: '', disabled: true },
        });

        expect(
            wrapper.get('input[name="accent_color"]').attributes('disabled'),
        ).toBeDefined();
        expect(wrapper.text()).toContain('Branding is not included');
    });
});
