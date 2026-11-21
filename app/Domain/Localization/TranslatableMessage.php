<?php

namespace App\Domain\Localization;

/**
 * For exceptions raised by framework-independent domain code: the message is
 * kept in English (the source language, safe for logs and stored results)
 * together with its template, and translated only where it is shown to a
 * person, such as validation errors (ADR-027).
 */
trait TranslatableMessage
{
    private string $messageTemplate = '';

    /**
     * @var array<string, string|int>
     */
    private array $messageReplacements = [];

    /**
     * @param  array<string, string|int>  $replace
     */
    protected static function fillTemplate(string $template, array $replace): string
    {
        $pairs = [];

        foreach ($replace as $key => $value) {
            $pairs[":{$key}"] = (string) $value;
        }

        return strtr($template, $pairs);
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    protected function rememberTemplate(string $template, array $replace): void
    {
        $this->messageTemplate = $template;
        $this->messageReplacements = $replace;
    }

    public function translatedMessage(?string $locale = null): string
    {
        return (string) __($this->messageTemplate, $this->messageReplacements, $locale);
    }
}
