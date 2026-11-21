<?php

use App\Domain\Notifications\Rules\DiscordWebhookUrl;

test('genuine Discord webhook URLs are accepted', function (string $url) {
    expect(DiscordWebhookUrl::isValid($url))->toBeTrue();
})->with([
    'https://discord.com/api/webhooks/123456789012345678/AbCdEf_ghIJ-klmnop',
    'https://discordapp.com/api/webhooks/123456789012345678/AbCdEf_ghIJ-klmnop',
    'https://canary.discord.com/api/webhooks/123456789012345678/AbCdEf_ghIJ-klmnop',
]);

test('anything else is refused', function (mixed $url) {
    expect(DiscordWebhookUrl::isValid($url))->toBeFalse();
})->with([
    'plain http' => ['http://discord.com/api/webhooks/123456789012345678/AbCdEf_ghIJ-klmnop'],
    'other host' => ['https://example.com/api/webhooks/123456789012345678/AbCdEf_ghIJ-klmnop'],
    'lookalike host' => ['https://discord.com.evil.io/api/webhooks/123456789012345678/AbCdEf_ghIJ-klmnop'],
    'internal host' => ['https://169.254.169.254/api/webhooks/123456789012345678/AbCdEf_ghIJ-klmnop'],
    'credentials' => ['https://user:pass@discord.com/api/webhooks/123456789012345678/AbCdEf_ghIJ-klmnop'],
    'custom port' => ['https://discord.com:8443/api/webhooks/123456789012345678/AbCdEf_ghIJ-klmnop'],
    'wrong path' => ['https://discord.com/api/channels/123456789012345678/messages'],
    'traversal' => ['https://discord.com/api/webhooks/123456789012345678/../../users/@me'],
    'query string' => ['https://discord.com/api/webhooks/123456789012345678/AbCdEf_ghIJ-klmnop?wait=true'],
    'not a string' => [null],
]);
