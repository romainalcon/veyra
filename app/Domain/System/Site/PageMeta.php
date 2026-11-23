<?php

namespace App\Domain\System\Site;

/**
 * Server-rendered metadata of the public site pages, for search engines and
 * link previews (Open Graph, X/Twitter cards): crawlers do not run the Vue
 * application. Rendered by `resources/views/app.blade.php`.
 */
class PageMeta
{
    /**
     * @param  string  $title  translated title, without the application name
     * @param  string  $description  translated description
     * @return array{title: string, description: string, url: string, image: string, image_alt: string, locale: string}
     */
    public static function make(string $title, string $description, string $url): array
    {
        $locale = app()->getLocale() === 'fr' ? 'fr' : 'en';

        return [
            'title' => $title.' - Veyra',
            'description' => $description,
            'url' => $url,
            'image' => asset("images/og-image-{$locale}.png"),
            'image_alt' => __('Veyra: know your services are down before your users do.'),
            'locale' => $locale === 'fr' ? 'fr_FR' : 'en_US',
        ];
    }
}
