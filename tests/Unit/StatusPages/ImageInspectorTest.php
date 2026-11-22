<?php

use App\Domain\StatusPages\Enums\AssetKind;
use App\Domain\StatusPages\Support\ImageInspector;
use Tests\Support\TestImages;

test('accepted raster images are identified from their bytes', function (string $bytes, AssetKind $kind, string $mime) {
    expect(ImageInspector::inspect($bytes, $kind))->toBe(['mime' => $mime, 'width' => 1, 'height' => 1]);
})->with([
    'png logo' => [fn () => TestImages::png(), AssetKind::Logo, 'image/png'],
    'webp logo' => [fn () => TestImages::webp(), AssetKind::Logo, 'image/webp'],
    'ico favicon' => [fn () => TestImages::ico(), AssetKind::Favicon, 'image/x-icon'],
    'png favicon' => [fn () => TestImages::png(), AssetKind::Favicon, 'image/png'],
]);

test('SVG, polyglots, unknown bytes and formats of the other kind are refused', function (string $bytes, AssetKind $kind) {
    expect(ImageInspector::inspect($bytes, $kind))->toBeNull();
})->with([
    'svg' => [fn () => TestImages::svg(), AssetKind::Logo],
    'fake png' => [fn () => TestImages::fakePng(), AssetKind::Logo],
    'text' => ['hello', AssetKind::Favicon],
    'empty' => ['', AssetKind::Logo],
    'ico as logo' => [fn () => TestImages::ico(), AssetKind::Logo],
]);
