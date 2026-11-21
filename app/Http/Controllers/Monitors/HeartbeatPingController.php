<?php

namespace App\Http\Controllers\Monitors;

use App\Domain\Monitoring\Heartbeats\PingOutcome;
use App\Domain\Monitoring\Heartbeats\RecordHeartbeatPing;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

/**
 * Public ping endpoint of heartbeat monitors: no session, no cookies, no
 * CSRF; the request body is never read. Unknown tokens get a plain 404,
 * paused monitors a 200 so that cron jobs do not report errors.
 */
class HeartbeatPingController extends Controller
{
    public function __construct(private readonly RecordHeartbeatPing $recordHeartbeatPing) {}

    public function __invoke(string $token): Response
    {
        return $this->respond($this->recordHeartbeatPing->handle($token));
    }

    public function fail(string $token): Response
    {
        return $this->respond($this->recordHeartbeatPing->handle($token, failed: true));
    }

    private function respond(PingOutcome $outcome): Response
    {
        $found = $outcome !== PingOutcome::NotFound;

        return response($found ? "OK\n" : "Not found\n", $found ? 200 : 404)
            ->header('Content-Type', 'text/plain; charset=UTF-8')
            ->header('Cache-Control', 'no-store')
            ->header('X-Robots-Tag', 'noindex');
    }
}
