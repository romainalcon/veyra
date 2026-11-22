<?php

namespace Database\Factories;

use App\Domain\Monitoring\DTOs\ExpectedStatuses;
use App\Domain\Monitoring\Enums\DnsMatchMode;
use App\Domain\Monitoring\Enums\DnsRecordType;
use App\Domain\Monitoring\Enums\DnsResolverChoice;
use App\Domain\Monitoring\Enums\HttpMethod;
use App\Domain\Monitoring\Enums\MonitorType;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorState;
use App\Domain\Monitoring\Support\HeartbeatToken;
use App\Domain\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Monitor>
 */
class MonitorFactory extends Factory
{
    protected $model = Monitor::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->domainWord().' website',
            'type' => MonitorType::Http,
            'enabled' => true,
            'interval_seconds' => 60,
            'timeout_seconds' => 10,
            'next_check_at' => now(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Monitor $monitor): void {
            MonitorState::ensureFor($monitor->id);

            if ($monitor->type === MonitorType::Tcp) {
                $monitor->tcpConfig()->create(['host' => 'example.com', 'port' => 443]);

                return;
            }

            if ($monitor->type === MonitorType::Dns) {
                $monitor->dnsConfig()->create([
                    'record_name' => 'example.com',
                    'record_type' => DnsRecordType::A,
                    'resolver' => DnsResolverChoice::Cloudflare,
                    'expected_values' => null,
                    'match_mode' => DnsMatchMode::Contains,
                ]);

                return;
            }

            if ($monitor->type !== MonitorType::Http) {
                return;
            }

            $monitor->httpConfig()->create([
                'url' => 'https://example.com/health',
                'method' => HttpMethod::Get,
                'expected_statuses' => ExpectedStatuses::default(),
                'follow_redirects' => true,
                'headers_encrypted' => null,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function http(array $attributes): static
    {
        return $this->afterCreating(function (Monitor $monitor) use ($attributes): void {
            // Model update, so encrypted casts apply.
            $monitor->httpConfig()->sole()->update($attributes);
        });
    }

    public function ofType(MonitorType $type): static
    {
        return $this->state(fn (): array => ['type' => $type]);
    }

    /**
     * A TCP monitor; the default target is example.com:443.
     */
    public function tcp(?string $host = null, ?int $port = null): static
    {
        return $this->ofType(MonitorType::Tcp)->afterCreating(function (Monitor $monitor) use ($host, $port): void {
            $monitor->tcpConfig()->update(array_filter(['host' => $host, 'port' => $port], fn (mixed $value): bool => $value !== null));
        });
    }

    /**
     * A DNS monitor; the default query is the A record of example.com via Cloudflare.
     *
     * @param  array<string, mixed>  $attributes  monitor_dns_configs columns
     */
    public function dns(array $attributes = []): static
    {
        return $this->ofType(MonitorType::Dns)->afterCreating(function (Monitor $monitor) use ($attributes): void {
            if ($attributes !== []) {
                $monitor->dnsConfig()->sole()->update($attributes);
            }
        });
    }

    /**
     * A heartbeat monitor pinged with the given plain token.
     */
    public function heartbeat(string $token, int $periodSeconds = 3600, int $graceSeconds = 300): static
    {
        return $this->ofType(MonitorType::Heartbeat)
            ->state(fn (): array => [
                'interval_seconds' => $periodSeconds,
                'next_check_at' => now()->addSeconds($periodSeconds + $graceSeconds),
            ])
            ->afterCreating(function (Monitor $monitor) use ($token, $graceSeconds): void {
                $monitor->heartbeatConfig()->create([
                    'token_hash' => HeartbeatToken::hash($token),
                    'grace_seconds' => $graceSeconds,
                ]);
            });
    }

    public function paused(): static
    {
        return $this->state(fn (): array => ['enabled' => false]);
    }

    public function due(): static
    {
        return $this->state(fn (): array => ['next_check_at' => now()->subSecond()]);
    }
}
