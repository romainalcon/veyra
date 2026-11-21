<?php

namespace App\Domain\Localization;

use Illuminate\Http\Request;

/**
 * The interface languages Veyra ships translations for (ADR-027).
 * English is the source language: its strings are the translation keys.
 */
final class SupportedLocales
{
    public const FALLBACK = 'en';

    /**
     * @return array<string, string> Locale code => native language name.
     */
    public static function all(): array
    {
        /** @var array<string, string> $locales */
        $locales = config('veyra.locales', [self::FALLBACK => 'English']);

        return $locales;
    }

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_keys(self::all());
    }

    public static function isSupported(?string $locale): bool
    {
        return $locale !== null && array_key_exists($locale, self::all());
    }

    /**
     * The instance default: `APP_LOCALE` when supported, English otherwise.
     */
    public static function default(): string
    {
        $locale = config('app.locale');

        return is_string($locale) && self::isSupported($locale) ? $locale : self::FALLBACK;
    }

    /**
     * A supported locale for the given value, or the instance default.
     */
    public static function resolve(?string $locale): string
    {
        return self::isSupported($locale) ? (string) $locale : self::default();
    }

    /**
     * The first supported language of the Accept-Language header (matching
     * on the primary subtag, so `fr-CA` selects `fr`), or the instance default.
     */
    public static function negotiate(Request $request): string
    {
        foreach ($request->getLanguages() as $language) {
            $primary = strtolower(explode('_', str_replace('-', '_', $language))[0]);

            if (self::isSupported($primary)) {
                return $primary;
            }
        }

        return self::default();
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (string $code, string $name): array => ['code' => $code, 'name' => $name],
            array_keys(self::all()),
            array_values(self::all()),
        );
    }
}
