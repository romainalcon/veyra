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
    { value: 'webhook' as const, label: 'Webhook' },
    { value: 'slack' as const, label: 'Slack' },
    { value: 'ntfy' as const, label: 'ntfy' },
    { value: 'gotify' as const, label: 'Gotify' },
    { value: 'telegram' as const, label: 'Telegram' },
    { value: 'teams' as const, label: 'Microsoft Teams' },
    { value: 'pagerduty' as const, label: 'PagerDuty' },
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

    it('never pre-fills the stored webhook endpoint', () => {
        const wrapper = mount(ChannelForm, {
            props: {
                action,
                types,
                maxRecipients: 10,
                channel: {
                    id: '3',
                    type: 'webhook',
                    name: 'Automation',
                    summary: 'POST to hooks.example.com',
                    enabled: true,
                    is_default: false,
                    monitors_count: 0,
                    recipients: [],
                },
            },
        });

        const input = wrapper.get('input[name="webhook_url"]');
        expect((input.element as HTMLInputElement).value).toBe('');
        expect(input.attributes('type')).toBe('password');
        expect(wrapper.text()).toContain('Endpoint URL');
        expect(wrapper.text()).toContain('Current: POST to hooks.example.com');
        expect(wrapper.find('[data-test="webhook-hint"]').exists()).toBe(false);
    });

    it('explains the signing secret when creating a webhook', async () => {
        const wrapper = mount(ChannelForm, {
            props: { action, types, maxRecipients: 10 },
        });

        await wrapper.get('select#type').setValue('webhook');

        expect(wrapper.get('[data-test="webhook-hint"]').text()).toContain(
            'signing secret',
        );
        expect(
            wrapper.get('input[name="webhook_url"]').attributes('placeholder'),
        ).toBe('https://hooks.example.com/veyra');
    });

    it('asks for a Slack incoming webhook when creating a Slack channel', async () => {
        const wrapper = mount(ChannelForm, {
            props: { action, types, maxRecipients: 10 },
        });

        await wrapper.get('select#type').setValue('slack');

        const input = wrapper.get('input[name="webhook_url"]');
        expect(wrapper.text()).toContain('Slack webhook URL');
        expect(input.attributes('type')).toBe('password');
        expect(input.attributes('placeholder')).toBe(
            'https://hooks.slack.com/services/…',
        );
        expect(wrapper.find('[data-test="webhook-hint"]').exists()).toBe(false);
    });

    it.each([
        ['ntfy', true, true, false],
        ['gotify', true, true, false],
        ['teams', true, false, false],
        ['telegram', false, true, true],
        ['pagerduty', false, true, false],
    ] as const)(
        'shows the fields of a %s channel',
        async (type, hasUrl, hasToken, hasChat) => {
            const wrapper = mount(ChannelForm, {
                props: { action, types, maxRecipients: 10 },
            });

            await wrapper.get('select#type').setValue(type);

            expect(wrapper.find('input[name="webhook_url"]').exists()).toBe(
                hasUrl,
            );
            expect(wrapper.find('input[name="token"]').exists()).toBe(hasToken);
            expect(wrapper.find('input[name="chat_id"]').exists()).toBe(
                hasChat,
            );
            expect(wrapper.find('input[name="recipients"]').exists()).toBe(
                false,
            );
        },
    );

    it('never pre-fills a stored token', () => {
        const wrapper = mount(ChannelForm, {
            props: {
                action,
                types,
                maxRecipients: 10,
                channel: {
                    id: '1',
                    type: 'telegram',
                    name: 'On-call',
                    summary: 'Chat -100123',
                    enabled: true,
                    is_default: false,
                    monitors_count: 0,
                    recipients: [],
                },
            },
        });

        const token = wrapper.get('input[name="token"]');
        expect((token.element as HTMLInputElement).value).toBe('');
        expect(token.attributes('type')).toBe('password');
        expect(token.attributes('placeholder')).toBe(
            'Leave empty to keep the current token',
        );
        expect(wrapper.text()).toContain('Current: Chat -100123');
    });
});
