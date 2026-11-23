<?php

namespace App\Domain\Monitoring\Models;

use App\Domain\Incidents\Models\Incident;
use App\Domain\Monitoring\Enums\HeartbeatPeriod;
use App\Domain\Monitoring\Enums\MonitorType;
use App\Domain\Notifications\Models\EscalationPolicy;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Organizations\Models\Organization;
use App\Policies\MonitorPolicy;
use Carbon\CarbonImmutable;
use Database\Factories\MonitorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property MonitorType $type
 * @property bool $enabled
 * @property int $interval_seconds
 * @property int $timeout_seconds
 * @property CarbonImmutable|null $next_check_at
 * @property string|null $escalation_policy_id null = organization default policy (ADR-045)
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Organization $organization
 * @property-read MonitorHttpConfig|null $httpConfig
 * @property-read MonitorTcpConfig|null $tcpConfig
 * @property-read MonitorHeartbeatConfig|null $heartbeatConfig
 * @property-read MonitorDnsConfig|null $dnsConfig
 * @property-read MonitorDomain|null $domain
 * @property-read MonitorTlsCertificate|null $tlsCertificate
 * @property-read MonitorCheck|null $latestCheck
 * @property-read MonitorState|null $state
 * @property-read EscalationPolicy|null $escalationPolicy
 */
#[Fillable(['organization_id', 'name', 'type', 'enabled', 'interval_seconds', 'timeout_seconds', 'next_check_at', 'escalation_policy_id'])]
#[UseFactory(MonitorFactory::class)]
#[UsePolicy(MonitorPolicy::class)]
class Monitor extends Model
{
    /** @use HasFactory<MonitorFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => MonitorType::class,
            'enabled' => 'boolean',
            'interval_seconds' => 'integer',
            'timeout_seconds' => 'integer',
            'next_check_at' => 'datetime',
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
     * @return HasOne<MonitorHttpConfig, $this>
     */
    public function httpConfig(): HasOne
    {
        return $this->hasOne(MonitorHttpConfig::class);
    }

    /**
     * @return HasOne<MonitorTcpConfig, $this>
     */
    public function tcpConfig(): HasOne
    {
        return $this->hasOne(MonitorTcpConfig::class);
    }

    /**
     * @return HasOne<MonitorHeartbeatConfig, $this>
     */
    public function heartbeatConfig(): HasOne
    {
        return $this->hasOne(MonitorHeartbeatConfig::class);
    }

    /**
     * @return HasOne<MonitorDnsConfig, $this>
     */
    public function dnsConfig(): HasOne
    {
        return $this->hasOne(MonitorDnsConfig::class);
    }

    /**
     * @return HasOne<MonitorDomain, $this>
     */
    public function domain(): HasOne
    {
        return $this->hasOne(MonitorDomain::class);
    }

    /**
     * Host whose domain registration is followed (ADR-043): the URL host of
     * an HTTP monitor or the record name of a DNS monitor; null for other
     * types and IP addresses.
     */
    public function domainHost(): ?string
    {
        $host = match ($this->type) {
            MonitorType::Http => $this->httpConfig === null ? null : parse_url($this->httpConfig->url, PHP_URL_HOST),
            MonitorType::Dns => $this->dnsConfig?->record_name,
            default => null,
        };

        if (! is_string($host) || $host === '') {
            return null;
        }

        $host = rtrim(strtolower(trim($host, '[]')), '.');

        return filter_var($host, FILTER_VALIDATE_IP) === false && str_contains($host, '.') ? $host : null;
    }

    /**
     * @return HasOne<MonitorTlsCertificate, $this>
     */
    public function tlsCertificate(): HasOne
    {
        return $this->hasOne(MonitorTlsCertificate::class);
    }

    /**
     * Human-readable destination, for lists and headers.
     */
    public function target(): ?string
    {
        return match ($this->type) {
            MonitorType::Http => $this->httpConfig === null ? null : "{$this->httpConfig->method->value} {$this->httpConfig->url}",
            MonitorType::Tcp => $this->tcpConfig?->target(),
            MonitorType::Dns => $this->dnsConfig?->target(),
            MonitorType::Heartbeat => (string) __('Heartbeat every :period', ['period' => HeartbeatPeriod::tryFrom($this->interval_seconds)?->label() ?? "{$this->interval_seconds} s"]),
        };
    }

    /**
     * @return HasMany<MonitorCheck, $this>
     */
    public function checks(): HasMany
    {
        return $this->hasMany(MonitorCheck::class);
    }

    /**
     * @return HasOne<MonitorCheck, $this>
     */
    public function latestCheck(): HasOne
    {
        return $this->hasOne(MonitorCheck::class)->latestOfMany('checked_at');
    }

    /**
     * @return HasOne<MonitorState, $this>
     */
    public function state(): HasOne
    {
        return $this->hasOne(MonitorState::class);
    }

    /**
     * @return HasMany<Incident, $this>
     */
    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }

    /**
     * @return BelongsToMany<NotificationChannel, $this>
     */
    public function notificationChannels(): BelongsToMany
    {
        return $this->belongsToMany(NotificationChannel::class, 'monitor_notification_channels');
    }

    /**
     * @return BelongsTo<EscalationPolicy, $this>
     */
    public function escalationPolicy(): BelongsTo
    {
        return $this->belongsTo(EscalationPolicy::class);
    }

    /**
     * @return HasMany<MonitorDailyStat, $this>
     */
    public function dailyStats(): HasMany
    {
        return $this->hasMany(MonitorDailyStat::class);
    }

    /**
     * @return HasMany<MonitorDispatch, $this>
     */
    public function dispatches(): HasMany
    {
        return $this->hasMany(MonitorDispatch::class);
    }
}
