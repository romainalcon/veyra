import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import UptimeBars from '@/components/charts/UptimeBars.vue';

describe('UptimeBars', () => {
    it('renders one bar per day with a distinct no-data tone', () => {
        const wrapper = mount(UptimeBars, {
            props: {
                days: [
                    {
                        day: '2026-11-20',
                        uptime: null,
                        coverage: null,
                        checks: 0,
                    },
                    {
                        day: '2026-11-21',
                        uptime: 100,
                        coverage: 1,
                        checks: 1440,
                    },
                    { day: '2026-11-22', uptime: 95, coverage: 1, checks: 720 },
                ],
            },
        });

        const bars = wrapper.findAll('[data-tone]');
        expect(bars.map((bar) => bar.attributes('data-tone'))).toEqual([
            'none',
            'good',
            'bad',
        ]);
        expect(bars[0].attributes('title')).toBe('2026-11-20: No data');
    });
});
