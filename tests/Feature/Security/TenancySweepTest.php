<?php

use App\Domain\Incidents\Models\Incident;
use App\Domain\Maintenance\Models\MaintenanceWindow;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Organizations\Models\OrganizationInvitation;
use App\Domain\StatusPages\Models\StatusPage;
use App\Models\User;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;

/*
 * Every tenant route must answer 404 to a non-member, whatever the method and
 * even with valid identifiers of that organization's resources. A new route
 * that forgets the tenant middleware makes this test fail.
 */
test('every tenant route is unreachable for non-members', function () {
    $intruder = User::factory()->create();
    organizationWith($intruder);

    $victim = organizationWith(User::factory()->create());
    $monitor = Monitor::factory()->create(['organization_id' => $victim->id]);
    $ids = [
        'organization' => $victim->slug,
        'monitor' => $monitor->id,
        'member' => $victim->members()->firstOrFail()->id,
        'invitation' => OrganizationInvitation::factory()->create(['organization_id' => $victim->id])->id,
        'incident' => Incident::factory()->create(['monitor_id' => $monitor->id])->id,
        'notificationChannel' => NotificationChannel::factory()->create(['organization_id' => $victim->id])->id,
        'maintenanceWindow' => MaintenanceWindow::factory()->create(['organization_id' => $victim->id])->id,
        'statusPage' => StatusPage::factory()->create(['organization_id' => $victim->id])->id,
    ];

    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RouteDefinition $route) => in_array('organization', $route->parameterNames(), true));

    expect($routes->count())->toBeGreaterThan(20);

    $this->actingAs($intruder);

    foreach ($routes as $route) {
        $missing = array_diff($route->parameterNames(), array_keys($ids));
        expect($missing)->toBe([], "Unknown route parameter(s) in {$route->uri()}: add them to the sweep.");

        $url = url(str_replace(
            array_map(fn ($name) => '{'.$name.'}', array_keys($ids)),
            array_values($ids),
            $route->uri(),
        ));

        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $status = $this->call($method, $url, ['name' => 'Hijack', 'role' => 'owner', 'enabled' => false])->getStatusCode();

            expect($status)->toBe(404, "{$method} {$route->uri()} answered {$status} to a non-member.");
        }
    }

    expect($monitor->fresh()->enabled)->toBeTrue()
        ->and($victim->fresh()->name)->not->toBe('Hijack');
});
