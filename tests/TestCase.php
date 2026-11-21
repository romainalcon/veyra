<?php

namespace Tests;

use App\Domain\Monitoring\Probes\Contracts\DnsResolver;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\FakeDnsResolver;

abstract class TestCase extends BaseTestCase
{
    protected FakeDnsResolver $dns;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dns = new FakeDnsResolver;
        $this->app->instance(DnsResolver::class, $this->dns);
    }
}
