import { createInertiaApp, router } from '@inertiajs/vue3';
import type { DefineComponent } from 'vue';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import PublicLayout from '@/layouts/PublicLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';
import { setLocale } from '@/lib/i18n';
import { buildPageRegistry } from '@/lib/pages';
import { initializeRealtime } from '@/lib/realtime';

const appName = import.meta.env.VITE_APP_NAME || 'Veyra';

/*
 * Core pages plus the pages of optional extensions cloned into
 * `extensions/<name>/` (ADR-030); without extensions the second glob is empty.
 */
const pages = buildPageRegistry<DefineComponent>(
    import.meta.glob<DefineComponent>('./pages/**/*.vue', {
        import: 'default',
    }),
    import.meta.glob<DefineComponent>(
        '../../extensions/*/resources/js/pages/**/*.vue',
        { import: 'default' },
    ),
);

/*
 * The server renders `<html lang>` in the language of the request (the
 * organization's, or the browser's outside one). Apply it before mounting,
 * then follow every visit before the new page renders.
 */
setLocale(document.documentElement.lang);

router.on('beforeUpdate', (event) => {
    setLocale(event.detail.page.props.locale);
});

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: async (name) => {
        const page = pages.get(name);

        if (!page) {
            throw new Error(`Page not found: ${name}`);
        }

        return page();
    },
    layout: (name) => {
        switch (true) {
            case name === 'Welcome':
                return null;
            case name.startsWith('auth/'):
            case name.startsWith('invitations/'):
                return AuthLayout;
            case name.startsWith('status/'):
                return PublicLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    withApp: (app) => {
        app.directive('focus', {
            mounted: (el: HTMLElement, shouldFocus) => {
                if (shouldFocus.value !== false) {
                    el.focus();
                }
            },
        });
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();

// Optional Reverb connection (HTTP remains authoritative)...
initializeRealtime();
