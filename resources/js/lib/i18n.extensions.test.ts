import { describe, expect, it } from 'vitest';
import { mergeDictionaries } from '@/lib/i18n';

describe('mergeDictionaries', () => {
    it('adds extension translations to the core dictionary of the same language', () => {
        const merged = mergeDictionaries(
            { '../../../lang/fr.json': { Monitors: 'Moniteurs' } },
            {
                '../../../extensions/cloud/lang/fr.json': {
                    Billing: 'Facturation',
                },
            },
        );

        expect(merged.get('fr')).toEqual({
            Monitors: 'Moniteurs',
            Billing: 'Facturation',
        });
        expect(merged.get('en')).toEqual({});
    });

    it('keeps core translations when an extension redefines a key', () => {
        const merged = mergeDictionaries(
            { '../../../lang/fr.json': { Settings: 'Réglages' } },
            {
                '../../../extensions/cloud/lang/fr.json': {
                    Settings: 'Paramètres',
                },
            },
        );

        expect(merged.get('fr')?.Settings).toBe('Réglages');
    });
});
