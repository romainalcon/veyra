# Load baselines

Produced with `php artisan veyra:benchmark` (local/testing only). The command runs in an isolated child process against a dedicated `*_bench` database and Redis databases, seeds monitors with a 60 s interval spread over one minute, runs the real scheduler/outbox (`ScheduleDueMonitors`, `PublishPendingDispatches`) every 10 s and real `queue:work` processes on `probes` and `incidents`, with a **simulated probe** (no network, SSRF policy untouched).

| Run | Monitors | Workers | Required rate | Measured | Lag p95 (scheduled → recorded) | Scheduler pass (claim / publish) | Backlog |
|---|---|---|---|---|---|---|---|
| [baseline-2026-11-500](baseline-2026-11-500.md) | 500 | 4 | 8.3 checks/s | 7.8 checks/s (all dispatched work completed) | 9 s | 26 / 51 ms | none |
| [baseline-2026-11-2000](baseline-2026-11-2000.md) | 2 000 | 8 | 33.3 checks/s | 31.1 checks/s (all dispatched work completed) | 10 s | 82 / 168 ms | none |

## Reading the numbers
- The lag is dominated by the 10 s scheduler cadence (a check becomes due at most 10 s before it is claimed); processing itself adds well under a second.
- One worker sustains about 5.6 checks/s with a 150 ms probe (≈ 180 ms per job including persistence and the state transition), so capacity ≈ workers × 1000 / (probe latency + 30 ms). Real probes are I/O-bound: size `HORIZON_PROBES_MAX_PROCESSES` from the slowest endpoints' latency.
- Aggregating one day of 100 000 checks takes ≈ 0.3 s; purging them in batches ≈ 1.5–2 s. Partitioning (see `docs/DATABASE.md`) is not needed at this scale.
- Figures come from a single MacBook (Apple Silicon, MariaDB 11.4, Redis 8.8): use them as a relative reference, not as production sizing.

## Pitfall found while building the benchmark
Worker sub-processes must not have their output piped without being read: once the pipe buffer is full, `queue:work` blocks on writing. The command starts workers with `--quiet` and `disableOutput()`.
