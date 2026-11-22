<?php

use App\Domain\StatusPages\Enums\PublicComponentStatus;
use App\Domain\StatusPages\Support\AccentColor;
use App\Domain\StatusPages\Support\BadgeRenderer;

test('badges are well-formed SVG with an accessible label', function () {
    $svg = BadgeRenderer::render('API', 'Operational', '#007e46', '#ffffff');
    $xml = simplexml_load_string($svg);

    expect($xml)->not->toBeFalse()
        ->and((string) $xml['aria-label'])->toBe('API: Operational')
        ->and((string) $xml->title)->toBe('API: Operational');
});

test('badge texts are escaped and bounded', function () {
    $svg = BadgeRenderer::render('<script>alert("x")</script>&'.str_repeat('a', 80), "line\nbreak", '#000000', '#ffffff');

    expect($svg)->not->toContain('<script>')
        ->toContain('&lt;script&gt;')
        ->not->toContain("\n")
        ->and(simplexml_load_string($svg))->not->toBeFalse()
        ->and(mb_strlen((string) simplexml_load_string($svg)->title))->toBeLessThan(90);
});

test('status colours keep their text readable', function (PublicComponentStatus $status) {
    [$background, $foreground] = BadgeRenderer::statusColors($status);

    expect(AccentColor::contrast($background, $foreground))->toBeGreaterThanOrEqual(4.5);
})->with(PublicComponentStatus::cases());

test('uptime colours follow the percentage, null meaning no data', function () {
    expect(BadgeRenderer::uptimeColors(null))->toBe(BadgeRenderer::statusColors(PublicComponentStatus::NoData))
        ->and(BadgeRenderer::uptimeColors(99.95))->toBe(BadgeRenderer::statusColors(PublicComponentStatus::Operational))
        ->and(BadgeRenderer::uptimeColors(97.0))->toBe(BadgeRenderer::statusColors(PublicComponentStatus::Degraded))
        ->and(BadgeRenderer::uptimeColors(80.0))->toBe(BadgeRenderer::statusColors(PublicComponentStatus::Outage));
});
