<?php

namespace Tests\Support;

/**
 * Tiny, valid image files for branding tests.
 */
final class TestImages
{
    public static function png(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
    }

    public static function webp(): string
    {
        return (string) base64_decode('UklGRiQAAABXRUJQVlA4IBgAAAAwAQCdASoBAAEAAwA0JaQAA3AA/vuUAAA=');
    }

    /**
     * An ICO container holding the 1x1 PNG.
     */
    public static function ico(): string
    {
        $png = self::png();

        return pack('vvv', 0, 1, 1).pack('CCCCvvVV', 1, 1, 0, 0, 1, 32, strlen($png), 22).$png;
    }

    public static function svg(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(1)</script></svg>';
    }

    /**
     * A PNG signature followed by HTML: a polyglot that is not an image.
     */
    public static function fakePng(): string
    {
        return "\x89PNG\r\n\x1A\n<html><script>alert(1)</script></html>";
    }
}
