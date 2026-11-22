<?php

namespace Tests;

use App\Domain\Monitoring\Probes\Contracts\DnsQueryClient;
use App\Domain\Monitoring\Probes\Contracts\DnsResolver;
use App\Domain\StatusPages\Contracts\TxtResolver;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\FakeDnsQueryClient;
use Tests\Support\FakeDnsResolver;
use Tests\Support\FakeTxtResolver;

abstract class TestCase extends BaseTestCase
{
    protected FakeDnsResolver $dns;

    protected FakeTxtResolver $txt;

    protected FakeDnsQueryClient $dnsQueries;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dns = new FakeDnsResolver;
        $this->app->instance(DnsResolver::class, $this->dns);

        $this->txt = new FakeTxtResolver;
        $this->app->instance(TxtResolver::class, $this->txt);

        $this->dnsQueries = new FakeDnsQueryClient;
        $this->app->instance(DnsQueryClient::class, $this->dnsQueries);
    }

    /**
     * Send the next request with an organization API token (ADR-044). Guards
     * live as long as the application, which a test shares between requests:
     * forgetting them makes every request authenticate on its own, as in
     * production.
     */
    public function withApiToken(string $plain): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($plain);
    }
}
