<?php

use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Organizations\Enums\ApiTokenAbility;
use App\Domain\Organizations\Models\ApiToken;
use App\Models\User;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->organization = organizationWith(User::factory()->create());
});

test('requests without a valid token are unauthenticated', function (?string $header) {
    $request = $header === null ? $this : $this->withHeader('Authorization', $header);

    $request->getJson(route('api.v1.monitors.index'))
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);
})->with([
    'missing' => null,
    'not bearer' => 'Token not-a-bearer-token',
    'malformed' => 'Bearer veyra_short',
    'unknown' => 'Bearer veyra_'.str_repeat('a', 40),
]);

test('revoked and expired tokens are refused', function (array $attributes) {
    $plain = apiTokenFor($this->organization, attributes: $attributes);

    $this->withApiToken($plain)->getJson(route('api.v1.monitors.index'))->assertUnauthorized();
})->with([
    'revoked' => [['revoked_at' => now()->subMinute()]],
    'expired' => [['expires_at' => now()->subSecond()]],
]);

test('a valid token reaches its organization and records its last use once a minute', function () {
    $plain = apiTokenFor($this->organization, attributes: ['expires_at' => now()->addDay()]);
    Monitor::factory()->create(['organization_id' => $this->organization->id]);

    $this->withApiToken($plain)->getJson(route('api.v1.monitors.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $token = ApiToken::sole();
    $firstUse = $token->last_used_at;
    expect($firstUse)->not->toBeNull();

    $this->travel(30)->seconds();
    $this->withApiToken($plain)->getJson(route('api.v1.monitors.index'))->assertOk();
    expect($token->refresh()->last_used_at->equalTo($firstUse))->toBeTrue();

    $this->travel(31)->seconds();
    $this->withApiToken($plain)->getJson(route('api.v1.monitors.index'))->assertOk();
    expect($token->refresh()->last_used_at->greaterThan($firstUse))->toBeTrue();
});

test('the API never starts a session or sets cookies', function () {
    $plain = apiTokenFor($this->organization);

    $response = $this->withApiToken($plain)->getJson(route('api.v1.monitors.index'))->assertOk();

    expect($response->headers->getCookies())->toBe([]);
});

test('a session login does not authenticate the API', function () {
    $user = $this->organization->users()->firstOrFail();

    $this->actingAs($user)->getJson(route('api.v1.monitors.index'))->assertUnauthorized();
});

test('the token keeps working when its creator leaves the organization', function () {
    $creator = $this->organization->users()->firstOrFail();
    $plain = apiTokenFor($this->organization, attributes: ['created_by' => $creator->id]);

    $creator->delete();

    $this->withApiToken($plain)->getJson(route('api.v1.monitors.index'))->assertOk();
});

test('requests are rate limited per token', function () {
    config(['veyra.api.requests_per_minute' => 3]);
    $plain = apiTokenFor($this->organization);
    $other = apiTokenFor($this->organization);

    foreach (range(1, 3) as $ignored) {
        $this->withApiToken($plain)->getJson(route('api.v1.monitors.index'))->assertOk();
    }

    $this->withApiToken($plain)->getJson(route('api.v1.monitors.index'))
        ->assertTooManyRequests()
        ->assertHeader('Retry-After');

    $this->withApiToken($other)->getJson(route('api.v1.monitors.index'))->assertOk();
});

test('read tokens cannot write', function () {
    $plain = apiTokenFor($this->organization, ApiTokenAbility::Read);
    $monitor = Monitor::factory()->create(['organization_id' => $this->organization->id]);

    $this->withApiToken($plain)->getJson(route('api.v1.monitors.show', $monitor))->assertOk();
    $this->withApiToken($plain)->postJson(route('api.v1.monitors.pause', $monitor))->assertForbidden();
    $this->withApiToken($plain)->deleteJson(route('api.v1.monitors.destroy', $monitor))->assertForbidden();
    $this->withApiToken($plain)->postJson(route('api.v1.monitors.store'), ['name' => 'x'])->assertForbidden();

    expect($monitor->refresh()->enabled)->toBeTrue();
});

test('every API route requires a token', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/'));

    expect($routes->count())->toBe(17);

    foreach ($routes as $route) {
        expect($route->gatherMiddleware())->toContain('auth:api');
    }
});
