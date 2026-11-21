<?php

namespace Tests;

use App\Domain\Monitoring\Probes\Contracts\DnsResolver;
use App\Domain\StatusPages\Contracts\TxtResolver;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\FakeDnsResolver;
use Tests\Support\FakeTxtResolver;

abstract class TestCase extends BaseTestCase
{
    protected FakeDnsResolver $dns;

    protected FakeTxtResolver $txt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dns = new FakeDnsResolver;
        $this->app->instance(DnsResolver::class, $this->dns);

        $this->txt = new FakeTxtResolver;
        $this->app->instance(TxtResolver::class, $this->txt);
    }
}
