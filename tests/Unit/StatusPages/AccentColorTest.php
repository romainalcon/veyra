<?php

use App\Domain\StatusPages\Support\AccentColor;

test('accent colours must use the #rrggbb format', function (string $value, bool $valid) {
    expect(AccentColor::isValid($value))->toBe($valid);
})->with([
    ['#4f46e5', true],
    ['#ABCDEF', true],
    ['4f46e5', false],
    ['#fff', false],
    ['#4f46e5;color:red', false],
    ['red', false],
]);

test('invalid colours are refused', function () {
    AccentColor::fromHex('javascript:alert(1)');
})->throws(InvalidArgumentException::class);

test('contrast ratios follow WCAG 2', function () {
    expect(AccentColor::contrast('#000000', '#ffffff'))->toEqualWithDelta(21.0, 0.01)
        ->and(AccentColor::contrast('#ffffff', '#ffffff'))->toEqualWithDelta(1.0, 0.01)
        ->and(AccentColor::contrast('#767676', '#ffffff'))->toEqualWithDelta(4.54, 0.01);
});

test('the foreground on the accent is black or white, whichever contrasts most', function (string $accent, string $foreground) {
    expect(AccentColor::fromHex($accent)->foreground())->toBe($foreground);
})->with([
    ['#4f46e5', '#ffffff'],
    ['#facc15', '#000000'],
    ['#000000', '#ffffff'],
    ['#ffffff', '#000000'],
]);

test('the accent is used as text only on backgrounds where it reaches 4.5:1', function () {
    $indigo = AccentColor::fromHex('#4F46E5')->toArray();
    $yellow = AccentColor::fromHex('#facc15')->toArray();

    expect($indigo)->toMatchArray(['accent' => '#4f46e5', 'text_light' => true, 'text_dark' => false])
        ->and($yellow)->toMatchArray(['text_light' => false, 'text_dark' => true]);
});
