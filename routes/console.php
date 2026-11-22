<?php

use Illuminate\Support\Facades\Schedule;

/*
 * Scheduler liveness for /health and the "monitoring paused" banner.
 */
Schedule::command('veyra:heartbeat')->everyMinute();

/*
 * Monitoring: claim due monitors into the MariaDB outbox and relay pending
 * dispatches to the Horizon `probes` queue. Missed runs are safe: the next run
 * catches up without replaying every missed slot.
 */
Schedule::command('veyra:monitors:dispatch')
    ->everyTenSeconds()
    ->withoutOverlapping(expiresAt: 5)
    ->onOneServer();

/*
 * Missing data turns confirmed states into UNKNOWN (never DOWN).
 */
Schedule::command('veyra:monitors:detect-stale')
    ->everyMinute()
    ->withoutOverlapping(expiresAt: 5)
    ->onOneServer();

/*
 * Planned maintenance: enter/leave the MAINTENANCE state on time.
 */
Schedule::command('veyra:maintenance:transition')
    ->everyMinute()
    ->withoutOverlapping(expiresAt: 5)
    ->onOneServer();

/*
 * Recurring maintenance: materialize occurrences ahead of time (ADR-041).
 */
Schedule::command('veyra:maintenance:materialize')->hourly()->withoutOverlapping()->onOneServer();

/*
 * Status pages: custom domain ownership (TXT) checks.
 */
Schedule::command('veyra:status-domains:verify')->hourly()->withoutOverlapping()->onOneServer();

/*
 * Notifications: a single reminder for unacknowledged incidents.
 */
Schedule::command('veyra:certificates:check')->hourly()->withoutOverlapping()->onOneServer();
Schedule::command('veyra:incidents:remind')->everyFiveMinutes()->withoutOverlapping()->onOneServer();

/*
 * Analytics and retention (jobs run on the `maintenance` queue).
 */
Schedule::command('veyra:stats:aggregate')->everyTenMinutes()->onOneServer();
Schedule::command('veyra:history:purge')->dailyAt('03:15')->timezone('UTC')->onOneServer();

/*
 * Horizon metrics for the dashboard graphs.
 */
Schedule::command('horizon:snapshot')->everyFiveMinutes();
