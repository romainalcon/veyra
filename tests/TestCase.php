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
}
