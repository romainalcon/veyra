<?php

namespace App\Domain\StatusPages\Support;

use App\Domain\StatusPages\Enums\PublicComponentStatus;

/**
 * Flat two-part SVG badges ("label | value"). Every text is escaped; colours
 * are the sRGB equivalents of the ADR-035 status tokens.
 */
final class BadgeRenderer
{
    private const LABEL_COLOR = '#353d4d';

    /**
     * Approximate advance of an 11 px sans-serif character.
     */
    private const CHAR_WIDTH = 6.8;

    private const PADDING = 6;

    /**
     * @return array{0: string, 1: string} background and text colours
     */
    public static function statusColors(PublicComponentStatus $status): array
    {
        return match ($status) {
            PublicComponentStatus::Operational => ['#007e46', '#ffffff'],
            PublicComponentStatus::Degraded => ['#cf7b00', '#231103'],
            PublicComponentStatus::Outage => ['#cc2827', '#ffffff'],
            PublicComponentStatus::Maintenance => ['#0573aa', '#ffffff'],
            PublicComponentStatus::NoData => ['#6a7281', '#ffffff'],
        };
    }

    /**
     * Colours for an uptime percentage (null: no data).
     *
     * @return array{0: string, 1: string}
     */
    public static function uptimeColors(?float $uptime): array
    {
        return match (true) {
            $uptime === null => self::statusColors(PublicComponentStatus::NoData),
            $uptime >= 99.0 => self::statusColors(PublicComponentStatus::Operational),
            $uptime >= 95.0 => self::statusColors(PublicComponentStatus::Degraded),
            default => self::statusColors(PublicComponentStatus::Outage),
        };
    }

    public static function render(string $label, string $value, string $background, string $foreground): string
    {
        $label = self::clean($label);
        $value = self::clean($value);
        $labelWidth = self::width($label);
        $valueWidth = self::width($value);
        $total = $labelWidth + $valueWidth;
        $title = self::escape("{$label}: {$value}");
        $labelX = $labelWidth / 2;
        $valueX = $labelWidth + $valueWidth / 2;
        $escapedLabel = self::escape($label);
        $escapedValue = self::escape($value);
        $labelColor = self::LABEL_COLOR;

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="{$total}" height="20" role="img" aria-label="{$title}"><title>{$title}</title><clipPath id="r"><rect width="{$total}" height="20" rx="3"/></clipPath><g clip-path="url(#r)"><rect width="{$labelWidth}" height="20" fill="{$labelColor}"/><rect x="{$labelWidth}" width="{$valueWidth}" height="20" fill="{$background}"/></g><g text-anchor="middle" font-family="Verdana,DejaVu Sans,sans-serif" font-size="11"><text x="{$labelX}" y="14" fill="#ffffff">{$escapedLabel}</text><text x="{$valueX}" y="14" fill="{$foreground}">{$escapedValue}</text></g></svg>
SVG;
    }

    private static function width(string $text): int
    {
        return (int) ceil(mb_strlen($text) * self::CHAR_WIDTH) + 2 * self::PADDING;
    }

    /**
     * Single line, bounded length: badge texts are short public labels.
     */
    private static function clean(string $text): string
    {
        $text = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text));

        return mb_strlen($text) > 40 ? mb_substr($text, 0, 39).'…' : $text;
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }
}
