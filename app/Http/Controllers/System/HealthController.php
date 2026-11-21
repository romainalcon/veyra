<?php

namespace App\Http\Controllers\System;

use App\Domain\System\CheckResult;
use App\Domain\System\HealthCheck;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public, minimal health status for load balancers and container
 * healthchecks; details require the configured bearer token.
 */
class HealthController extends Controller
{
    public function __invoke(Request $request, HealthCheck $healthCheck): JsonResponse
    {
        $results = $healthCheck->run();
        $status = HealthCheck::overall($results);

        $payload = ['status' => $status, 'version' => (string) config('veyra.version')];

        $token = (string) config('veyra.health_token');
        $provided = (string) $request->bearerToken();

        if ($token !== '' && $provided !== '' && hash_equals($token, $provided)) {
            $payload['checks'] = array_map(fn (CheckResult $result): array => $result->toArray(), $results);
        }

        return response()
            ->json($payload, $status === 'down' ? 503 : 200)
            ->header('Cache-Control', 'no-store');
    }
}
