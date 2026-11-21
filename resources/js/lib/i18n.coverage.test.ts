import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import french from '../../../lang/fr.json';

/*
 * Every literal passed to t() / tc() must have a French translation, so that
 * a new English string cannot ship untranslated unnoticed.
 */
const root = join(__dirname, '..');

function sources(directory: string): string[] {
    return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
        const path = join(directory, entry.name);

        if (entry.isDirectory()) {
            return sources(path);
        }

        return /\.(vue|ts)$/.test(entry.name) &&
            !entry.name.endsWith('.test.ts') &&
            entry.name !== 'i18n.ts'
            ? [path]
            : [];
    });
}

function keys(source: string): string[] {
    const pattern = /\btc?\(\s*(['"])((?:\\.|(?!\1).)*)\1/gs;

    return [...source.matchAll(pattern)].map((match) =>
        match[2].replace(/\\(['"\\])/g, '$1'),
    );
}

describe('French dictionary', () => {
    it('translates every string used by the interface', () => {
        const dictionary = french as Record<string, string>;
        const missing = sources(root).flatMap((path) =>
            keys(readFileSync(path, 'utf8'))
                .filter((key) => !(key in dictionary))
                .map((key) => `${path.replace(root, '')}: ${key}`),
        );

        expect(missing).toEqual([]);
    });

    it('keeps every placeholder of the English source', () => {
        // Units glued to a placeholder (":daysd") cannot be parsed reliably.
        const glued = new Set([':daysd :hoursh']);
        const mismatched = Object.entries(french as Record<string, string>)
            .filter(([source, translation]) => {
                if (glued.has(source)) {
                    return false;
                }

                const placeholders = (text: string) =>
                    [...text.matchAll(/:([a-z_]+)/gi)]
                        .map((match) => match[1].toLowerCase())
                        .sort()
                        .join(',');

                return (
                    placeholders(source.replace(/\|/g, ' ')) !==
                    placeholders(translation.replace(/\|/g, ' '))
                );
            })
            .map(([source]) => source);

        expect(mismatched).toEqual([]);
    });
});
