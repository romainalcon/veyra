import { computed, ref, shallowRef } from 'vue';

/**
 * Interface translations (ADR-027). The English source strings are the keys,
 * as in Laravel's JSON translation files: `lang/fr.json` is shared by PHP and
 * Vue, and English needs no dictionary. Dictionaries are bundled so that a
 * language switch (moving to another organization) never shows a partially
 * translated page; move to lazy loading if many languages are added.
 */
export type Replacements = Record<string, string | number | null | undefined>;

type Dictionary = Record<string, string>;

export const SOURCE_LOCALE = 'en';

/**
 * Merges dictionaries by language code (`…/fr.json`). Core entries win over
 * those of extensions (`extensions/<name>/lang/*.json`, ADR-030).
 */
export function mergeDictionaries(
    core: Record<string, Dictionary>,
    extensions: Record<string, Dictionary> = {},
): Map<string, Dictionary> {
    const merged = new Map<string, Dictionary>([[SOURCE_LOCALE, {}]]);

    for (const [path, dictionary] of [
        ...Object.entries(extensions),
        ...Object.entries(core),
    ]) {
        const code = /([a-z]+)\.json$/.exec(path)?.[1];

        if (code) {
            merged.set(code, { ...(merged.get(code) ?? {}), ...dictionary });
        }
    }

    return merged;
}

const dictionaries = mergeDictionaries(
    import.meta.glob<Dictionary>('../../../lang/*.json', {
        import: 'default',
        eager: true,
    }),
    import.meta.glob<Dictionary>('../../../extensions/*/lang/*.json', {
        import: 'default',
        eager: true,
    }),
);

const activeLocale = ref(SOURCE_LOCALE);
const activeDictionary = shallowRef<Dictionary>({});

/**
 * Switches the interface language. Regional variants use their primary
 * language (`fr-CA` → `fr`); unknown locales fall back to English rather
 * than showing translation keys.
 */
export function setLocale(locale: string | null | undefined): void {
    const requested = (locale ?? SOURCE_LOCALE).toLowerCase().split(/[-_]/)[0];
    const resolved = dictionaries.has(requested) ? requested : SOURCE_LOCALE;

    activeDictionary.value = dictionaries.get(resolved) ?? {};
    activeLocale.value = resolved;

    if (typeof document !== 'undefined') {
        document.documentElement.lang = resolved;
    }
}

export function currentLocale(): string {
    return activeLocale.value;
}

/**
 * Laravel-compatible replacements in a single pass (`:name`, `:Name` and
 * `:NAME`), so that a replaced value is never itself re-interpreted.
 */
function replace(line: string, replacements: Replacements): string {
    const keys = Object.keys(replacements).sort((a, b) => b.length - a.length);

    if (keys.length === 0) {
        return line;
    }

    const pattern = new RegExp(`:(${keys.join('|')})`, 'gi');

    return line.replace(pattern, (match: string, name: string) => {
        const key = keys.find(
            (candidate) => candidate.toLowerCase() === name.toLowerCase(),
        );

        if (key === undefined) {
            return match;
        }

        const value = String(replacements[key] ?? '');

        if (name === key.toUpperCase() && name !== key) {
            return value.toUpperCase();
        }

        if (name.charAt(0) !== key.charAt(0)) {
            return value.charAt(0).toUpperCase() + value.slice(1);
        }

        return value;
    });
}

/**
 * Translates a source string: `t('Down for :duration', { duration })`.
 */
export function t(key: string, replacements: Replacements = {}): string {
    return replace(activeDictionary.value[key] ?? key, replacements);
}

/**
 * Translates a "singular|plural" source string for `count`, which is also
 * available as the `:count` replacement.
 */
export function tc(
    key: string,
    count: number,
    replacements: Replacements = {},
): string {
    const forms = (activeDictionary.value[key] ?? key).split('|');
    // French and English both use the singular for 1 (French also for 0).
    const singular =
        count === 1 || (activeLocale.value === 'fr' && count === 0);
    const form = forms.length > 1 && !singular ? forms[1] : forms[0];

    return replace(form, { count, ...replacements });
}

export function useTranslations() {
    return { t, tc, locale: computed(() => activeLocale.value) };
}
