<?php

use App\Domain\Monitoring\Certificates\CertificateParser;
use App\Domain\Monitoring\Certificates\RecordTlsCertificate;
use App\Domain\Monitoring\DTOs\ExpectedStatuses;
use App\Domain\Monitoring\DTOs\ProbeResult;
use App\Domain\Monitoring\DTOs\TlsCertificate;
use App\Domain\Monitoring\Enums\DispatchStatus;
use App\Domain\Monitoring\Enums\HttpMethod;
use App\Domain\Monitoring\Jobs\ExecuteProbe;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorDispatch;
use App\Domain\Monitoring\Models\MonitorTlsCertificate;
use App\Domain\Monitoring\Probes\Destination;
use App\Domain\Monitoring\Probes\HttpProbe;
use App\Domain\Monitoring\Probes\ParsedUrl;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\TransferStats;
use Illuminate\Support\Facades\Http;
use Tests\Support\SelfSignedCertificate;

test('the leaf certificate is parsed from cURL certinfo', function () {
    $pem = SelfSignedCertificate::pem('shop.example.com', 30);

    $certificate = CertificateParser::fromCertinfo([['Subject' => 'ignored', 'Cert' => $pem], ['Cert' => 'intermediate']]);

    expect($certificate)->toBeInstanceOf(TlsCertificate::class)
        ->and($certificate->subject)->toBe('shop.example.com')
        ->and($certificate->issuer)->toBe('Example Org')
        ->and($certificate->fingerprint)->toBe(strtolower((string) openssl_x509_fingerprint($pem, 'sha256')))
        ->and(abs($certificate->notAfter->diffInDays(now()->addDays(30))))->toBeLessThan(1);
});

test('missing or malformed certinfo never breaks a check', function (mixed $certinfo) {
    expect(CertificateParser::fromCertinfo($certinfo))->toBeNull();
})->with([
    'null' => [null],
    'empty' => [[]],
    'no pem' => [[['Subject' => 'x']]],
    'garbage pem' => [[['Cert' => "-----BEGIN CERTIFICATE-----\nnope\n-----END CERTIFICATE-----"]]],
]);

test('certificate capture enables CURLOPT_CERTINFO only when requested', function () {
    $probe = app(HttpProbe::class);
    $destination = new Destination(new ParsedUrl('https://example.com/', 'https', 'example.com', 443, false), '93.184.215.14');

    $plain = $probe->requestOptions($destination, 5);
    $timing = $probe->requestOptions($destination, 5, fn () => null);
    $capturing = $probe->requestOptions($destination, 5, fn () => null, captureCertificate: true);

    expect($plain['curl'])->not->toHaveKey(CURLOPT_CERTINFO)
        ->and($plain)->not->toHaveKey('on_stats')
        ->and($timing['curl'])->not->toHaveKey(CURLOPT_CERTINFO)
        ->and($timing['on_stats'])->toBeCallable()
        ->and($capturing['curl'][CURLOPT_CERTINFO])->toBeTrue()
        ->and($capturing['on_stats'])->toBeCallable();
});

test('the HTTPS probe attaches the certificate of the monitored host', function () {
    $pem = SelfSignedCertificate::pem('example.com');
    Http::fake(function ($request, array $options) use ($pem) {
        if (isset($options['on_stats'])) {
            $options['on_stats'](new TransferStats(new Request('GET', 'https://example.com/'), null, 0.1, null, ['certinfo' => [['Cert' => $pem]]]));
        }

        return Http::response('', 200);
    });

    $https = app(HttpProbe::class)->probe('https://example.com/', HttpMethod::Get, [], ExpectedStatuses::default(), true, 5);
    $http = app(HttpProbe::class)->probe('http://example.com/', HttpMethod::Get, [], ExpectedStatuses::default(), true, 5);

    expect($https->certificate?->subject)->toBe('example.com')
        ->and($http->certificate)->toBeNull();
});

test('checks store the latest certificate without rewriting it every minute', function () {
    $monitor = Monitor::factory()->create();
    $certificate = new TlsCertificate(str_repeat('a', 64), 'example.com', 'Example CA', now()->subDays(10), now()->addDays(80));
    $probe = Mockery::mock(HttpProbe::class);
    $probe->shouldReceive('run')->andReturn(new ProbeResult(true, 120, 200, null, [], $certificate));
    $this->app->instance(HttpProbe::class, $probe);

    $first = MonitorDispatch::factory()->create(['monitor_id' => $monitor->id, 'scheduled_for' => now()]);
    app()->call([new ExecuteProbe($first->id), 'handle']);
    $stored = MonitorTlsCertificate::findOrFail($monitor->id);

    expect($stored->fingerprint)->toBe(str_repeat('a', 64))
        ->and($stored->issuer)->toBe('Example CA')
        ->and($first->fresh()->status)->toBe(DispatchStatus::Completed);

    $this->travel(10)->minutes();
    app(RecordTlsCertificate::class)->handle($monitor->id, $certificate);
    expect($stored->fresh()->checked_at->equalTo($stored->checked_at))->toBeTrue();

    $renewed = new TlsCertificate(str_repeat('b', 64), 'example.com', 'Example CA', now(), now()->addDays(90));
    app(RecordTlsCertificate::class)->handle($monitor->id, $renewed);
    expect($stored->fresh()->fingerprint)->toBe(str_repeat('b', 64));
});
