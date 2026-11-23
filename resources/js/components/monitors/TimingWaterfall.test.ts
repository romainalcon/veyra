import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import TimingWaterfall from '@/components/monitors/TimingWaterfall.vue';
import { timingSegments } from '@/lib/timings';
import type { CheckTimings } from '@/types';

const timings = (overrides: Partial<CheckTimings> = {}): CheckTimings => ({
    dns: 12,
    connect: 20,
    tls: 40,
    ttfb: 110,
    transfer: 8,
    redirect: 0,
    ...overrides,
});

describe('timingSegments', () => {
    it('keeps measured phases in order and hides empty redirects', () => {
        expect(timingSegments(timings()).map((s) => s.phase)).toEqual([
            'dns',
            'connect',
            'tls',
            'ttfb',
            'transfer',
        ]);
        expect(
            timingSegments(timings({ redirect: 30, tls: null })).map(
                (s) => s.phase,
            ),
        ).toEqual(['redirect', 'dns', 'connect', 'ttfb', 'transfer']);
        expect(timingSegments(null)).toEqual([]);
    });
});

describe('TimingWaterfall', () => {
    it('lists every phase with its duration', () => {
        const wrapper = mount(TimingWaterfall, {
            props: { timings: timings() },
        });

        expect(wrapper.find('[data-test="timing-ttfb"]').text()).toContain(
            '110 ms',
        );
        expect(wrapper.findAll('[data-test^="timing-"]')).toHaveLength(5);
    });

    it('shows a compact bar with an accessible summary', () => {
        const wrapper = mount(TimingWaterfall, {
            props: { timings: timings(), compact: true },
        });

        const bar = wrapper.find('[data-test="timings-compact"]');
        expect(bar.attributes('role')).toBe('img');
        expect(bar.attributes('aria-label')).toContain('DNS 12 ms');
    });

    it('explains when a check has no breakdown', () => {
        const wrapper = mount(TimingWaterfall, {
            props: {
                timings: {
                    dns: null,
                    connect: null,
                    tls: null,
                    ttfb: null,
                    transfer: null,
                    redirect: null,
                },
            },
        });

        expect(wrapper.find('[data-test="timings-empty"]').exists()).toBe(true);
    });
});
