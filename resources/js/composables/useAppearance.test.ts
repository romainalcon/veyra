import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';
import { initializeTheme, useAppearance } from '@/composables/useAppearance';

function mockPrefersDark(matches: boolean) {
    vi.stubGlobal(
        'matchMedia',
        vi.fn().mockImplementation(() => ({
            matches,
            addEventListener: vi.fn(),
            removeEventListener: vi.fn(),
        })),
    );
    window.matchMedia = globalThis.matchMedia;
}

function mountComposable() {
    let api!: ReturnType<typeof useAppearance>;

    mount(
        defineComponent({
            setup() {
                api = useAppearance();

                return () => null;
            },
        }),
    );

    return api;
}

describe('useAppearance', () => {
    beforeEach(() => {
        localStorage.clear();
        document.cookie = 'appearance=; max-age=0; path=/';
        document.documentElement.classList.remove('dark');
        mockPrefersDark(false);
    });

    it('persists the chosen appearance and applies the dark class', () => {
        const { updateAppearance, resolvedAppearance } = mountComposable();

        updateAppearance('dark');

        expect(localStorage.getItem('appearance')).toBe('dark');
        expect(document.cookie).toContain('appearance=dark');
        expect(document.documentElement.classList.contains('dark')).toBe(true);
        expect(resolvedAppearance.value).toBe('dark');

        updateAppearance('light');

        expect(document.documentElement.classList.contains('dark')).toBe(false);
    });

    it('follows the operating system in system mode', () => {
        mockPrefersDark(true);
        const { updateAppearance, resolvedAppearance } = mountComposable();

        updateAppearance('system');

        expect(resolvedAppearance.value).toBe('dark');
        expect(document.documentElement.classList.contains('dark')).toBe(true);
    });

    it('restores the saved appearance on initialization', () => {
        localStorage.setItem('appearance', 'dark');

        initializeTheme();

        expect(document.documentElement.classList.contains('dark')).toBe(true);
    });
});
