<?php

namespace App\Domain\StatusPages\Enums;

/**
 * Branding images of a status page (ADR-036).
 */
enum AssetKind: string
{
    case Logo = 'logo';
    case Favicon = 'favicon';

    /**
     * Largest accepted file, in bytes.
     */
    public function maxBytes(): int
    {
        return match ($this) {
            self::Logo => (int) config('veyra.status_pages.logo_max_kb', 256) * 1024,
            self::Favicon => (int) config('veyra.status_pages.favicon_max_kb', 64) * 1024,
        };
    }

    /**
     * Largest accepted width or height, in pixels.
     */
    public function maxDimension(): int
    {
        return match ($this) {
            self::Logo => 2000,
            self::Favicon => 512,
        };
    }

    /**
     * Accepted media types and their public file extension. SVG is never
     * accepted: it can carry scripts.
     *
     * @return array<string, string>
     */
    public function mimeTypes(): array
    {
        return match ($this) {
            self::Logo => ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'],
            self::Favicon => ['image/png' => 'png', 'image/x-icon' => 'ico', 'image/webp' => 'webp'],
        };
    }
}
