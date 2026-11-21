<?php

use Illuminate\Support\Facades\Schedule;

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
 * Horizon metrics for the dashboard graphs.
 */
Schedule::command('horizon:snapshot')->everyFiveMinutes();
