<?php

use App\Domain\Monitoring\DTOs\ExpectedStatuses;
use App\Domain\Monitoring\Enums\HttpMethod;
use App\Domain\Monitoring\Enums\ProbeErrorCode;
use App\Domain\Monitoring\Probes\Destination;
use App\Domain\Monitoring\Probes\DestinationGuard;
use App\Domain\Monitoring\Probes\HttpProbe;
use App\Domain\Monitoring\Probes\ParsedUrl;
use App\Domain\Monitoring\Probes\ProbeSettings;
use Illuminate\Http\Client\Factory;
use Symfony\Component\Process\Process;

/*
 * Exercises the real Guzzle/cURL stack (Http::fake bypasses it): the options
 * built by HttpProbe must be accepted by the handler, and CURLOPT_RESOLVE
 * must route a host name to the validated address.
 *
 * The local server listens on loopback, which the SSRF policy always blocks,
 * so the guard is replaced by one that pins a fake public host name to it.
 */
beforeEach(function () {
    $this->port = 18000 + random_int(0, 999);
    $this->server = new Process([PHP_BINARY, '-S', "127.0.0.1:{$this->port}", __DIR__.'/fixtures/probe-server.php']);
    $this->server->start();

    $deadline = microtime(true) + 5;
    while (microtime(true) < $deadline && @fsockopen('127.0.0.1', $this->port) === false) {
        usleep(50_000);
    }

    $port = $this->port;
    $settings = new ProbeSettings(false, [80, 443, $port], 3, 'Veyra-Test');

    $guard = Mockery::mock(DestinationGuard::class);
    $guard->shouldReceive('inspect')->andReturnUsing(function (string $url) use ($port): Destination {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return new Destination(
            new ParsedUrl("http://pinned.example.com:{$port}{$path}", 'http', 'pinned.example.com', $port, false),
            '127.0.0.1',
        );
    });

    $this->probe = new HttpProbe(new Factory, $guard, $settings);
});

afterEach(fn () => $this->server->stop());

test('real requests are pinned to the validated address', function () {
    $result = $this->probe->probe("http://pinned.example.com:{$this->port}/ok", HttpMethod::Get, [], ExpectedStatuses::parse('204'), true, 5);

    expect($result->errorCode)->toBeNull()
        ->and($result->success)->toBeTrue()
        ->and($result->statusCode)->toBe(204)
        ->and($result->metadata['resolved_ip'])->toBe('127.0.0.1');
});

test('real redirects are followed through the guard', function () {
    $result = $this->probe->probe("http://pinned.example.com:{$this->port}/redirect", HttpMethod::Get, [], ExpectedStatuses::parse('204'), true, 5);

    expect($result->success)->toBeTrue()
        ->and($result->metadata['redirects'])->toBe(1);
});

test('oversized responses are aborted', function () {
    $result = $this->probe->probe("http://pinned.example.com:{$this->port}/large", HttpMethod::Get, [], ExpectedStatuses::default(), true, 5);

    expect($result->errorCode)->toBe(ProbeErrorCode::ResponseTooLarge);
});

test('HEAD requests work on the real handler', function () {
    $result = $this->probe->probe("http://pinned.example.com:{$this->port}/ok", HttpMethod::Head, [], ExpectedStatuses::parse('204'), true, 5);

    expect($result->success)->toBeTrue();
});

test('real requests report the phases of the pinned connection', function () {
    $result = $this->probe->probe("http://pinned.example.com:{$this->port}/redirect", HttpMethod::Get, [], ExpectedStatuses::parse('204'), true, 5);

    expect($result->success)->toBeTrue()
        ->and($result->timings?->connectMs)->toBeInt()
        ->and($result->timings?->ttfbMs)->toBeInt()
        ->and($result->timings?->transferMs)->toBeInt()
        ->and($result->timings?->redirectMs)->toBeInt()
        ->and($result->timings?->tlsMs)->toBeNull()
        ->and($result->timings?->dnsMs)->toBeInt();
});

test('real failing responses carry allow-listed evidence', function () {
    $result = $this->probe->probe("http://pinned.example.com:{$this->port}/error", HttpMethod::Get, [], ExpectedStatuses::parse('200'), true, 5);

    expect($result->errorCode)->toBe(ProbeErrorCode::UnexpectedStatus)
        ->and($result->evidence?->statusCode)->toBe(503)
        ->and($result->evidence?->bodyExcerpt)->toBe('{"error":"database unavailable"}')
        ->and($result->evidence?->headers)->toHaveKey('content-type')
        ->and($result->evidence?->headers)->not->toHaveKey('set-cookie')
        ->and($result->evidence?->headers)->not->toHaveKey('x-seen-host');
});
