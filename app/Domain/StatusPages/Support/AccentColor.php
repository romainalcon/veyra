<?php

namespace App\Domain\StatusPages\Support;

use InvalidArgumentException;

/**
 * A status page accent colour (#rrggbb) and its WCAG contrast decisions.
 *
 * The accent fills buttons and bars with an automatically chosen foreground
 * (black or white, whichever contrasts most). It is used as a text colour on
 * the page background only where it reaches 4.5:1 there; otherwise the
 * neutral theme colour is kept (ADR-036).
 */
final readonly class AccentColor
{
    public const TEXT_CONTRAST = 4.5;

    /**
     * sRGB equivalents of the public page backgrounds (resources/css/app.css).
     */
    public const LIGHT_BACKGROUND = '#f9fafc';

    public const DARK_BACKGROUND = '#0b0f18';

    private function __construct(public string $hex) {}

    public static function isValid(string $value): bool
    {
        return preg_match('/^#[0-9a-f]{6}$/i', $value) === 1;
    }

    public static function fromHex(string $value): self
    {
        if (! self::isValid($value)) {
            throw new InvalidArgumentException('Accent colours use the #rrggbb format.');
        }

        return new self(strtolower($value));
    }

    /**
     * Black or white, whichever contrasts most with the accent.
     */
    public function foreground(): string
    {
        return self::contrast($this->hex, '#000000') >= self::contrast($this->hex, '#ffffff') ? '#000000' : '#ffffff';
    }

    public function isReadableOn(string $background): bool
    {
        return self::contrast($this->hex, $background) >= self::TEXT_CONTRAST;
    }

    /**
     * @return array{accent: string, accent_foreground: string, text_light: bool, text_dark: bool}
     */
    public function toArray(): array
    {
        return [
            'accent' => $this->hex,
            'accent_foreground' => $this->foreground(),
            'text_light' => $this->isReadableOn(self::LIGHT_BACKGROUND),
            'text_dark' => $this->isReadableOn(self::DARK_BACKGROUND),
        ];
    }

    /**
     * WCAG 2.x contrast ratio between two #rrggbb colours (1 to 21).
     */
    public static function contrast(string $first, string $second): float
    {
        $a = self::luminance($first);
        $b = self::luminance($second);

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    private static function luminance(string $hex): float
    {
        $channels = array_map(
            function (string $pair): float {
                $value = hexdec($pair) / 255;

                return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
            },
            str_split(substr($hex, 1), 2),
        );

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
