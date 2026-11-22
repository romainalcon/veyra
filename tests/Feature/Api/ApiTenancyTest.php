<?php

use App\Domain\Incidents\Models\Incident;
use App\Domain\Maintenance\Models\MaintenanceWindow;
use App\Domain\Monitoring\Models\Monitor;
use App\Models\User;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

/*
 * Every API route answers 404 for another organization's identifiers,
 * whatever the method: the tenant is the token's organization and children
 * are resolved through it (ADR-044). A new route with a parameter missing
 * from this list makes the test fail.
 */
test('a token never reaches another organization\'s resources', function () {
    $token = apiTokenFor(organizationWith(User::factory()->create()));

    $victim = organizationWith(User::factory()->create());
    $monitor = Monitor::factory()->create(['organization_id' => $victim->id]);
    $ids = [
        'monitor' => $monitor->id,
        'incident' => Incident::factory()->create(['monitor_id' => $monitor->id])->id,
        'maintenanceWindow' => MaintenanceWindow::factory()->create(['organization_id' => $victim->id])->id,
    ];

    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RouteDefinition $route) => str_starts_with($route->uri(), 'api/v1/') && $route->parameterNames() !== []);

    expect($routes->count())->toBe(12);

    foreach ($routes as $route) {
        Cache::flush();

        $missing = array_diff($route->parameterNames(), array_keys($ids));
        expect($missing)->toBe([], "Unknown route parameter(s) in {$route->uri()}: add them to the sweep.");

        $url = url(str_replace(array_map(fn ($name) => '{'.$name.'}', array_keys($ids)), array_values($ids), $route->uri()));

        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $this->withApiToken($token)->json($method, $url)->assertNotFound();
        }
    }

    expect($monitor->refresh()->enabled)->toBeTrue()
        ->and(Monitor::query()->whereKey($monitor->id)->exists())->toBeTrue();
});

test('lists only contain the token organization\'s resources', function () {
    $organization = organizationWith(User::factory()->create());
    $token = apiTokenFor($organization);
    $own = Monitor::factory()->create(['organization_id' => $organization->id]);
    $foreign = Monitor::factory()->create();
    Incident::factory()->create(['monitor_id' => $foreign->id]);
    MaintenanceWindow::factory()->create(['organization_id' => $foreign->organization_id]);

    $this->withApiToken($token)->getJson(route('api.v1.monitors.index'))
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $own->id);
    $this->withApiToken($token)->getJson(route('api.v1.incidents.index', ['monitor_id' => $foreign->id]))->assertJsonCount(0, 'data');
    $this->withApiToken($token)->getJson(route('api.v1.maintenance-windows.index'))->assertJsonCount(0, 'data');
});
