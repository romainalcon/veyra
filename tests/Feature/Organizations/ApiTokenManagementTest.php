<?php

use App\Domain\Organizations\Enums\ApiTokenAbility;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Models\ApiToken;
use App\Domain\Organizations\Support\ApiTokenSecret;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->organization = organizationWith($this->owner);
});

test('admins create a token that is shown once and stored hashed', function (OrganizationRole $role) {
    $manager = $role === OrganizationRole::Owner ? $this->owner : memberOf($this->organization, $role);

    $response = $this->actingAs($manager)->post(route('organizations.api-tokens.store', $this->organization), [
        'name' => 'CI pipeline',
        'ability' => 'read',
        'expires_in_days' => 30,
    ]);

    $response->assertRedirect(route('organizations.api-tokens.index', $this->organization));

    $plain = (string) ($response->getSession()->get('inertia.flash_data')['apiToken'] ?? '');
    $token = ApiToken::sole();

    expect($token->name)->toBe('CI pipeline')
        ->and($token->ability)->toBe(ApiTokenAbility::Read)
        ->and($token->created_by)->toBe($manager->id)
        ->and($token->expires_at?->isSameDay(now()->addDays(30)))->toBeTrue();

    expect(ApiTokenSecret::isWellFormed($plain))->toBeTrue()
        ->and((string) json_encode(DB::table('api_tokens')->first()))->not->toContain($plain);

    // Shown on the page right after creation, never again.
    expect($this->get(route('organizations.api-tokens.index', $this->organization))->getContent())->toContain($plain);

    $page = $this->get(route('organizations.api-tokens.index', $this->organization));
    expect($page->getContent())->not->toContain($plain);

    $page->assertInertia(fn (Assert $page) => $page
        ->component('organizations/ApiTokens')
        ->has('tokens', 1)
        ->where('tokens.0.masked', 'veyra_…'.$token->last_four)
        ->missing('tokens.0.token_hash'));
})->with([OrganizationRole::Owner, OrganizationRole::Admin]);

test('the plain token is flashed once and authenticates the API', function () {
    $response = $this->actingAs($this->owner)
        ->post(route('organizations.api-tokens.store', $this->organization), ['name' => 'Bot', 'ability' => 'write']);
    $plain = (string) ($response->getSession()->get('inertia.flash_data')['apiToken'] ?? '');

    expect(ApiTokenSecret::isWellFormed($plain))->toBeTrue()
        ->and(ApiToken::sole()->token_hash)->toBe(ApiTokenSecret::hash($plain))
        ->and(ApiToken::sole()->expires_at)->toBeNull();

    $this->withApiToken($plain)->getJson(route('api.v1.monitors.index'))->assertOk();
});

test('members and viewers cannot manage tokens', function (OrganizationRole $role) {
    $user = memberOf($this->organization, $role);
    $token = ApiToken::factory()->create(['organization_id' => $this->organization->id]);

    $this->actingAs($user)->get(route('organizations.api-tokens.index', $this->organization))->assertForbidden();
    $this->post(route('organizations.api-tokens.store', $this->organization), ['name' => 'x', 'ability' => 'write'])->assertForbidden();
    $this->delete(route('organizations.api-tokens.destroy', [$this->organization, $token]))->assertForbidden();

    expect(ApiToken::query()->count())->toBe(1)->and($token->refresh()->revoked_at)->toBeNull();
})->with([OrganizationRole::Member, OrganizationRole::Viewer]);

test('token input is validated', function (array $payload, string $field) {
    $this->actingAs($this->owner)
        ->post(route('organizations.api-tokens.store', $this->organization), [...['name' => 'Bot', 'ability' => 'read'], ...$payload])
        ->assertSessionHasErrors($field);

    expect(ApiToken::query()->count())->toBe(0);
})->with([
    'missing name' => [['name' => ''], 'name'],
    'control characters' => [['name' => "a\nb"], 'name'],
    'unknown ability' => [['ability' => 'admin'], 'ability'],
    'unknown expiry' => [['expires_in_days' => 7], 'expires_in_days'],
]);

test('active tokens are capped per organization', function () {
    ApiToken::factory()->count(ApiToken::MAX_PER_ORGANIZATION)->create(['organization_id' => $this->organization->id]);
    ApiToken::factory()->create(['organization_id' => $this->organization->id, 'revoked_at' => now()]);

    $this->actingAs($this->owner)
        ->post(route('organizations.api-tokens.store', $this->organization), ['name' => 'One more', 'ability' => 'read'])
        ->assertSessionHasErrors('name');
});

test('revoking a token stops it immediately and keeps the row', function () {
    $plain = apiTokenFor($this->organization);
    $token = ApiToken::sole();

    $this->withApiToken($plain)->getJson(route('api.v1.monitors.index'))->assertOk();

    $this->actingAs($this->owner)
        ->delete(route('organizations.api-tokens.destroy', [$this->organization, $token]))
        ->assertRedirect(route('organizations.api-tokens.index', $this->organization));

    expect($token->refresh()->revoked_at)->not->toBeNull();

    $this->withApiToken($plain)->getJson(route('api.v1.monitors.index'))->assertUnauthorized();

    $this->actingAs($this->owner)->get(route('organizations.api-tokens.index', $this->organization))
        ->assertInertia(fn (Assert $page) => $page->has('tokens', 0));
});

test('tokens of another organization cannot be revoked', function () {
    $foreign = ApiToken::factory()->create();

    $this->actingAs($this->owner)
        ->delete(route('organizations.api-tokens.destroy', [$this->organization, $foreign]))
        ->assertNotFound();

    expect($foreign->refresh()->revoked_at)->toBeNull();
});

test('deleting the organization deletes its tokens', function () {
    ApiToken::factory()->create(['organization_id' => $this->organization->id]);

    $this->organization->delete();

    expect(ApiToken::query()->count())->toBe(0);
});

test('the sidebar flag follows the role', function () {
    $this->actingAs($this->owner)->get(route('organizations.dashboard', $this->organization))
        ->assertInertia(fn (Assert $page) => $page->where('currentOrganization.can.manageApiTokens', true));

    $this->actingAs(memberOf($this->organization, OrganizationRole::Member))->get(route('organizations.dashboard', $this->organization))
        ->assertInertia(fn (Assert $page) => $page->where('currentOrganization.can.manageApiTokens', false));
});
