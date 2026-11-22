<?php

namespace App\Domain\Organizations\Models;

use App\Domain\Organizations\Contracts\OrganizationActor;
use App\Domain\Organizations\Enums\ApiTokenAbility;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\ApiTokenFactory;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Access\Authorizable as AuthorizableContract;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\Access\Authorizable;

/**
 * An organization API token (ADR-044). It is a service account of the
 * organization, not of the member who created it: it keeps working when that
 * member leaves, and its fixed role (from its ability) goes through the same
 * policies as members. It authenticates the `api` guard, so `$request->user()`
 * is the token on API routes.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property ApiTokenAbility $ability
 * @property string $token_hash
 * @property string $last_four
 * @property string|null $created_by
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Organization $organization
 * @property-read User|null $creator
 */
#[Fillable(['organization_id', 'name', 'ability', 'token_hash', 'last_four', 'created_by', 'expires_at'])]
#[Hidden(['token_hash'])]
#[UseFactory(ApiTokenFactory::class)]
class ApiToken extends Model implements AuthenticatableContract, AuthorizableContract, OrganizationActor
{
    /** @use HasFactory<ApiTokenFactory> */
    use Authenticatable, Authorizable, HasFactory, HasUuids;

    /**
     * `last_used_at` is written at most once per this many seconds.
     */
    public const LAST_USED_RESOLUTION_SECONDS = 60;

    /**
     * Active (not revoked) tokens per organization.
     */
    public const MAX_PER_ORGANIZATION = 50;

    /**
     * Selectable lifetimes in days; null means no expiry.
     *
     * @var list<int>
     */
    public const EXPIRY_DAYS = [30, 90, 365];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ability' => ApiTokenAbility::class,
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Neither revoked nor expired.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')
            ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function roleIn(Organization $organization): ?OrganizationRole
    {
        return $this->isUsable() && $this->organization_id === $organization->getKey()
            ? $this->ability->role()
            : null;
    }

    /**
     * Shown in lists and timelines instead of the token: `veyra_…abcd`.
     */
    public function maskedToken(): string
    {
        return 'veyra_…'.$this->last_four;
    }

    /**
     * Tokens never sign in with a password or "remember me".
     */
    public function getRememberTokenName(): string
    {
        return '';
    }
}
