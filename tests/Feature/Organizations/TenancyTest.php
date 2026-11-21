<?php

use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->ownOrganization = organizationWith($this->user);

    $this->stranger = User::factory()->create();
    $this->foreignOrganization = organizationWith($this->stranger);
});

test('non-members receive 404 on every tenant route', function (string $method, string $route) {
    $this->actingAs($this->user)
        ->{$method}(route($route, $this->foreignOrganization), ['name' => 'Hijacked', 'confirmation' => $this->foreignOrganization->slug])
        ->assertNotFound();

    expect($this->foreignOrganization->fresh())->not->toBeNull()
        ->and($this->foreignOrganization->fresh()->name)->not->toBe('Hijacked');
})->with([
    ['get', 'organizations.dashboard'],
    ['get', 'organizations.edit'],
    ['patch', 'organizations.update'],
    ['delete', 'organizations.destroy'],
    ['get', 'organizations.members.index'],
    ['post', 'organizations.invitations.store'],
]);

test('a foreign member cannot be updated through one\'s own organization', function () {
    $foreignMember = $this->foreignOrganization->members()->sole();

    $this->actingAs($this->user)
        ->patch(route('organizations.members.update', [$this->ownOrganization, $foreignMember]), ['role' => 'viewer'])
        ->assertNotFound();

    expect($foreignMember->fresh()->role)->toBe(OrganizationRole::Owner);
});

test('a foreign member cannot be removed through one\'s own organization', function () {
    $foreignMember = $this->foreignOrganization->members()->sole();

    $this->actingAs($this->user)
        ->delete(route('organizations.members.destroy', [$this->ownOrganization, $foreignMember]))
        ->assertNotFound();

    expect($foreignMember->fresh())->not->toBeNull();
});

test('a foreign invitation cannot be revoked through one\'s own organization', function () {
    $invitation = OrganizationInvitation::factory()->create(['organization_id' => $this->foreignOrganization->id]);

    $this->actingAs($this->user)
        ->delete(route('organizations.invitations.destroy', [$this->ownOrganization, $invitation]))
        ->assertNotFound();

    expect($invitation->fresh())->not->toBeNull();
});

test('the organization switcher only lists the user\'s organizations', function () {
    $this->actingAs($this->user)
        ->get(route('organizations.dashboard', $this->ownOrganization))
        ->assertInertia(fn ($page) => $page
            ->has('organizations', 1)
            ->where('organizations.0.id', $this->ownOrganization->id));
});

test('private organization channels only authorize members', function () {
    config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb' => [
        'driver' => 'reverb',
        'key' => 'test-key',
        'secret' => 'test-secret',
        'app_id' => 'test-app',
        'options' => ['host' => 'localhost', 'port' => 8080, 'scheme' => 'http', 'useTLS' => false],
    ]]);
    Broadcast::purge('reverb');
    require base_path('routes/channels.php');

    $this->actingAs($this->user)
        ->post('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-organizations.'.$this->ownOrganization->id,
        ])->assertOk();

    $this->actingAs($this->user)
        ->post('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-organizations.'.$this->foreignOrganization->id,
        ])->assertForbidden();
});
