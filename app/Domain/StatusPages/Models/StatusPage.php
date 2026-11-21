<?php

namespace App\Domain\StatusPages\Models;

use App\Domain\Organizations\Models\Organization;
use App\Policies\StatusPagePolicy;
use Carbon\CarbonImmutable;
use Database\Factories\StatusPageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A public status page of an organization, served at /status/{slug} and,
 * once verified, on its custom domain.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $slug
 * @property string $title
 * @property string|null $description
 * @property bool $is_published
 * @property bool $show_uptime
 * @property string|null $custom_domain
 * @property string|null $domain_verification_token
 * @property CarbonImmutable|null $domain_verified_at
 * @property CarbonImmutable|null $domain_checked_at
 * @property int $domain_failures
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Organization $organization
 * @property-read Collection<int, StatusPageComponent> $components
 */
#[Fillable(['organization_id', 'slug', 'title', 'description', 'is_published', 'show_uptime', 'custom_domain', 'domain_verification_token', 'domain_verified_at', 'domain_checked_at', 'domain_failures'])]
#[Hidden(['domain_verification_token'])]
#[UseFactory(StatusPageFactory::class)]
#[UsePolicy(StatusPagePolicy::class)]
class StatusPage extends Model
{
    /** @use HasFactory<StatusPageFactory> */
    use HasFactory, HasUuids;

    /**
     * Slugs that could be confused with application paths or official pages.
     */
    public const RESERVED_SLUGS = ['admin', 'api', 'app', 'assets', 'build', 'dashboard', 'health', 'login', 'status', 'veyra', 'www'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'show_uptime' => 'boolean',
            'domain_verified_at' => 'datetime',
            'domain_checked_at' => 'datetime',
            'domain_failures' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return HasMany<StatusPageComponent, $this>
     */
    public function components(): HasMany
    {
        return $this->hasMany(StatusPageComponent::class)->orderBy('position')->orderBy('display_name');
    }

    public function hasVerifiedDomain(): bool
    {
        return $this->custom_domain !== null && $this->domain_verified_at !== null;
    }
}
