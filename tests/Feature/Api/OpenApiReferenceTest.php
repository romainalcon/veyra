<?php

use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

/*
 * docs/api/openapi.yaml documents exactly the v1 routes (ADR-044): a route
 * added or removed without updating the reference makes this test fail.
 */
test('the OpenAPI reference documents every v1 route and nothing else', function () {
    /** @var array{paths: array<string, array<string, mixed>>} $spec */
    $spec = Yaml::parseFile(base_path('docs/api/openapi.yaml'));

    $documented = collect($spec['paths'])
        ->flatMap(fn (array $operations, string $path) => collect(array_keys($operations))
            ->reject(fn (string $key) => $key === 'parameters')
            ->map(fn (string $method) => strtoupper($method).' '.$path))
        ->sort()
        ->values()
        ->all();

    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RouteDefinition $route) => str_starts_with($route->uri(), 'api/v1/'))
        ->flatMap(fn (RouteDefinition $route) => collect(array_diff($route->methods(), ['HEAD']))
            ->map(fn (string $method) => $method.' '.substr($route->uri(), strlen('api/v1'))))
        ->sort()
        ->values()
        ->all();

    expect($documented)->toBe($routes);
});
