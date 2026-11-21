<?php

use App\Domain\Monitoring\Enums\ProbeErrorCode;
use App\Domain\Monitoring\Probes\DestinationRejected;
use App\Domain\Monitoring\Probes\UrlPolicy;

function urlPolicy(): UrlPolicy
{
    return new UrlPolicy([80, 443, 8080, 8443]);
}

test('valid URLs are parsed', function () {
    $parsed = urlPolicy()->parse('HTTPS://Example.COM/health?x=1');

    expect($parsed->scheme)->toBe('https')
        ->and($parsed->host)->toBe('example.com')
        ->and($parsed->port)->toBe(443)
        ->and($parsed->hostIsIp)->toBeFalse();

    expect(urlPolicy()->parse('http://example.com:8080')->port)->toBe(8080)
        ->and(urlPolicy()->parse('http://[2606:4700::1111]/')->host)->toBe('2606:4700::1111');
});

test('unsafe or malformed URLs are rejected', function (string $url, ProbeErrorCode $code) {
    try {
        urlPolicy()->parse($url);
        $this->fail('Expected rejection');
    } catch (DestinationRejected $exception) {
        expect($exception->errorCode)->toBe($code);
    }
})->with([
    'ftp' => ['ftp://example.com', ProbeErrorCode::InvalidUrl],
    'file' => ['file:///etc/passwd', ProbeErrorCode::InvalidUrl],
    'gopher' => ['gopher://example.com', ProbeErrorCode::InvalidUrl],
    'relative' => ['/health', ProbeErrorCode::InvalidUrl],
    'credentials' => ['https://user:secret@example.com', ProbeErrorCode::InvalidUrl],
    'whitespace' => ['https://exa mple.com', ProbeErrorCode::InvalidUrl],
    'bad host' => ['https://-bad-.com', ProbeErrorCode::InvalidUrl],
    'ssh port' => ['http://example.com:22', ProbeErrorCode::ForbiddenDestination],
    'redis port' => ['http://example.com:6379', ProbeErrorCode::ForbiddenDestination],
    'too long' => ['https://example.com/'.str_repeat('a', 2050), ProbeErrorCode::InvalidUrl],
    'decimal host' => ['http://2130706433/', ProbeErrorCode::InvalidUrl],
    'hex host' => ['http://0x7f.1/', ProbeErrorCode::InvalidUrl],
    'localhost' => ['http://localhost/', ProbeErrorCode::ForbiddenDestination],
    'localhost subdomain' => ['http://api.localhost/', ProbeErrorCode::ForbiddenDestination],
    'mdns' => ['http://nas.local/', ProbeErrorCode::ForbiddenDestination],
]);

test('private host name suffixes are accepted only with the private-network opt-in', function () {
    expect((new UrlPolicy([80], allowPrivateHostnames: true))->parse('http://nas.local/')->host)->toBe('nas.local');

    expect(fn () => (new UrlPolicy([80], allowPrivateHostnames: true))->parse('http://localhost/'))
        ->toThrow(DestinationRejected::class);
});
