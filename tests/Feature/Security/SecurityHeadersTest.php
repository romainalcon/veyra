<?php

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Support\Facades\Vite;

beforeEach(function () {
    // Behave like a production build even if a Vite dev server is running locally.
    Vite::useHotFile(storage_path('framework/testing/no-vite-hot-file'));
});

test('pages are served with a strict content security policy and hardening headers', function () {
    $response = $this->get(route('login'))->assertOk();

    $csp = (string) $response->headers->get('Content-Security-Policy');

    expect($csp)->toContain("default-src 'self'")
        ->toContain("frame-ancestors 'none'")
        ->toContain("object-src 'none'")
        ->toMatch("/script-src 'self' 'nonce-[A-Za-z0-9]+'/")
        ->not->toContain("script-src 'self' 'unsafe-inline'");

    $response->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});

test('the inline theme script carries the CSP nonce', function () {
    $response = $this->get(route('login'));

    preg_match("/'nonce-([A-Za-z0-9]+)'/", (string) $response->headers->get('Content-Security-Policy'), $matches);

    expect($response->getContent())->toContain('<script nonce="'.$matches[1].'">');
});

test('the Reverb WebSocket origin is allowed in connect-src', function () {
    config(['veyra.csp.connect_src' => ['wss://reverb.example.com:443']]);

    expect(app(SecurityHeaders::class)->policy('abc'))->toContain("connect-src 'self' wss://reverb.example.com:443");
});

test('HSTS is only sent over HTTPS', function () {
    $this->get('http://localhost/login')->assertHeaderMissing('Strict-Transport-Security');
    $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

test('extensions may add script, frame and image origins to the policy', function () {
    config([
        'veyra.csp.script_src' => ['https://cdn.paddle.com'],
        'veyra.csp.frame_src' => ['https://buy.paddle.com'],
        'veyra.csp.img_src' => ['https://cdn.paddle.com'],
    ]);

    $policy = app(SecurityHeaders::class)->policy('abc');

    expect($policy)
        ->toContain("script-src 'self' 'nonce-abc' https://cdn.paddle.com")
        ->toContain("frame-src 'self' https://buy.paddle.com")
        ->toContain("img-src 'self' data: https://cdn.paddle.com");
});

test('the default policy only allows the instance itself', function () {
    expect(app(SecurityHeaders::class)->policy('abc'))
        ->toContain("script-src 'self' 'nonce-abc';")
        ->toContain("frame-src 'self';");
});

test('configured origins can never inject another directive', function () {
    config(['veyra.csp.script_src' => ["https://evil.test; script-src 'unsafe-eval'", "'unsafe-inline'", 'https://ok.test']]);

    expect(app(SecurityHeaders::class)->policy('abc'))
        ->toContain("script-src 'self' 'nonce-abc' https://ok.test;")
        ->not->toContain('unsafe-eval');
});
