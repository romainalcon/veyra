<?php

use App\Domain\Monitoring\DTOs\ExpectedStatuses;
use App\Domain\Monitoring\Enums\HttpMethod;
use App\Domain\Monitoring\Enums\ProbeErrorCode;
use App\Domain\Monitoring\Probes\DestinationGuard;
use App\Domain\Monitoring\Probes\HttpProbe;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

function probe(string $url, array $headers = [], string $expected = '200-299', bool $followRedirects = true, HttpMethod $method = HttpMethod::Get)
{
    return app(HttpProbe::class)->probe($url, $method, $headers, ExpectedStatuses::parse($expected), $followRedirects, 10);
}

test('a reachable endpoint with an expected status succeeds', function () {
    Http::fake(['https://example.com/*' => Http::response('', 204)]);

    $result = probe('https://example.com/health');

    expect($result->success)->toBeTrue()
        ->and($result->statusCode)->toBe(204)
        ->and($result->errorCode)->toBeNull()
        ->and($result->durationMs)->toBeGreaterThanOrEqual(0)
        ->and($result->metadata)->toMatchArray(['redirects' => 0, 'resolved_ip' => '93.184.215.14', 'final_host' => 'example.com']);
});

test('an unexpected status is a failed check', function () {
    Http::fake(['*' => Http::response('', 503)]);

    $result = probe('https://example.com/');

    expect($result->success)->toBeFalse()
        ->and($result->statusCode)->toBe(503)
        ->and($result->errorCode)->toBe(ProbeErrorCode::UnexpectedStatus);
});

test('HEAD requests and custom headers are sent', function () {
    Http::fake(['*' => Http::response('', 200)]);

    probe('https://example.com/', ['Authorization' => 'Bearer secret'], method: HttpMethod::Head);

    Http::assertSent(fn (Request $request) => $request->method() === 'HEAD'
        && $request->hasHeader('Authorization', 'Bearer secret')
        && str_starts_with($request->header('User-Agent')[0], 'Veyra-Uptime-Monitor'));
});

test('connection timeouts are classified', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 10001 milliseconds'));

    expect(probe('https://example.com/')->errorCode)->toBe(ProbeErrorCode::Timeout);
});

test('connection and TLS failures are classified', function (string $message, ProbeErrorCode $code) {
    Http::fake(fn () => throw new ConnectionException($message));

    expect(probe('https://example.com/')->errorCode)->toBe($code);
})->with([
    ['cURL error 7: Failed to connect to example.com port 443', ProbeErrorCode::ConnectionFailed],
    ['cURL error 60: SSL certificate problem: certificate has expired', ProbeErrorCode::TlsError],
]);

test('names that do not resolve fail with a DNS error without any request', function () {
    Http::fake();

    expect(probe('https://does-not-exist.example.net/')->errorCode)->toBe(ProbeErrorCode::DnsFailure);

    Http::assertNothingSent();
});

test('destinations resolving to forbidden addresses are never requested', function (string $ip) {
    Http::fake();
    $this->dns->set('internal.example.com', [$ip]);

    expect(probe('https://internal.example.com/')->errorCode)->toBe(ProbeErrorCode::ForbiddenDestination);

    Http::assertNothingSent();
})->with(['127.0.0.1', '169.254.169.254', '10.0.0.8', '::1', 'fd00:ec2::254']);

test('a host is refused when any of its addresses is forbidden', function () {
    Http::fake();
    $this->dns->set('mixed.example.com', ['93.184.215.20', '192.168.1.1']);

    expect(probe('https://mixed.example.com/')->errorCode)->toBe(ProbeErrorCode::ForbiddenDestination);
});

test('private networks are reachable only when the operator opts in', function () {
    Http::fake(['*' => Http::response('', 200)]);
    $this->dns->set('nas.lan.example.com', ['192.168.1.20']);

    expect(probe('http://nas.lan.example.com/')->errorCode)->toBe(ProbeErrorCode::ForbiddenDestination);

    config(['veyra.probes.allow_private_networks' => true]);
    expect(probe('http://nas.lan.example.com/')->success)->toBeTrue();

    config(['veyra.edition' => 'cloud']);
    expect(probe('http://nas.lan.example.com/')->errorCode)->toBe(ProbeErrorCode::ForbiddenDestination);
});

test('every redirect hop is validated against SSRF rules', function (string $location) {
    $this->dns->set('rebind.example.com', ['127.0.0.1']);
    Http::fake([
        'https://example.com/*' => Http::response('', 302, ['Location' => $location]),
        '*' => Http::response('internal secrets', 200),
    ]);

    $result = probe('https://example.com/start');

    expect($result->success)->toBeFalse()
        ->and($result->errorCode)->toBe(ProbeErrorCode::ForbiddenDestination);

    Http::assertSentCount(1);
})->with([
    'loopback literal' => ['http://127.0.0.1/admin'],
    'metadata service' => ['http://169.254.169.254/latest/meta-data/'],
    'rebinding host' => ['https://rebind.example.com/'],
    'forbidden port' => ['http://example.com:6379/'],
]);

test('redirects are followed up to the limit', function () {
    Http::fake([
        'https://example.com/a' => Http::response('', 301, ['Location' => '/b']),
        'https://example.com/b' => Http::response('', 302, ['Location' => 'https://other.example.org/c']),
        'https://other.example.org/c' => Http::response('', 200),
    ]);

    $result = probe('https://example.com/a');

    expect($result->success)->toBeTrue()
        ->and($result->metadata['redirects'])->toBe(2)
        ->and($result->metadata['final_host'])->toBe('other.example.org');
});

test('too many redirects fail the check', function () {
    Http::fake(['*' => Http::response('', 302, ['Location' => '/loop'])]);

    $result = probe('https://example.com/loop');

    expect($result->errorCode)->toBe(ProbeErrorCode::TooManyRedirects);
    Http::assertSentCount(4);
});

test('redirects are not followed when disabled', function () {
    Http::fake(['*' => Http::response('', 301, ['Location' => '/elsewhere'])]);

    $result = probe('https://example.com/', expected: '301', followRedirects: false);

    expect($result->success)->toBeTrue();
    Http::assertSentCount(1);
});

test('custom headers are not forwarded to another host on redirect', function () {
    Http::fake([
        'https://example.com/*' => Http::response('', 302, ['Location' => 'https://other.example.org/']),
        'https://other.example.org/*' => Http::response('', 200),
    ]);

    probe('https://example.com/', ['Authorization' => 'Bearer secret']);

    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://other.example.org')
        && ! $request->hasHeader('Authorization'));
});

test('connections are pinned to the validated address and hardened', function () {
    $destination = app(DestinationGuard::class)->inspect('https://example.com/health');
    $options = app(HttpProbe::class)->requestOptions($destination, 10);

    expect($options['curl'][CURLOPT_RESOLVE])->toBe(['example.com:443:93.184.215.14'])
        ->and($options['protocols'])->toBe(['http', 'https'])
        ->and($options['allow_redirects'])->toBeFalse()
        ->and($options['proxy'])->toBe('')
        ->and($options['verify'])->toBeTrue()
        ->and($options)->toHaveKeys(['on_headers', 'progress'])
        ->and($options)->not->toHaveKey('stream')
        ->and($options['timeout'])->toBe(10)
        ->and($options['connect_timeout'])->toBe(10);
});

test('ipv6 destinations are pinned with brackets', function () {
    $this->dns->set('v6.example.com', ['2606:4700:4700::1111']);

    $destination = app(DestinationGuard::class)->inspect('https://v6.example.com/');

    expect(app(HttpProbe::class)->requestOptions($destination, 5)['curl'][CURLOPT_RESOLVE])
        ->toBe(['v6.example.com:443:[2606:4700:4700::1111]']);
});

test('unexpected errors are logged without the query string or headers', function () {
    Log::spy();
    Http::fake(fn () => throw new RuntimeException('boom https://example.com/?token=super-secret'));

    $result = probe('https://example.com/path?token=super-secret', ['Authorization' => 'Bearer hidden']);

    expect($result->errorCode)->toBe(ProbeErrorCode::UnexpectedError);

    Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) {
        $encoded = json_encode([$message, $context]);

        return $message === 'veyra.probe.unexpected_error'
            && $context['destination'] === 'https://example.com/path'
            && ! str_contains($encoded, 'super-secret')
            && ! str_contains($encoded, 'hidden');
    });
});
