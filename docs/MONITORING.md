# Veyra — Monitoring engine

## Scheduling
1. Scheduler runs periodically (target every 10 seconds using Laravel sub-minute scheduling).
2. Claim due monitors in a MariaDB transaction using row locks and a bounded batch; validate MariaDB locking semantics in integration tests.
3. Advance `next_check_at` and insert unique `monitor_dispatches` outbox rows in the same transaction.
4. Outbox dispatcher enqueues `ExecuteProbe` jobs to Redis and marks dispatch status; retries on failures.
5. Horizon workers execute probes and persist results idempotently.
6. State transition logic updates monitor state and incidents transactionally; broadcasts and notifications after commit.

## Defaults
- Intervals: 60, 120, 300, 600, 900, 1800, 3600 seconds.
- HTTP timeout 10 seconds (maximum 30); up to 3 redirects.
- Expected statuses default to HTTP 2xx; GET/HEAD only for V1.
- Confirm DOWN after 3 consecutive failed probes; UP after 2 successful probes.
- Raw check retention: 30 days by default; daily aggregates kept separately.

## State model
- `UP`, `DOWN`, `DEGRADED`, `UNKNOWN`, `MAINTENANCE` (planned, Sprint 6).
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

## Implementation (Sprint 2)
- **Outbox**: `veyra:monitors:dispatch` runs every 10 s (`withoutOverlapping`, `onOneServer`).
  1. `ScheduleDueMonitors` claims up to 500 due monitors with `SELECT … FOR UPDATE SKIP LOCKED`, inserts one `monitor_dispatches` row per slot (`insertOrIgnore` on unique `(monitor_id, scheduled_for)`) and advances `next_check_at` in the same transaction. After an outage, missed slots are skipped (`next = now + interval`), never replayed in bulk.
  2. `PublishPendingDispatches` pushes `ExecuteProbe` to `probes` inside the transaction (a Redis failure rolls back and keeps rows `pending`). Rows `dispatched` for more than 5 min are redelivered; after 3 attempts they become `failed`; rows older than `max(interval, 5 min)` become `skipped`. No check is written for skipped/failed dispatches.
- **Job**: `ExecuteProbe` (1 try, 45 s timeout) skips paused/deleted monitors; `RecordProbeResult` inserts the check with a unique `dispatch_id`, so replays are no-ops.
- **Manual checks** ("Check now") use the same outbox with `trigger = manual`.
- **Probe**: see `app/Domain/Monitoring/Probes/HttpProbe.php`. Every hop is validated by `DestinationGuard`, the connection is pinned with `CURLOPT_RESOLVE`, redirects are followed manually (max 3), environment proxies are ignored, custom headers are dropped on cross-host redirects and responses are capped at 1 MiB.
- **Error codes**: `invalid_url`, `forbidden_destination`, `dns_failure`, `connection_failed`, `timeout`, `tls_error`, `too_many_redirects`, `response_too_large`, `unexpected_status`, `unexpected_error`.
- The UI shows the latest **raw** check; confirmed UP/DOWN states arrive in Sprint 3.

## Confirmed state (Sprint 3)
- `ApplyMonitorStateJob` (queue `incidents`, unique per monitor until processing) runs after each recorded check. Under the `monitor_states` row lock it reads the latest checks (ordered by `scheduled_for`) and recomputes the streaks, so lost, duplicated or late jobs cannot corrupt counters.
- Ordering guard: if the newest check is not newer than `last_applied_scheduled_for`, nothing changes (late checks stay in history only).
- Transitions (thresholds `VEYRA_DOWN_AFTER_FAILURES=3`, `VEYRA_UP_AFTER_SUCCESSES=2`):

| From \ Leading streak | 1–2 failures | ≥ 3 failures | 1 success | ≥ 2 successes |
|---|---|---|---|---|
| UNKNOWN | UNKNOWN | DOWN | UNKNOWN | UP |
| UP | DEGRADED | DOWN | UP | UP |
| DEGRADED | DEGRADED | DOWN | UP | UP |
| DOWN | DOWN | DOWN | DOWN | UP |

- DEGRADED = failing but not confirmed; it never opens an incident.
- Entering DOWN opens an incident (`started_at` = first failed check of the streak); leaving DOWN for UP resolves it (`resolved_at` = first successful check of the recovery streak). `monitor_states.active_incident_id` guarantees a single active incident.
- Stale data: `veyra:monitors:detect-stale` (every minute) sets UNKNOWN when `last_checked_at` is older than `2 × interval + 120 s`. Incidents are neither opened nor resolved; an open incident gets `data_unavailable`, then `data_restored`. Streaks restart from the gap (`streak_since`), as they do on pause/resume.
- Pausing → UNKNOWN (incident stays open). Deleting → open incident resolved with `monitor_deleted`.
- Domain events for Sprint 4 notifications: `IncidentOpened`, `IncidentResolved`, `IncidentAcknowledged`, `MonitorStateChanged` (dispatched after commit).
- Real time: `OrganizationUpdate` broadcasts `monitor.checked`, `monitor.state-changed` and `incident.updated` on `private-organizations.{id}` with identifiers only; the UI reloads props over HTTP (debounced), resyncs after reconnecting and polls every 30 s whenever the socket is unavailable.

## Notifications, analytics and retention (Sprint 4)
- **Channels**: e-mail (≤ 10 recipients), Discord webhooks, Slack incoming webhooks (ADR-034) and signed outgoing webhooks (ADR-033), defined per organization and selected per monitor (`is_default` channels are pre-selected for new monitors).
- **Events**: incident opened, resolved, acknowledged, plus one reminder when an incident is still open and unacknowledged after `VEYRA_REMINDER_AFTER_MINUTES` (default 30, `veyra:incidents:remind` every 5 min).
- **Delivery**: `QueueIncidentNotifications` inserts one `notification_deliveries` row per (incident, channel, event) — unique, so replays never send twice — and `SendNotificationDelivery` (queue `notifications`, unique per delivery) sends it. Transient failures (5xx, timeouts, 429 with `Retry-After`) are retried up to 5 times (30 s, 2 min, 10 min, 30 min, 1 h); permanent failures (other 4xx, invalid config) are marked `failed` immediately. History is shown per channel and per incident.
- **Outgoing webhooks** (ADR-033): a JSON `POST` per delivery, signed with Standard Webhooks headers (`webhook-id` = delivery id, stable across retries; `webhook-timestamp`; `webhook-signature: v1,<base64 HMAC-SHA256(id.timestamp.body)>`). Payload `version: 1`:
  `{"version":1,"id":"…","event":"incident.opened","occurred_at":"2026-11-22T10:15:00Z","organization":{"id","slug","name"},"monitor":{"id","name","type"}|null,"incident":{"id","started_at","resolved_at","acknowledged_at","acknowledged_by","duration_seconds","cause"}|null,"certificate":{"expires_at","issuer"}|null,"title":"…","summary":"…","url":"…"}`.
  Events: `incident.opened`, `incident.acknowledged`, `incident.reminder`, `incident.resolved`, `certificate.expiring`, `certificate.expired`, `test`. `title`/`summary` are in the organization language; every other field is stable. 2xx = sent; 408/429/5xx/timeouts/DNS failures are retried; redirects, other statuses and rejected destinations fail permanently.
- **Aggregates**: `veyra:stats:aggregate` (every 10 min, queue `maintenance`) upserts today's and yesterday's `monitor_daily_stats` from raw checks.
- **Uptime** = successful / observed checks. **Coverage** = observed / expected checks (from the interval and the monitor's age). Periods without checks are "No data" (never 100 % or 0 %); coverage under 90 % is flagged as partial.
- **Series** are bounded: 24 h = 96 × 15 min buckets, 7 d = 168 hourly buckets (raw checks), 30/90 d = daily aggregates.
- **Retention**: `veyra:history:purge` (03:15 UTC) deletes raw checks older than `VEYRA_CHECK_RETENTION_DAYS` (default 30) per monitor in batches of 1000 with a time budget, finished dispatches older than 7 days and deliveries older than 90 days. Daily aggregates are kept.

## Planned maintenance (Sprint 6)
- One-off windows per organization, covering one or more monitors over `[starts_at, ends_at)` (UTC, max `VEYRA_MAINTENANCE_MAX_HOURS`, default 168 h). Members and above manage them; a started window keeps its start, an ended or cancelled one is read-only; only future windows can be cancelled, a running one is ended early ("End now").
- Probes keep running. `RecordProbeResult` flags a check `in_maintenance` when a window covers its `scheduled_for` slot (deterministic on replay).
- `ApplyMonitorState`: a flagged newest check (or a running window) holds the state at `MAINTENANCE` — no incident is opened or resolved, streaks are zero, `last_applied_scheduled_for` still advances. The first unflagged result after a window restarts the streaks from itself and is evaluated from `UNKNOWN`.
- `veyra:maintenance:transition` (every minute, `onOneServer`) moves monitors into `MAINTENANCE` when a window starts and back to `UNKNOWN` (`streak_since` = window end) when it ends, under the `monitor_states` row lock; it is idempotent.
- An incident open before the window stays open (`maintenance_started` / `maintenance_ended` timeline events), gets no reminder while in maintenance and resolves normally afterwards.
- Stale detection ignores monitors in `MAINTENANCE`.
- Statistics: flagged checks are excluded from uptime, latency and observed checks, counted in `monitor_daily_stats.maintenance_count` and removed from the expected checks (coverage). Chart series expose a `maintenance` count per bucket.

## Status pages (Sprint 6)
- Public status = confirmed state: `UP` → operational, `DEGRADED` → degraded, `DOWN` → outage, `MAINTENANCE` → maintenance, `UNKNOWN` or paused → no data. The overall status is the worst among components with data (no data only if none has any).
- Incidents of listed monitors appear automatically (open, or resolved within 14 days) with a generic title; only `opened`, `public_update` and `resolved` events are published. Maintenance windows of listed monitors appear when running or starting within 7 days.

## Monitor types (Sprint 7)
- `http`, `tcp`, `heartbeat` share the outbox, check history, state machine and incidents (ADR-024). `ExecuteProbe` resolves the probe of the monitor's type through `ProbeRegistry`; a type without a probe or a monitor without its configuration is `skipped` (no check).
- Thresholds per type: HTTP/TCP 3 failures → DOWN, 2 successes → UP; heartbeat 1 missed ping → DOWN, 1 ping → UP (`VEYRA_HEARTBEAT_DOWN_AFTER_MISSES`, `VEYRA_HEARTBEAT_UP_AFTER_PINGS`).
- **TCP** (`TcpProbe`): `DestinationGuard::inspectEndpoint` validates the host (`HostPolicy`, shared with URLs), the port against `VEYRA_PROBES_TCP_ALLOWED_PORTS` and every resolved address before each check; `StreamTcpConnector` opens a plain TCP connection to the validated IP within the monitor timeout and closes it at once. Success = handshake completed; failures are `connection_failed`, `timeout`, `dns_failure`, `forbidden_destination` or `invalid_url` (invalid host). Checks have no status code; `resolved_ip` is kept in metadata.
- The type is chosen on creation and is immutable.
- **Heartbeat**: jobs call `/ping/{token}` (GET/POST/HEAD) or `/ping/{token}/fail`. Each ping moves `next_check_at` to `ping + period + grace`; a check is recorded (dispatch `trigger = ping`) for the first successful ping of a period, the first ping after a failure, and every failure signal. One failure → DOWN, one ping → UP. Paused monitors acknowledge and ignore pings; "Check now" is not available. When the deadline is due, `HeartbeatProbe` records `heartbeat_missed` only if `scheduler_beats` shows the scheduler alive during the whole window (no gap > 180 s); otherwise nothing is recorded (UNKNOWN through stale detection, `2 × period + grace + 120 s`). Missed deadlines repeat every period while no ping arrives.
- **TLS certificates** (HTTPS monitors, ADR-026): the probe captures the leaf certificate of the monitored host on the first HTTPS hop (`CURLOPT_CERTINFO`, never for redirect targets on other hosts); parse failures are ignored. `monitor_tls_certificates` is refreshed when the fingerprint changes or hourly. `veyra:certificates:check` (hourly) raises the most urgent threshold reached among `VEYRA_CERTIFICATE_WARNING_DAYS` (default `14,7,1`) or "expired", once per certificate and threshold, for enabled HTTP monitors, and notifies their enabled channels (`certificate_expiring` / `certificate_expired`). Expiry never opens an incident: an expired certificate already fails the TLS-verified check.
