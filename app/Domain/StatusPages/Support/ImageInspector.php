<?php

namespace App\Domain\StatusPages\Support;

use App\Domain\StatusPages\Enums\AssetKind;

/**
 * Identifies an uploaded branding image from its bytes, never from the
 * client-provided name or type: the magic number and getimagesizefromstring()
 * must agree on an accepted, well-formed raster format (no SVG, no GD
 * dependency).
 */
final class ImageInspector
{
    /**
     * @return array{mime: string, width: int, height: int}|null
     */
    public static function inspect(string $bytes, AssetKind $kind): ?array
    {
        $mime = self::sniff($bytes);

        if ($mime === null || ! array_key_exists($mime, $kind->mimeTypes()) || ! self::isWellFormed($bytes, $mime)) {
            return null;
        }

        $info = @getimagesizefromstring($bytes);

        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            return null;
        }

        $detected = $info['mime'] === 'image/vnd.microsoft.icon' ? 'image/x-icon' : $info['mime'];

        if ($detected !== $mime) {
            return null;
        }

        return ['mime' => $mime, 'width' => (int) $info[0], 'height' => (int) $info[1]];
    }

    /**
     * Minimal structure checks, so that a signature followed by arbitrary
     * content is not taken for an image.
     */
    private static function isWellFormed(string $bytes, string $mime): bool
    {
        return match ($mime) {
            'image/png' => substr($bytes, 12, 4) === 'IHDR' && substr($bytes, -8, 4) === 'IEND',
            'image/jpeg' => str_ends_with($bytes, "\xFF\xD9"),
            'image/webp' => strlen($bytes) >= 12 && self::uint32($bytes, 4) + 8 === strlen($bytes),
            'image/x-icon' => strlen($bytes) >= 22 && self::uint16($bytes, 4) >= 1,
            default => false,
        };
    }

    /**
     * Little-endian unsigned integers at an offset (callers check the length).
     */
    private static function uint32(string $bytes, int $offset): int
    {
        $bytes = array_map('ord', str_split(substr($bytes, $offset, 4)));

        return $bytes[0] | ($bytes[1] << 8) | ($bytes[2] << 16) | ($bytes[3] << 24);
    }

    private static function uint16(string $bytes, int $offset): int
    {
        return ord($bytes[$offset]) | (ord($bytes[$offset + 1]) << 8);
    }

    private static function sniff(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, "\x89PNG\r\n\x1A\n") => 'image/png',
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => 'image/webp',
            str_starts_with($bytes, "\x00\x00\x01\x00") => 'image/x-icon',
            default => null,
        };
    }
}
