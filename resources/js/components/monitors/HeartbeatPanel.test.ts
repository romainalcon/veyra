import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import HeartbeatPanel from '@/components/monitors/HeartbeatPanel.vue';
import type { MonitorDetail } from '@/types';

const page = vi.hoisted(() => ({ flash: {} as { pingUrl?: string } }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<Record<string, unknown>>()),
    usePage: () => page,
}));

const monitor = {
    id: '01930000-0000-7000-8000-000000000001',
    type: 'heartbeat',
    heartbeat: {
        grace_seconds: 300,
        last_ping_at: null,
        last_ping_failed: false,
        ping_url_prefix: 'https://veyra.test/ping/',
    },
} as MonitorDetail;

const mountPanel = () =>
    mount(HeartbeatPanel, {
        props: { monitor, organizationSlug: 'acme', canUpdate: false },
    });

describe('HeartbeatPanel', () => {
    beforeEach(() => {
        page.flash = {};
    });

    it('uses a placeholder in examples when the URL is not shown', () => {
        const wrapper = mountPanel();
        const examples = wrapper.get('[data-test="ping-examples"]').text();

        expect(wrapper.find('[data-test="ping-url"]').exists()).toBe(false);
        expect(examples).toContain(
            'curl -fsS -m 10 --retry 3 -o /dev/null https://veyra.test/ping/<your-ping-token>',
        );
        expect(examples).toContain('<your-ping-token>/fail');
        expect(wrapper.text()).toContain('never');
    });

    it('fills the examples with the freshly issued URL', () => {
        page.flash = { pingUrl: 'https://veyra.test/ping/abc123' };

        const wrapper = mountPanel();
        const examples = wrapper.get('[data-test="ping-examples"]').text();

        expect(wrapper.get('[data-test="ping-url"]').text()).toContain(
            'https://veyra.test/ping/abc123',
        );
        expect(examples).toContain('https://veyra.test/ping/abc123/fail');
        expect(examples).toContain(
            '/path/to/job.sh && curl -fsS -m 10 --retry 3 -o /dev/null https://veyra.test/ping/abc123',
        );
        expect(examples).not.toContain('<your-ping-token>');
    });

    it('hides regeneration from members who cannot update', () => {
        expect(
            mountPanel().find('[data-test="regenerate-ping-url"]').exists(),
        ).toBe(false);
    });
});
