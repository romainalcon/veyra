<?php

use App\Domain\Monitoring\DTOs\ExpectedStatuses;
use App\Domain\Monitoring\Enums\HttpMethod;
use App\Domain\Monitoring\Enums\ProbeErrorCode;
use App\Domain\Monitoring\Probes\DestinationGuard;
use App\Domain\Monitoring\Probes\HttpProbe;
use Illuminate\Support\Facades\Http;

function edgeProbe(string $url)
{
    return app(HttpProbe::class)->probe($url, HttpMethod::Get, [], ExpectedStatuses::default(), true, 5);
}

test('disguised loopback and local addresses are never requested', function (string $url) {
    Http::fake();
    $this->dns->set('rebind.example.com', ['127.0.0.1']);

    $result = edgeProbe($url);

    expect($result->success)->toBeFalse()
        ->and($result->errorCode)->toBeIn([ProbeErrorCode::InvalidUrl, ProbeErrorCode::ForbiddenDestination, ProbeErrorCode::DnsFailure]);
    Http::assertNothingSent();
})->with([
    'decimal ip' => ['http://2130706433/'],
    'octal ip' => ['http://0177.0.0.1/'],
    'hex ip' => ['http://0x7f.1/'],
    'short ip' => ['http://127.1/'],
    'localhost' => ['http://localhost/'],
    'localhost trailing dot' => ['http://localhost./'],
    'subdomain of localhost' => ['http://app.localhost/'],
    'ipv4-mapped ipv6' => ['http://[::ffff:7f00:1]/'],
    'ipv6 loopback' => ['http://[0:0:0:0:0:0:0:1]/'],
    'unspecified' => ['http://0.0.0.0/'],
    'metadata' => ['http://169.254.169.254/latest/meta-data/'],
    'metadata alias' => ['http://[fd00:ec2::254]/'],
    'host resolving to loopback' => ['http://rebind.example.com/'],
    'mdns name' => ['http://printer.local/'],
    'internal tld' => ['http://db.internal/'],
]);

test('a relative redirect to another port is re-validated', function () {
    Http::fake([
        'https://example.com/start' => Http::response('', 302, ['Location' => '//example.com:6379/']),
        '*' => Http::response('', 200),
    ]);

    expect(edgeProbe('https://example.com/start')->errorCode)->toBe(ProbeErrorCode::ForbiddenDestination);
    Http::assertSentCount(1);
});

test('the first resolved address is pinned, so a later DNS change cannot redirect the connection', function () {
    $this->dns->set('flip.example.com', ['93.184.215.30']);
    $destination = app(DestinationGuard::class)->inspect('https://flip.example.com/');

    // An attacker flips DNS after validation: the request options still pin the validated IP.
    $this->dns->set('flip.example.com', ['127.0.0.1']);

    expect(app(HttpProbe::class)->requestOptions($destination, 5)['curl'][CURLOPT_RESOLVE])
        ->toBe(['flip.example.com:443:93.184.215.30']);
});
