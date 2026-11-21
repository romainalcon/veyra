<?php

namespace App\Domain\Organizations\Actions;

use App\Domain\Organizations\DTOs\OrganizationData;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateOrganization
{
    /**
     * Create an organization and make the creator its first owner.
     */
    public function handle(User $creator, OrganizationData $data): Organization
    {
        return DB::transaction(function () use ($creator, $data): Organization {
            $organization = Organization::create([
                'name' => $data->name,
                'slug' => $this->uniqueSlug($data->name),
            ]);

            $organization->members()->create([
                'user_id' => $creator->id,
                'role' => OrganizationRole::Owner,
                'joined_at' => now(),
            ]);

            return $organization;
        });
    }

    /**
     * The unique index on `slug` remains the final guard against races.
     */
    private function uniqueSlug(string $name): string
    {
        $base = Str::limit(Str::slug($name), 48, '') ?: 'organization';
        $slug = $base;

        while (Organization::where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(6));
        }

        return $slug;
    }
}
