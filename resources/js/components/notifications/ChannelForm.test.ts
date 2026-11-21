import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ChannelForm from '@/components/notifications/ChannelForm.vue';

const action = {
    action: '/o/acme/notification-channels/1',
    method: 'post' as const,
};
const types = [
    { value: 'email' as const, label: 'E-mail' },
    { value: 'discord' as const, label: 'Discord' },
];

describe('ChannelForm', () => {
    it('never pre-fills the stored Discord webhook', () => {
        const wrapper = mount(ChannelForm, {
            props: {
                action,
                types,
                maxRecipients: 10,
                channel: {
                    id: '1',
                    type: 'discord',
                    name: 'Alerts',
                    summary: 'Webhook …abcd',
                    enabled: true,
                    is_default: false,
                    monitors_count: 2,
                    recipients: [],
                },
            },
        });

        const input = wrapper.get('input[name="webhook_url"]');
        expect((input.element as HTMLInputElement).value).toBe('');
        expect(input.attributes('type')).toBe('password');
        expect(wrapper.text()).toContain('Current: Webhook …abcd');
    });

    it('shows recipients for e-mail channels', () => {
        const wrapper = mount(ChannelForm, {
            props: {
                action,
                types,
                maxRecipients: 10,
                channel: {
                    id: '2',
                    type: 'email',
                    name: 'Ops',
                    summary: '2 recipient(s)',
                    enabled: true,
                    is_default: true,
                    monitors_count: 0,
                    recipients: ['a@example.com', 'b@example.com'],
                },
            },
        });

        expect(
            (
                wrapper.get('input[name="recipients"]')
                    .element as HTMLInputElement
            ).value,
        ).toBe('a@example.com, b@example.com');
    });
});
