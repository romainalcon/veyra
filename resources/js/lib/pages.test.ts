import { describe, expect, it, vi } from 'vitest';
import { buildPageRegistry } from '@/lib/pages';

const loader = (label: string) => () => Promise.resolve(label);

describe('buildPageRegistry', () => {
    it('names core pages after their path below pages/', () => {
        const registry = buildPageRegistry(
            { './pages/monitors/Index.vue': loader('core') },
            {},
        );

        expect([...registry.keys()]).toEqual(['monitors/Index']);
    });

    it('adds extension pages under their own path', async () => {
        const registry = buildPageRegistry(
            { './pages/monitors/Index.vue': loader('core') },
            {
                '../../extensions/cloud/resources/js/pages/cloud/billing/Show.vue':
                    loader('cloud'),
            },
        );

        await expect(registry.get('cloud/billing/Show')?.()).resolves.toBe(
            'cloud',
        );
    });

    it('never lets an extension replace a core page', async () => {
        const warn = vi.fn();
        const registry = buildPageRegistry(
            { './pages/monitors/Index.vue': loader('core') },
            {
                '../../extensions/evil/resources/js/pages/monitors/Index.vue':
                    loader('extension'),
            },
            warn,
        );

        await expect(registry.get('monitors/Index')?.()).resolves.toBe('core');
        expect(warn).toHaveBeenCalledOnce();
    });

    it('is the core registry when no extension is installed', () => {
        const registry = buildPageRegistry(
            { './pages/Welcome.vue': loader('core') },
            {},
        );

        expect(registry.size).toBe(1);
    });
});
