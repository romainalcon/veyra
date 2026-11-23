<?php

namespace App\Http\Controllers\System;

use App\Domain\System\Metrics\MetricsCollector;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

/**
 * Prometheus scrape endpoint; access is checked by AuthorizeMetricsScrape.
 */
class MetricsController extends Controller
{
    public function __invoke(MetricsCollector $collector): Response
    {
        return response(MetricsCollector::render($collector->collect()), 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
