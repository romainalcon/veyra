<?php

namespace App\Domain\Organizations\Models;

use App\Domain\Incidents\Models\Incident;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Models\User;
use App\Policies\OrganizationPolicy;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property array<string, mixed>|null $settings
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, User> $users
 * @property-read Collection<int, OrganizationMember> $members
 * @property-read Collection<int, OrganizationInvitation> $invitations
 * @property-read Collection<int, Monitor> $monitors
 */
#[Fillable(['name', 'slug', 'settings'])]
#[UseFactory(OrganizationFactory::class)]
#[UsePolicy(OrganizationPolicy::class)]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return BelongsToMany<User, $this, OrganizationMember, 'membership'>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_members')
            ->using(OrganizationMember::class)
            ->as('membership')
            ->withPivot(['id', 'role', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<OrganizationMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(OrganizationMember::class);
    }

    /**
     * @return HasMany<OrganizationInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(OrganizationInvitation::class);
    }

    /**
     * @return HasMany<Monitor, $this>
     */
    public function monitors(): HasMany
    {
        return $this->hasMany(Monitor::class);
    }

    /**
     * @return HasMany<Incident, $this>
     */
    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }

    /**
     * @return HasMany<NotificationChannel, $this>
     */
    public function notificationChannels(): HasMany
    {
        return $this->hasMany(NotificationChannel::class);
    }
}
