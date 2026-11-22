<?php

use App\Domain\Monitoring\DTOs\ExpectedStatuses;
use App\Domain\Monitoring\DTOs\ProbeResult;
use App\Domain\Monitoring\Enums\HttpMethod;
use App\Domain\Monitoring\Enums\KeywordMode;
use App\Domain\Monitoring\Enums\ProbeErrorCode;
use App\Domain\Monitoring\Enums\RequestContentType;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Probes\ContentAssertion;
use App\Domain\Monitoring\Probes\HttpProbe;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function advancedProbe(string $url, HttpMethod $method = HttpMethod::Get, ?string $body = null, ?ContentAssertion $assertion = null, array $headers = []): ProbeResult
{
    return app(HttpProbe::class)->probe($url, $method, $headers, ExpectedStatuses::parse('200-299'), true, 10, $body, $body === null ? null : 'application/json', $assertion);
}

test('POST, PUT and PATCH send the body with its content type', function (HttpMethod $method) {
    Http::fake(['*' => Http::response('', 202)]);

    $result = advancedProbe('https://example.com/api', $method, '{"ping":true}');

    expect($result->success)->toBeTrue();
    Http::assertSent(fn (Request $request) => $request->method() === $method->value
        && $request->body() === '{"ping":true}'
        && $request->hasHeader('Content-Type', 'application/json'));
})->with([HttpMethod::Post, HttpMethod::Put, HttpMethod::Patch]);

test('a body is never sent with GET', function () {
    Http::fake(['*' => Http::response('', 200)]);

    advancedProbe('https://example.com/', HttpMethod::Get, '{"ping":true}');

    Http::assertSent(fn (Request $request) => $request->method() === 'GET' && $request->body() === '');
});

test('the SSRF policy applies to every method', function (HttpMethod $method) {
    $this->dns->set('internal.example.com', ['10.0.0.5']);
    Http::fake(['*' => Http::response('', 200)]);

    $result = advancedProbe('https://internal.example.com/', $method, $method->allowsBody() ? '{}' : null);

    expect($result->errorCode)->toBe(ProbeErrorCode::ForbiddenDestination);
    Http::assertNothingSent();
})->with([HttpMethod::Post, HttpMethod::Put, HttpMethod::Patch]);

test('303 redirects, and 301/302 after a POST, continue as GET without body', function (int $status) {
    Http::fake([
        'https://example.com/form' => Http::response('', $status, ['Location' => '/done']),
        'https://example.com/done' => Http::response('', 200),
    ]);

    advancedProbe('https://example.com/form', HttpMethod::Post, '{"secret":"body-secret"}');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://example.com/done'
        && $request->method() === 'GET'
        && $request->body() === '');
})->with([301, 302, 303]);

test('307 and 308 keep the method and body on the same host', function (int $status) {
    Http::fake([
        'https://example.com/a' => Http::response('', $status, ['Location' => '/b']),
        'https://example.com/b' => Http::response('', 200),
    ]);

    advancedProbe('https://example.com/a', HttpMethod::Put, '{"v":1}');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://example.com/b'
        && $request->method() === 'PUT'
        && $request->body() === '{"v":1}');
})->with([307, 308]);

test('the body and credentials are never forwarded to another host', function () {
    Http::fake([
        'https://example.com/*' => Http::response('', 307, ['Location' => 'https://other.example.org/c']),
        'https://other.example.org/*' => Http::response('', 200),
    ]);

    advancedProbe('https://example.com/a', HttpMethod::Post, '{"secret":"body-secret"}', headers: ['Authorization' => 'Basic dXNlcjpwYXNz']);

    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://other.example.org')
        && $request->method() === 'GET'
        && ! str_contains($request->body(), 'body-secret')
        && ! $request->hasHeader('Authorization'));
});

test('basic auth credentials are sent to the monitored host', function () {
    Http::fake(['*' => Http::response('', 200)]);
    $monitor = Monitor::factory()->http([
        'url' => 'https://example.com/private',
        'basic_auth_encrypted' => ['username' => 'robot', 'password' => 's3cret'],
    ])->create();

    $result = app(HttpProbe::class)->run($monitor->httpConfig, 10);

    expect($result->success)->toBeTrue();
    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Basic '.base64_encode('robot:s3cret')));
});

test('a stored body is sent by the monitor probe', function () {
    Http::fake(['*' => Http::response('', 200)]);
    $monitor = Monitor::factory()->http([
        'url' => 'https://example.com/graphql',
        'method' => HttpMethod::Post,
        'body_encrypted' => '{"query":"{ health }"}',
        'body_content_type' => RequestContentType::Json,
    ])->create();

    app(HttpProbe::class)->run($monitor->httpConfig, 10);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->body() === '{"query":"{ health }"}'
        && $request->hasHeader('Content-Type', 'application/json'));
});

test('keyword assertions decide the check', function (string $body, ContentAssertion $assertion, ?ProbeErrorCode $expected) {
    Http::fake(['*' => Http::response($body, 200)]);

    $result = advancedProbe('https://example.com/health', assertion: $assertion);

    expect($result->errorCode)->toBe($expected)
        ->and($result->success)->toBe($expected === null)
        ->and($result->statusCode)->toBe(200);
})->with([
    'present' => ['{"status":"OK"}', new ContentAssertion('"status":"ok"'), null],
    'missing' => ['{"status":"degraded"}', new ContentAssertion('"status":"ok"'), ProbeErrorCode::KeywordMissing],
    'case-sensitive miss' => ['{"status":"OK"}', new ContentAssertion('"status":"ok"', caseSensitive: true), ProbeErrorCode::KeywordMissing],
    'case-sensitive hit' => ['{"status":"ok"}', new ContentAssertion('"status":"ok"', caseSensitive: true), null],
    'multibyte, case-insensitive' => ['État : OPÉRATIONNEL', new ContentAssertion('opérationnel'), null],
    'absent as required' => ['all good', new ContentAssertion('error', KeywordMode::NotContains), null],
    'forbidden keyword found' => ['Fatal ERROR', new ContentAssertion('error', KeywordMode::NotContains), ProbeErrorCode::KeywordFound],
]);

test('the keyword is not evaluated when the status is unexpected', function () {
    Http::fake(['*' => Http::response('ok', 503)]);

    expect(advancedProbe('https://example.com/', assertion: new ContentAssertion('ok'))->errorCode)->toBe(ProbeErrorCode::UnexpectedStatus);
});

test('assertions ask for an uncompressed body and decode gzip under the cap', function () {
    Http::fake(['*' => Http::response(gzencode('service healthy'), 200, ['Content-Encoding' => 'gzip'])]);

    $result = advancedProbe('https://example.com/', assertion: new ContentAssertion('healthy'));

    expect($result->success)->toBeTrue();
    Http::assertSent(fn (Request $request) => $request->hasHeader('Accept-Encoding', 'identity'));
});

test('bodies in unsupported or broken encodings are unreadable, never assumed fine', function (string $body, string $encoding) {
    Http::fake(['*' => Http::response($body, 200, ['Content-Encoding' => $encoding])]);

    $result = advancedProbe('https://example.com/', assertion: new ContentAssertion('error', KeywordMode::NotContains));

    expect($result->errorCode)->toBe(ProbeErrorCode::BodyUnreadable);
})->with([
    'brotli' => ['compressed', 'br'],
    'corrupt gzip' => ['not gzip at all', 'gzip'],
]);

test('decompressed bodies larger than the response cap are unreadable', function () {
    Http::fake(['*' => Http::response(gzencode(str_repeat('a', HttpProbe::MAX_RESPONSE_BYTES + 10)), 200, ['Content-Encoding' => 'gzip'])]);

    expect(advancedProbe('https://example.com/', assertion: new ContentAssertion('b', KeywordMode::NotContains))->errorCode)
        ->toBe(ProbeErrorCode::BodyUnreadable);
});

test('HEAD monitors never evaluate a keyword', function () {
    Http::fake(['*' => Http::response('', 200)]);

    expect(advancedProbe('https://example.com/', HttpMethod::Head, assertion: new ContentAssertion('ok'))->success)->toBeTrue();
});

test('successful checks above the latency threshold are flagged slow, failures never', function () {
    $fast = new ProbeResult(true, 300, 200, null);
    $slowSuccess = new ProbeResult(true, 1500, 200, null);
    $slowFailure = ProbeResult::failure(ProbeErrorCode::Timeout, 10_000);

    expect(HttpProbe::withLatencyThreshold($fast, 1000)->slow)->toBeFalse()
        ->and(HttpProbe::withLatencyThreshold($slowSuccess, 1000)->slow)->toBeTrue()
        ->and(HttpProbe::withLatencyThreshold($slowSuccess, 1000)->success)->toBeTrue()
        ->and(HttpProbe::withLatencyThreshold($slowSuccess, null)->slow)->toBeFalse()
        ->and(HttpProbe::withLatencyThreshold($slowFailure, 1000)->slow)->toBeFalse();
});
