<?php

use App\Domain\Incidents\Models\Incident;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorCheck;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Reports\Actions\BuildWeeklyReport;
use App\Domain\Reports\Jobs\SendWeeklyReport;
use App\Domain\Reports\Mail\WeeklyReportMail;
use App\Domain\Reports\WeeklyReportPeriod;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->organization = organizationWith($this->owner, OrganizationRole::Owner, ['name' => 'Acme', 'timezone' => 'Europe/Paris']);
});

function optIn(Organization $organization, User $user, bool $enabled = true): void
{
    $organization->members()->where('user_id', $user->id)->update(['weekly_report' => $enabled]);
}

function weeklyCheck(Monitor $monitor, string $checkedAt, bool $success, int $durationMs = 100, bool $maintenance = false): void
{
    MonitorCheck::factory()->create([
        'monitor_id' => $monitor->id,
        'scheduled_for' => $checkedAt,
        'checked_at' => $checkedAt,
        'success' => $success,
        'duration_ms' => $durationMs,
        'in_maintenance' => $maintenance,
    ]);
}

test('the reported week is the previous local Monday to Monday, due from 08:00 local time', function () {
    // Monday 23 November 2026, 06:59 UTC = 07:59 in Paris: not due yet.
    expect(WeeklyReportPeriod::dueAt(CarbonImmutable::parse('2026-11-23 06:59:00'), 'Europe/Paris'))->toBeNull();

    $period = WeeklyReportPeriod::dueAt(CarbonImmutable::parse('2026-11-23 07:00:00'), 'Europe/Paris');
    expect($period->localStartDate)->toBe('2026-11-16')
        ->and($period->start->toDateTimeString())->toBe('2026-11-15 23:00:00')
        ->and($period->end->toDateTimeString())->toBe('2026-11-22 23:00:00');

    // Still the same week later in the week (catch-up after a scheduler outage).
    expect(WeeklyReportPeriod::dueAt(CarbonImmutable::parse('2026-11-27 12:00:00'), 'Europe/Paris')->localStartDate)->toBe('2026-11-16');
});

test('a week across a daylight saving change keeps local midnights', function () {
    // Europe/Paris leaves summer time on Sunday 25 October 2026: that week lasts 169 hours.
    $period = WeeklyReportPeriod::dueAt(CarbonImmutable::parse('2026-10-26 09:00:00'), 'Europe/Paris');

    expect($period->start->toDateTimeString())->toBe('2026-10-18 22:00:00')
        ->and($period->end->toDateTimeString())->toBe('2026-10-25 23:00:00')
        ->and((int) $period->start->diffInHours($period->end))->toBe(169);
});

test('reports are queued once per organization and week, only with opted-in members and monitors', function () {
    Queue::fake([SendWeeklyReport::class]);
    Monitor::factory()->create(['organization_id' => $this->organization->id]);
    $this->travelTo('2026-11-23 08:30:00');

    $this->artisan('veyra:reports:weekly')->assertSuccessful();
    Queue::assertNothingPushed();

    optIn($this->organization, $this->owner);
    organizationWith(User::factory()->create()); // no monitor, nobody opted in

    $this->artisan('veyra:reports:weekly');
    $this->artisan('veyra:reports:weekly');
    $this->travelTo('2026-11-24 10:00:00');
    $this->artisan('veyra:reports:weekly');

    Queue::assertPushed(SendWeeklyReport::class, 1);
    expect(DB::table('weekly_reports')->where('organization_id', $this->organization->id)->value('period_start'))->toBe('2026-11-16');
});

test('organizations in other time zones get their report on their own Monday morning', function () {
    Queue::fake([SendWeeklyReport::class]);
    $tokyoOwner = User::factory()->create();
    $tokyo = organizationWith($tokyoOwner, OrganizationRole::Owner, ['timezone' => 'Asia/Tokyo']);
    Monitor::factory()->create(['organization_id' => $tokyo->id]);
    Monitor::factory()->create(['organization_id' => $this->organization->id]);
    optIn($tokyo, $tokyoOwner);
    optIn($this->organization, $this->owner);

    // Sunday 22 November, 23:30 UTC = Monday 08:30 in Tokyo, Monday 00:30 in Paris.
    $this->travelTo('2026-11-22 23:30:00');
    $this->artisan('veyra:reports:weekly');

    Queue::assertPushed(SendWeeklyReport::class, 1);
    expect(DB::table('weekly_reports')->pluck('organization_id')->all())->toBe([$tokyo->id]);
});

test('the report sums uptime, incidents, downtime and latency of the local week only', function () {
    $api = Monitor::factory()->create(['organization_id' => $this->organization->id, 'name' => 'API', 'interval_seconds' => 3600, 'created_at' => '2026-11-01 00:00:00']);
    $site = Monitor::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Site', 'interval_seconds' => 3600, 'created_at' => '2026-11-01 00:00:00']);
    $foreign = Monitor::factory()->create(['name' => 'Foreign']);

    // Inside the window (2026-11-15 23:00 → 2026-11-22 23:00 UTC).
    weeklyCheck($api, '2026-11-16 10:00:00', true, 300);
    weeklyCheck($api, '2026-11-16 11:00:00', false, 900);
    weeklyCheck($api, '2026-11-16 12:00:00', true, 500);
    weeklyCheck($api, '2026-11-16 13:00:00', true, 100, maintenance: true);
    weeklyCheck($site, '2026-11-17 10:00:00', true, 50);
    // Outside: before the local Monday and after the local Sunday.
    weeklyCheck($api, '2026-11-15 22:30:00', false);
    weeklyCheck($site, '2026-11-22 23:30:00', false);
    weeklyCheck($foreign, '2026-11-17 10:00:00', false, 5000);

    Incident::factory()->resolved()->create(['monitor_id' => $api->id, 'started_at' => '2026-11-16 11:00:00', 'resolved_at' => '2026-11-16 11:30:00']);
    Incident::factory()->create(['monitor_id' => $site->id, 'started_at' => '2026-11-22 22:00:00']);
    Incident::factory()->resolved()->create(['monitor_id' => $site->id, 'started_at' => '2026-11-10 10:00:00', 'resolved_at' => '2026-11-11 10:00:00']);
    Incident::factory()->create(['monitor_id' => $foreign->id, 'started_at' => '2026-11-17 10:00:00']);

    $period = WeeklyReportPeriod::dueAt(CarbonImmutable::parse('2026-11-23 08:00:00'), 'Europe/Paris');
    $report = app(BuildWeeklyReport::class)->handle($this->organization, $period->start, $period->end);

    expect($report->monitorsCount)->toBe(2)
        ->and($report->overall['checks'])->toBe(4)
        ->and($report->overall['uptime'])->toBe(75.0)
        ->and($report->overall['partial'])->toBeTrue()
        ->and($report->lowestUptime)->toHaveCount(1)
        ->and($report->lowestUptime[0]['name'])->toBe('API')
        ->and(round($report->lowestUptime[0]['uptime'], 2))->toBe(66.67)
        ->and($report->slowest[0])->toBe(['name' => 'API', 'latency_ms' => 400])
        ->and($report->slowest[1])->toBe(['name' => 'Site', 'latency_ms' => 50])
        ->and($report->incidentsCount)->toBe(2)
        ->and($report->downtimeSeconds)->toBe(1800 + 3600)
        ->and($report->incidents[0]['open'])->toBeTrue()
        ->and($report->incidents[1]['started_at'])->toContain('12:00')
        ->and($report->periodLabel)->toBe('16 Nov – 22 Nov 2026');
});

test('a week without checks reports no data, never 100 %', function () {
    Monitor::factory()->create(['organization_id' => $this->organization->id, 'created_at' => '2026-11-01 00:00:00']);
    $period = WeeklyReportPeriod::dueAt(CarbonImmutable::parse('2026-11-23 08:00:00'), 'UTC');

    $report = app(BuildWeeklyReport::class)->handle($this->organization, $period->start, $period->end);

    expect($report->overall['uptime'])->toBeNull()
        ->and(WeeklyReportMail::percent($report->overall['uptime']))->toBe('No data');
});

test('the job mails opted-in verified members once, in the organization language', function () {
    Mail::fake();
    $this->organization->update(['locale' => 'fr']);
    Monitor::factory()->create(['organization_id' => $this->organization->id, 'name' => 'API | public']);
    $member = memberOf($this->organization, OrganizationRole::Member);
    $unverified = memberOf($this->organization, OrganizationRole::Viewer);
    $unverified->forceFill(['email_verified_at' => null])->save();
    $notOptedIn = memberOf($this->organization, OrganizationRole::Admin);
    optIn($this->organization, $this->owner);
    optIn($this->organization, $member);
    optIn($this->organization, $unverified);

    // The same user opted in elsewhere does not receive this organization's report twice.
    $elsewhere = organizationWith($member);
    optIn($elsewhere, $member);

    $this->travelTo('2026-11-23 08:30:00');
    $this->artisan('veyra:reports:weekly');
    $this->artisan('veyra:reports:weekly');

    $id = (string) DB::table('weekly_reports')->where('organization_id', $this->organization->id)->value('id');
    (new SendWeeklyReport($id, '2026-11-15T23:00:00Z', '2026-11-22T23:00:00Z'))->handle(app(BuildWeeklyReport::class));

    Mail::assertQueued(WeeklyReportMail::class, 2);
    Mail::assertQueued(WeeklyReportMail::class, fn (WeeklyReportMail $mail) => $mail->hasTo($this->owner->email));
    Mail::assertQueued(WeeklyReportMail::class, fn (WeeklyReportMail $mail) => $mail->hasTo($member->email));
    Mail::assertNotQueued(WeeklyReportMail::class, fn (WeeklyReportMail $mail) => $mail->hasTo($notOptedIn->email) || $mail->hasTo($unverified->email));

    $mail = new WeeklyReportMail(app(BuildWeeklyReport::class)->handle($this->organization, CarbonImmutable::parse('2026-11-15 23:00:00'), CarbonImmutable::parse('2026-11-22 23:00:00')));
    $mail->assertHasSubject('[Veyra] Rapport hebdomadaire — Acme, 16 nov. – 22 nov. 2026');
    $mail->assertSeeInHtml('Disponibilité');
    $mail->assertSeeInHtml('Aucune donnée');
    $mail->assertDontSeeInHtml('Weekly report');

    expect(DB::table('weekly_reports')->where('id', $id)->value('recipients_count'))->toBe(2);
});

test('a retried job after queueing sends nothing again', function () {
    Mail::fake();
    Monitor::factory()->create(['organization_id' => $this->organization->id]);
    optIn($this->organization, $this->owner);
    $id = (string) Str::uuid7();
    DB::table('weekly_reports')->insert(['id' => $id, 'organization_id' => $this->organization->id, 'period_start' => '2026-11-16', 'created_at' => now()]);

    $job = new SendWeeklyReport($id, '2026-11-15T23:00:00Z', '2026-11-22T23:00:00Z');
    $job->handle(app(BuildWeeklyReport::class));
    $job->handle(app(BuildWeeklyReport::class));

    Mail::assertQueued(WeeklyReportMail::class, 1);
});

test('monitor names cannot break the e-mail tables', function () {
    expect(WeeklyReportMail::cell("API | prod\nv2"))->toBe('API \| prod v2');
});

test('each member toggles their own weekly report from the settings page', function () {
    $viewer = memberOf($this->organization, OrganizationRole::Viewer);

    $this->actingAs($viewer)
        ->get(route('organizations.edit', $this->organization))
        ->assertInertia(fn (Assert $page) => $page->where('weeklyReport', false));

    $this->put(route('organizations.weekly-report.update', $this->organization), ['weekly_report' => '1'])
        ->assertRedirect(route('organizations.edit', $this->organization));

    expect($this->organization->members()->where('user_id', $viewer->id)->value('weekly_report'))->toBeTruthy()
        ->and($this->organization->members()->where('user_id', $this->owner->id)->value('weekly_report'))->toBeFalsy();

    $this->get(route('organizations.edit', $this->organization))
        ->assertInertia(fn (Assert $page) => $page->where('weeklyReport', true));

    $this->put(route('organizations.weekly-report.update', $this->organization), ['weekly_report' => 'maybe'])
        ->assertSessionHasErrors('weekly_report');
});

test('non-members cannot change a weekly report preference', function () {
    $this->actingAs(User::factory()->create())
        ->put(route('organizations.weekly-report.update', $this->organization), ['weekly_report' => '1'])
        ->assertNotFound();
});
