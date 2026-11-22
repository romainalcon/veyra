import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import WebhookSecretPanel from '@/components/notifications/WebhookSecretPanel.vue';
import type { NotificationChannelSummary } from '@/types';

const page = vi.hoisted(() => ({
    flash: {} as { webhookSecret?: { channel_id: string; secret: string } },
}));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<Record<string, unknown>>()),
    usePage: () => page,
}));

const channel: NotificationChannelSummary = {
    id: '01930000-0000-7000-8000-000000000001',
    type: 'webhook',
    name: 'Automation',
    summary: 'POST to hooks.example.com',
    enabled: true,
    is_default: false,
    monitors_count: 0,
    recipients: [],
};

const mountPanel = () =>
    mount(WebhookSecretPanel, {
        props: { channel, organizationSlug: 'acme', canManage: false },
    });

describe('WebhookSecretPanel', () => {
    beforeEach(() => {
        page.flash = {};
    });

    it('shows no secret unless one was just issued', () => {
        const wrapper = mountPanel();

        expect(wrapper.find('[data-test="webhook-secret"]').exists()).toBe(
            false,
        );
        expect(
            wrapper.get('[data-test="webhook-verification"]').text(),
        ).toContain('Standard Webhooks');
    });

    it('shows the secret issued for this channel', () => {
        page.flash = {
            webhookSecret: { channel_id: channel.id, secret: 'whsec_abc123' },
        };

        expect(
            mountPanel().get('[data-test="webhook-secret"]').text(),
        ).toContain('whsec_abc123');
    });

    it('never shows the secret of another channel', () => {
        page.flash = {
            webhookSecret: { channel_id: 'other', secret: 'whsec_other' },
        };

        const wrapper = mountPanel();

        expect(wrapper.find('[data-test="webhook-secret"]').exists()).toBe(
            false,
        );
        expect(wrapper.text()).not.toContain('whsec_other');
    });
});
