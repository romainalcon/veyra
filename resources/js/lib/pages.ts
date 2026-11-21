/**
 * Page registry (ADR-030). Core pages live in `resources/js/pages`; an
 * optional extension cloned into `extensions/<name>/` adds its own under
 * `extensions/<name>/resources/js/pages`. Page names are the path below the
 * `pages` directory without extension (`monitors/Index`, `cloud/billing/Show`).
 * A core page always wins over an extension page with the same name.
 */
export type PageLoader<T = unknown> = () => Promise<T>;

const CORE_PREFIX = './pages/';
const EXTENSION_PATTERN =
    /\/extensions\/[^/]+\/resources\/js\/pages\/(.+)\.vue$/;

export function pageNameFromCorePath(path: string): string | null {
    return path.startsWith(CORE_PREFIX) && path.endsWith('.vue')
        ? path.slice(CORE_PREFIX.length, -'.vue'.length)
        : null;
}

export function pageNameFromExtensionPath(path: string): string | null {
    return EXTENSION_PATTERN.exec(path)?.[1] ?? null;
}

export function buildPageRegistry<T>(
    corePages: Record<string, PageLoader<T>>,
    extensionPages: Record<string, PageLoader<T>>,
    warn: (message: string) => void = console.warn,
): Map<string, PageLoader<T>> {
    const registry = new Map<string, PageLoader<T>>();

    for (const [path, loader] of Object.entries(corePages)) {
        const name = pageNameFromCorePath(path);

        if (name !== null) {
            registry.set(name, loader);
        }
    }

    for (const [path, loader] of Object.entries(extensionPages)) {
        const name = pageNameFromExtensionPath(path);

        if (name === null) {
            continue;
        }

        if (registry.has(name)) {
            warn(`Extension page "${path}" ignored: "${name}" already exists.`);

            continue;
        }

        registry.set(name, loader);
    }

    return registry;
}
