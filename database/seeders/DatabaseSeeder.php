<?php

namespace Database\Seeders;

use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Models\Organization;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed a local development dataset. Never run against production.
     */
    public function run(): void
    {
        $owner = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        Organization::factory()
            ->withMember($owner, OrganizationRole::Owner)
            ->create(['name' => 'Acme', 'slug' => 'acme']);
    }
}
