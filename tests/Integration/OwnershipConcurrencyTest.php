<?php

use App\Domain\Organizations\Actions\RemoveMember;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Models\OrganizationMember;
use App\Domain\Organizations\Services\OwnershipGuard;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/*
 * Exercises real MariaDB row locks across two connections. Data is committed
 * (DatabaseTruncation), so these tests must never run against SQLite.
 */
beforeEach(function () {
    config(['database.connections.concurrent' => config('database.connections.mysql')]);
    DB::purge('concurrent');

    $this->organization = organizationWith(User::factory()->create());
    memberOf($this->organization, OrganizationRole::Owner);

    [$this->first, $this->second] = OrganizationMember::query()
        ->where('organization_id', $this->organization->id)
        ->orderBy('id')
        ->get()
        ->all();
});

afterEach(fn () => DB::disconnect('concurrent'));

test('the ownership guard locks owner rows against concurrent transactions', function () {
    $other = DB::connection('concurrent');
    $other->statement('SET SESSION innodb_lock_wait_timeout = 1');

    DB::transaction(function () use ($other) {
        app(OwnershipGuard::class)->lockAndEnsureNotLastOwner($this->first, demoting: true);

        // A second transaction trying to lock the same owners must wait.
        expect(fn () => $other->transaction(fn () => $other->table('organization_members')
            ->where('organization_id', $this->organization->id)
            ->where('role', OrganizationRole::Owner->value)
            ->lockForUpdate()
            ->get()))->toThrow(QueryException::class);
    });
});

test('removing two owners one after another keeps at least one owner', function () {
    app(RemoveMember::class)->handle($this->first);

    expect(fn () => app(RemoveMember::class)->handle($this->second))
        ->toThrow(ValidationException::class);

    expect(OrganizationMember::query()
        ->where('organization_id', $this->organization->id)
        ->where('role', OrganizationRole::Owner)
        ->count())->toBe(1);
});
