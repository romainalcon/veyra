<?php

use Illuminate\Support\Facades\Schedule;

/*
 * Horizon metrics for the dashboard graphs. Monitoring schedules (due-monitor
 * claiming, outbox dispatch, retention) are added in later sprints.
 */
Schedule::command('horizon:snapshot')->everyFiveMinutes();
