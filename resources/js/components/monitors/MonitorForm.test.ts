import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import MonitorForm from '@/components/monitors/MonitorForm.vue';
import type { MonitorDetail } from '@/types';

const props = {
    action: { action: '/o/acme/monitors', method: 'post' as const },
    channels: [],
    escalationPolicies: [],
    intervals: [{ value: 60, label: '1 minute' }],
    methods: [
        'GET' as const,
        'HEAD' as const,
        'POST' as const,
        'PUT' as const,
        'PATCH' as const,
    ],
    contentTypes: ['application/json', 'text/plain'],
    keywordModes: ['contains' as const, 'not_contains' as const],
    maxBodyBytes: 16384,
    types: [
        { value: 'http' as const, label: 'HTTP(S)' },
        { value: 'tcp' as const, label: 'TCP port' },
        { value: 'dns' as const, label: 'DNS record' },
        { value: 'heartbeat' as const, label: 'Heartbeat' },
    ],
    tcpPorts: [22, 443],
    heartbeatPeriods: [{ value: 3600, label: '1 hour' }],
    maxGraceMinutes: 1440,
    dnsRecordTypes: [
        'A' as const,
        'AAAA' as const,
        'CNAME' as const,
        'MX' as const,
        'TXT' as const,
        'NS' as const,
    ],
    dnsResolvers: [
        { value: 'system', label: 'System resolver' },
        { value: 'cloudflare', label: 'Cloudflare (1.1.1.1)' },
    ],
    dnsMatchModes: ['contains' as const, 'exact' as const],
    maxDnsExpectedValues: 20,
    tagOptions: [],
    maxTags: 10,
    maxTagLength: 32,
    submitLabel: 'Create monitor',
};

const tcpMonitor: MonitorDetail = {
    id: '1',
    name: 'Database',
    type: 'tcp',
    target: 'db.example.com:22',
    url: null,
    method: null,
    enabled: true,
    interval_seconds: 60,
    tags: ['database'],
    latest_check: null,
    state: {
        status: 'unknown',
        last_changed_at: null,
        active_incident_id: null,
    },
    timeout_seconds: 5,
    expected_statuses: null,
    follow_redirects: true,
    header_names: [],
    body: null,
    basic_auth_username: null,
    keyword: null,
    keyword_mode: null,
    keyword_case_sensitive: false,
    latency_threshold_ms: null,
    host: 'db.example.com',
    port: 22,
    heartbeat: null,
    dns: null,
    domain: null,
    certificate: null,
    notification_channel_ids: [],
    escalation_policy_id: null,
    next_check_at: null,
    created_at: null,
};

describe('MonitorForm', () => {
    it('switches between HTTP and TCP fields on creation', async () => {
        const wrapper = mount(MonitorForm, { props });

        expect(wrapper.find('input[name="url"]').exists()).toBe(true);
        expect(wrapper.find('input[name="host"]').exists()).toBe(false);

        await wrapper.get('[data-test="type-tcp"]').setValue(true);

        expect(wrapper.find('input[name="url"]').exists()).toBe(false);
        expect(wrapper.find('input[name="expected_statuses"]').exists()).toBe(
            false,
        );
        expect(wrapper.find('input[name="host"]').exists()).toBe(true);
        expect(wrapper.find('input[name="port"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('22, 443');
    });

    it('asks heartbeats for a period and grace instead of a target', async () => {
        const wrapper = mount(MonitorForm, { props });

        await wrapper.get('[data-test="type-heartbeat"]').setValue(true);

        expect(wrapper.find('input[name="url"]').exists()).toBe(false);
        expect(wrapper.find('input[name="host"]').exists()).toBe(false);
        expect(wrapper.find('input[name="timeout"]').exists()).toBe(false);
        expect(wrapper.find('input[name="grace_minutes"]').exists()).toBe(true);
        expect(
            (
                wrapper.get('select[name="interval"]')
                    .element as HTMLSelectElement
            ).value,
        ).toBe('3600');
    });

    it('asks DNS monitors for a record, a resolver and expected values', async () => {
        const wrapper = mount(MonitorForm, { props });

        await wrapper.get('[data-test="type-dns"]').setValue(true);

        expect(wrapper.find('input[name="url"]').exists()).toBe(false);
        expect(wrapper.find('input[name="host"]').exists()).toBe(false);
        expect(wrapper.find('input[name="record_name"]').exists()).toBe(true);
        expect(wrapper.find('input[name="timeout"]').exists()).toBe(true);
        expect(
            wrapper
                .findAll('select[name="resolver"] option')
                .map((option) => option.attributes('value')),
        ).toEqual(['system', 'cloudflare']);

        await wrapper.get('select[name="record_type"]').setValue('MX');

        expect(
            wrapper
                .get('textarea[name="expected_values"]')
                .attributes('placeholder'),
        ).toBe('10 mail.example.com');
    });

    it('keeps a resolver disabled by the operator on the edited DNS monitor', () => {
        const wrapper = mount(MonitorForm, {
            props: {
                ...props,
                submitLabel: 'Save',
                monitor: {
                    ...tcpMonitor,
                    type: 'dns',
                    host: null,
                    port: null,
                    dns: {
                        record_name: 'example.com',
                        record_type: 'TXT',
                        resolver: 'quad9',
                        resolver_label: 'Quad9 (9.9.9.9)',
                        expected_values: ['v=spf1 -all', 'google-site'],
                        match_mode: 'exact',
                        last_answers: null,
                        last_resolved_at: null,
                    },
                },
            },
        });

        expect(
            (
                wrapper.get('select[name="resolver"]')
                    .element as HTMLSelectElement
            ).value,
        ).toBe('quad9');
        expect(
            (
                wrapper.get('textarea[name="expected_values"]')
                    .element as HTMLTextAreaElement
            ).value,
        ).toBe('v=spf1 -all\ngoogle-site');
    });

    it('keeps the type fixed when editing', () => {
        const wrapper = mount(MonitorForm, {
            props: { ...props, monitor: tcpMonitor, submitLabel: 'Save' },
        });

        expect(wrapper.find('input[name="type"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('TCP port');
        expect(
            (wrapper.get('input[name="host"]').element as HTMLInputElement)
                .value,
        ).toBe('db.example.com');
    });

    it('offers a request body only for POST, PUT and PATCH', async () => {
        const wrapper = mount(MonitorForm, { props });

        expect(wrapper.find('textarea[name="body"]').exists()).toBe(false);
        expect(wrapper.find('input[name="keyword"]').exists()).toBe(true);

        await wrapper.get('select[name="method"]').setValue('POST');

        expect(wrapper.find('textarea[name="body"]').exists()).toBe(true);
        expect(wrapper.find('select[name="body_content_type"]').exists()).toBe(
            true,
        );

        await wrapper.get('select[name="method"]').setValue('HEAD');

        expect(wrapper.find('textarea[name="body"]').exists()).toBe(false);
        expect(wrapper.find('input[name="keyword"]').exists()).toBe(false);
    });

    it('keeps the stored body and credentials when editing', async () => {
        const httpMonitor: MonitorDetail = {
            ...tcpMonitor,
            type: 'http',
            url: 'https://example.com/login',
            method: 'POST',
            expected_statuses: '200-299',
            host: null,
            port: null,
            body: { content_type: 'application/json', bytes: 42 },
            basic_auth_username: 'robot',
            keyword: 'ok',
            keyword_mode: 'contains',
            latency_threshold_ms: 800,
        };
        const wrapper = mount(MonitorForm, {
            props: { ...props, monitor: httpMonitor, submitLabel: 'Save' },
        });

        expect(
            (wrapper.get('input[name="body_mode"]').element as HTMLInputElement)
                .value,
        ).toBe('keep');
        expect(
            (wrapper.get('input[name="auth_mode"]').element as HTMLInputElement)
                .value,
        ).toBe('keep');
        expect(wrapper.find('textarea[name="body"]').exists()).toBe(false);
        expect(wrapper.find('input[name="basic_auth_password"]').exists()).toBe(
            false,
        );
        expect(wrapper.text()).toContain('robot');

        await wrapper.get('[data-test="replace-body"]').trigger('click');
        await wrapper.get('[data-test="replace-auth"]').trigger('click');

        expect(
            (wrapper.get('input[name="body_mode"]').element as HTMLInputElement)
                .value,
        ).toBe('replace');
        expect(wrapper.find('textarea[name="body"]').exists()).toBe(true);
        expect(wrapper.find('input[name="basic_auth_password"]').exists()).toBe(
            true,
        );
    });
});
