# Veyra — Monitoring engine

## Scheduling
1. Scheduler runs periodically (target every 10 seconds using Laravel sub-minute scheduling).
2. Claim due monitors in a MariaDB transaction using row locks and a bounded batch; validate MariaDB locking semantics in integration tests.
3. Advance `next_check_at` and insert unique `monitor_dispatches` outbox rows in the same transaction.
4. Outbox dispatcher enqueues `ExecuteHttpProbe` jobs to Redis and marks dispatch status; retries on failures.
5. Horizon workers execute probes and persist results idempotently.
6. State transition logic updates monitor state and incidents transactionally; broadcasts and notifications after commit.

## Defaults
- Intervals: 60, 120, 300, 600, 900, 1800, 3600 seconds.
- HTTP timeout 10 seconds (maximum 30); up to 3 redirects.
- Expected statuses default to HTTP 2xx; GET/HEAD only for V1.
- Confirm DOWN after 3 consecutive failed probes; UP after 2 successful probes.
- Raw check retention: 30 days by default; daily aggregates kept separately.

## State model
- `UP`, `DOWN`, `DEGRADED`, `UNKNOWN`.
- Raw probe success/failure is distinct from confirmed state.
- Missing/stale data means `UNKNOWN`, never automatically `DOWN`.
- Handle delayed/out-of-order checks by a monotonic sequence or scheduled timestamp guard.
- State updates must lock the current monitor state and remain idempotent on replay.

## Probe security
- Validate scheme, hostname, DNS A/AAAA answers, all resolved addresses, port, and every redirect.
- Prevent DNS rebinding by pinning the validated destination at connection time.
- Reject private, loopback, link-local, multicast, metadata and other forbidden address ranges.
- Isolate worker egress; cap response bytes, redirects, timeout, and concurrency.

## Metrics
- Persist individual checks, update daily aggregates asynchronously.
- Calculate uptime from observed checks with explicit UNKNOWN coverage; never treat gaps as healthy.
- Provide bounded, downsampled chart series to the frontend.
