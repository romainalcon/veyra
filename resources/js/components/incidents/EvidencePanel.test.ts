import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import EvidencePanel from '@/components/incidents/EvidencePanel.vue';
import type { IncidentEvidence } from '@/types';

const evidence = (
    overrides: Partial<IncidentEvidence> = {},
): IncidentEvidence => ({
    checked_at: '2026-11-23T10:00:01+00:00',
    status_code: 503,
    http_version: '1.1',
    error_code: 'unexpected_status',
    error_label: 'Unexpected status code',
    content_type: 'text/html',
    headers: { server: 'nginx', 'retry-after': '120' },
    body_excerpt: '<script>alert(1)</script>',
    body_truncated: false,
    body_omitted_reason: null,
    ...overrides,
});

describe('EvidencePanel', () => {
    it('shows an empty state without evidence', () => {
        const wrapper = mount(EvidencePanel, { props: { evidence: null } });

        expect(wrapper.find('[data-test="evidence-empty"]').exists()).toBe(
            true,
        );
    });

    it('renders the status, headers and body as escaped text', () => {
        const wrapper = mount(EvidencePanel, {
            props: { evidence: evidence() },
        });

        expect(wrapper.find('[data-test="evidence-status"]').text()).toContain(
            '503',
        );
        expect(wrapper.find('[data-test="evidence-headers"]').text()).toContain(
            'nginx',
        );
        const body = wrapper.find('[data-test="evidence-body"]');
        expect(body.text()).toBe('<script>alert(1)</script>');
        expect(body.element.querySelector('script')).toBeNull();
    });

    it('explains omitted and truncated bodies', () => {
        const omitted = mount(EvidencePanel, {
            props: {
                evidence: evidence({
                    body_excerpt: null,
                    body_omitted_reason: 'binary',
                }),
            },
        });
        expect(
            omitted.find('[data-test="evidence-body-omitted"]').exists(),
        ).toBe(true);

        const truncated = mount(EvidencePanel, {
            props: { evidence: evidence({ body_truncated: true }) },
        });
        expect(
            truncated.find('[data-test="evidence-truncated"]').exists(),
        ).toBe(true);
    });
});
