import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import CertificatePanel from '@/components/monitors/CertificatePanel.vue';
import type { MonitorCertificate } from '@/types';

const certificate = (
    overrides: Partial<MonitorCertificate> = {},
): MonitorCertificate => ({
    subject: 'shop.example.com',
    issuer: "Let's Encrypt",
    not_before: '2026-09-01T00:00:00+00:00',
    not_after: '2026-12-01T00:00:00+00:00',
    checked_at: '2026-11-22T09:00:00+00:00',
    days_left: 60,
    status: 'valid',
    ...overrides,
});

describe('CertificatePanel', () => {
    it('shows an empty state until a certificate is seen', () => {
        const wrapper = mount(CertificatePanel, {
            props: { certificate: null },
        });

        expect(wrapper.find('[data-test="certificate-empty"]').exists()).toBe(
            true,
        );
        expect(wrapper.find('[data-test="certificate-status"]').exists()).toBe(
            false,
        );
    });

    it('shows the subject, issuer and days left', () => {
        const wrapper = mount(CertificatePanel, {
            props: { certificate: certificate() },
        });

        expect(wrapper.text()).toContain('shop.example.com');
        expect(wrapper.text()).toContain("Let's Encrypt");
        expect(wrapper.get('[data-test="certificate-status"]').text()).toBe(
            'Expires in 60 days',
        );
    });

    it.each<[Partial<MonitorCertificate>, string, string]>([
        [{ status: 'expiring', days_left: 1 }, 'Expires in 1 day', 'amber'],
        [{ status: 'expiring', days_left: 0 }, 'Expires today', 'amber'],
        [{ status: 'expired', days_left: -3 }, 'Expired', 'destructive'],
    ])('flags %o as "%s"', (overrides, label, color) => {
        const wrapper = mount(CertificatePanel, {
            props: { certificate: certificate(overrides) },
        });
        const badge = wrapper.get('[data-test="certificate-status"]');

        expect(badge.text()).toBe(label);
        expect(badge.classes().join(' ')).toContain(color);
    });
});
