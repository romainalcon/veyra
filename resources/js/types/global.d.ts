import type { Directive } from 'vue';
import type { Auth } from '@/types/auth';
import type {
    CurrentOrganization,
    OrganizationSummary,
} from '@/types/organization';
import type { PublicNavItem } from '@/types/navigation';

// Extend ImportMeta interface for Vite...
declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        readonly VITE_REVERB_APP_KEY?: string;
        readonly VITE_REVERB_HOST?: string;
        readonly VITE_REVERB_PORT?: string;
        readonly VITE_REVERB_SCHEME?: string;
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(pattern: string) => Record<string, () => Promise<T>>;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            /** Interface language of the request (ADR-027). */
            locale: string;
            auth: Auth;
            organizations: OrganizationSummary[];
            currentOrganization: CurrentOrganization | null;
            system?: {
                version: string;
                sourceUrl: string | null;
                monitoring: { paused: boolean; reasons: string[] };
            };
            /** Public site links from optional extensions; absent on public status pages (ADR-032). */
            publicNavigation?: PublicNavItem[];
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}

declare module 'vue' {
    interface GlobalDirectives {
        vFocus: Directive<HTMLElement, boolean | undefined>;
    }

    interface ComponentCustomProperties {
        $inertia: typeof Router;
        $page: Page;
        $headManager: ReturnType<typeof createHeadManager>;
    }
}
