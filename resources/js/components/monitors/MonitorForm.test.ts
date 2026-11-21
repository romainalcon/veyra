import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import MonitorForm from '@/components/monitors/MonitorForm.vue';
import type { MonitorDetail } from '@/types';

const props = {
    action: { action: '/o/acme/monitors', method: 'post' as const },
    channels: [],
    intervals: [{ value: 60, label: '1 minute' }],
    methods: ['GET' as const, 'HEAD' as const],
    types: [
        { value: 'http' as const, label: 'HTTP(S)' },
        { value: 'tcp' as const, label: 'TCP port' },
    ],
    tcpPorts: [22, 443],
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
    host: 'db.example.com',
    port: 22,
    notification_channel_ids: [],
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
});
