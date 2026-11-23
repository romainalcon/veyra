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
- Expected statuses default to HTTP 2xx; methods GET, HEAD, POST, PUT, PATCH (body only with the last three, ADR-037).
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
- **Error codes**: `invalid_url`, `forbidden_destination`, `dns_failure`, `connection_failed`, `timeout`, `tls_error`, `too_many_redirects`, `response_too_large`, `unexpected_status`, `keyword_missing`, `keyword_found`, `body_unreadable` (Sprint 15), `unexpected_error`.
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
- **Channels**: e-mail (≤ 10 recipients), Discord webhooks, Slack incoming webhooks (ADR-034), signed outgoing webhooks (ADR-033), ntfy, Gotify, Telegram, Microsoft Teams workflows and PagerDuty Events v2 (ADR-039; PagerDuty gets no reminders and resolves its alert with the incident), defined per organization and selected per monitor (`is_default` channels are pre-selected for new monitors).
- **Events**: incident opened, resolved, acknowledged, plus one reminder when an incident is still open and unacknowledged after `VEYRA_REMINDER_AFTER_MINUTES` (default 30, `veyra:incidents:remind` every 5 min).
- **Delivery**: `QueueIncidentNotifications` inserts one `notification_deliveries` row per (incident, channel, event) — unique, so replays never send twice — and `SendNotificationDelivery` (queue `notifications`, unique per delivery) sends it. Transient failures (5xx, timeouts, 429 with `Retry-After`) are retried up to 5 times (30 s, 2 min, 10 min, 30 min, 1 h); permanent failures (other 4xx, invalid config) are marked `failed` immediately. History is shown per channel and per incident.
- **Outgoing webhooks** (ADR-033): a JSON `POST` per delivery, signed with Standard Webhooks headers (`webhook-id` = delivery id, stable across retries; `webhook-timestamp`; `webhook-signature: v1,<base64 HMAC-SHA256(id.timestamp.body)>`). Payload `version: 1`:
  `{"version":1,"id":"…","event":"incident.opened","occurred_at":"2026-11-22T10:15:00Z","organization":{"id","slug","name"},"monitor":{"id","name","type"}|null,"incident":{"id","started_at","resolved_at","acknowledged_at","acknowledged_by","duration_seconds","cause"}|null,"certificate":{"expires_at","issuer"}|null,"domain":{"name","expires_at"}|null,"title":"…","summary":"…","url":"…"}`.
  Events: `incident.opened`, `incident.acknowledged`, `incident.reminder`, `incident.escalated` (with `"escalation":{"step":n}`, `null` for other events), `incident.resolved`, `certificate.expiring`, `certificate.expired`, `domain.expiring`, `domain.expired`, `test`. `title`/`summary` are in the organization language; every other field is stable. 2xx = sent; 408/429/5xx/timeouts/DNS failures are retried; redirects, other statuses and rejected destinations fail permanently.
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

## Recurring maintenance (Sprint 17, ADR-041)
- Daily, weekly (chosen days) or monthly (day 1–31, last day for shorter months) series with a local start time and a duration, in the organization time zone (Settings → Time zone).
- Occurrences are created 14 days ahead as ordinary windows by `veyra:maintenance:materialize` (hourly, `onOneServer`) and on every save; generation is idempotent and only creates future starts.
- Daylight saving time: the start keeps its local wall-clock time; a start inside the spring-forward gap moves forward by the gap; a start in the fall-back overlap takes the first instant; durations are real time.
- Editing a series or the organization time zone regenerates future occurrences only; running and past ones are never altered; skipped occurrences stay skipped. Deleting a series removes its future occurrences and keeps history.
- An occurrence is skipped (cancelled) or ended early from the maintenance list; it cannot be edited on its own. Status pages show only the next or running occurrence of each series.

## Status pages (Sprint 6)
- Public status = confirmed state: `UP` → operational, `DEGRADED` → degraded, `DOWN` → outage, `MAINTENANCE` → maintenance, `UNKNOWN` or paused → no data. The overall status is the worst among components with data (no data only if none has any).
- Incidents of listed monitors appear automatically (open, or resolved within 14 days) with a generic title; only `opened`, `public_update` and `resolved` events are published. Maintenance windows of listed monitors appear when running or starting within 7 days.

## Monitor types (Sprint 7)
- `http`, `tcp`, `heartbeat` share the outbox, check history, state machine and incidents (ADR-024). `ExecuteProbe` resolves the probe of the monitor's type through `ProbeRegistry`; a type without a probe or a monitor without its configuration is `skipped` (no check).
- Thresholds per type: HTTP/TCP 3 failures → DOWN, 2 successes → UP; DNS the same by default (`VEYRA_DNS_DOWN_AFTER_FAILURES`, `VEYRA_DNS_UP_AFTER_SUCCESSES`); heartbeat 1 missed ping → DOWN, 1 ping → UP (`VEYRA_HEARTBEAT_DOWN_AFTER_MISSES`, `VEYRA_HEARTBEAT_UP_AFTER_PINGS`).
- **TCP** (`TcpProbe`): `DestinationGuard::inspectEndpoint` validates the host (`HostPolicy`, shared with URLs), the port against `VEYRA_PROBES_TCP_ALLOWED_PORTS` and every resolved address before each check; `StreamTcpConnector` opens a plain TCP connection to the validated IP within the monitor timeout and closes it at once. Success = handshake completed; failures are `connection_failed`, `timeout`, `dns_failure`, `forbidden_destination` or `invalid_url` (invalid host). Checks have no status code; `resolved_ip` is kept in metadata.
- The type is chosen on creation and is immutable.
- **Heartbeat**: jobs call `/ping/{token}` (GET/POST/HEAD) or `/ping/{token}/fail`. Each ping moves `next_check_at` to `ping + period + grace`; a check is recorded (dispatch `trigger = ping`) for the first successful ping of a period, the first ping after a failure, and every failure signal. One failure → DOWN, one ping → UP. Paused monitors acknowledge and ignore pings; "Check now" is not available. When the deadline is due, `HeartbeatProbe` records `heartbeat_missed` only if `scheduler_beats` shows the scheduler alive during the whole window (no gap > 180 s); otherwise nothing is recorded (UNKNOWN through stale detection, `2 × period + grace + 120 s`). Missed deadlines repeat every period while no ping arrives.
- **DNS** (`DnsProbe`, ADR-042): queries one record (A, AAAA, CNAME, MX, TXT, NS) of a name through the system resolver (name servers of the worker's `/etc/resolv.conf`, at most 3) or a fixed public resolver (Cloudflare, Google, Quad9; `VEYRA_DNS_RESOLVERS` narrows the list). `SocketDnsQueryClient` sends UDP with EDNS0 (1232 bytes), falls back to TCP for truncated answers (≤ 16 KiB), ignores datagrams whose ID or question differ and stops at the monitor timeout (≤ 30 s) across servers; SERVFAIL/REFUSED moves to the next server. Answers of the queried type only, at most 50, TXT at most 1024 bytes. Expected values are optional: `contains` = every expected value is among the answers (a TXT value may be part of an answer; an MX value without preference matches any preference), `exact` = same set. Results: success, `dns_mismatch` (answers differ), `dns_no_answer` (NOERROR without record), `dns_nxdomain`, `dns_failure` (no usable answer) or `timeout`. Metadata: `resolver`, `rcode`, `answers` (count); the last answers of the newest slot are kept on `monitor_dns_configs` and shown on the detail page (non-public addresses from the system resolver are redacted). A resolver disabled by the operator after saving skips the dispatch (UNKNOWN).
- **Domain expiry** (HTTP and DNS monitors, ADR-043): `veyra:domains:check` (hourly, one server) queues `CheckDomainExpiriesJob` on `maintenance`. It refreshes `monitor_domains` through RDAP at most once a day per monitor (one lookup per host and run, results cached 23 h, 200 lookups and 240 s per run), keeps the last known expiry when a lookup fails and records the reason, then raises warnings at the certificate thresholds (`VEYRA_CERTIFICATE_WARNING_DAYS`) once per (monitor, domain, expiry date, threshold): `domain_expiring` / `domain_expired`, never an incident. IP targets, TCP monitors and heartbeats have no domain. `VEYRA_DOMAIN_EXPIRY_ENABLED=false` turns it off.
- **TLS certificates** (HTTPS monitors, ADR-026): the probe captures the leaf certificate of the monitored host on the first HTTPS hop (`CURLOPT_CERTINFO`, never for redirect targets on other hosts); parse failures are ignored. `monitor_tls_certificates` is refreshed when the fingerprint changes or hourly. `veyra:certificates:check` (hourly) raises the most urgent threshold reached among `VEYRA_CERTIFICATE_WARNING_DAYS` (default `14,7,1`) or "expired", once per certificate and threshold, for enabled HTTP monitors, and notifies their enabled channels (`certificate_expiring` / `certificate_expired`). Expiry never opens an incident: an expired certificate already fails the TLS-verified check.

## Advanced HTTP checks (Sprint 15, ADR-037)
- **Methods and body**: POST, PUT and PATCH may send a body of at most 16 KiB with a content type from a closed list (`application/json` — validated as JSON —, `application/x-www-form-urlencoded`, `application/xml`, `text/plain`). A `Content-Type` custom header is refused when a body is set. GET and HEAD never send one; switching a monitor to them drops the stored body.
- **Redirects**: 303, and 301/302 after a method with a body, continue as GET without body (HEAD stays HEAD); 307/308 keep the method and body on the monitored host. On a hop to another host, custom headers and basic auth are dropped as before and a body request becomes a plain GET without body.
- **Basic auth**: user name and password, sent as `Authorization: Basic …` to the monitored host only; it cannot be combined with an `Authorization` custom header.
- **Content assertion**: an optional keyword (≤ 255 characters) that the body must contain (`contains`) or must not contain (`not_contains`), case-insensitive by default (UTF-8 aware). It is evaluated only when the status is expected, on the body already downloaded under the 1 MiB cap. The probe sends `Accept-Encoding: identity` unless a custom header sets it; gzip/deflate answers are decoded under the same cap, other encodings or corrupt/oversized bodies fail with `body_unreadable`, never with a pass. Not available with HEAD. Failures are `keyword_missing` / `keyword_found`; neither the keyword nor the body is stored in the check.
- **Latency threshold**: optional, 100 ms to just below the timeout. A successful check slower than it is stored with `monitor_checks.slow = true` (still `success = true`: uptime, DOWN logic and incidents ignore it). In the state machine slow checks are successes; where the result would be UP, `VEYRA_DOWN_AFTER_FAILURES` (3) slow checks in a row give DEGRADED, and leaving that DEGRADED needs `VEYRA_UP_AFTER_SUCCESSES` (2) fast checks in a row. An incident is resolved by the usual success streak even if those checks are slow. DEGRADED never opens an incident; status pages show it as "degraded".

## Escalation policies (Sprint 20, ADR-045)
- A policy is an ordered list of 1–5 steps; each step has a delay (1–1440 min, strictly increasing) and 1–10 channels of the organization. A monitor uses its own policy (`monitors.escalation_policy_id`) or, when it has none, the organization default (`is_default`, at most one). Policies are managed by Owners and Admins on the notifications page; monitors pick one in their form or through the API.
- Steps add to the monitor's normal notifications: the opening alert still goes to the monitor's channels; a step sends an `escalated` notification to its own channels, whether or not they were already notified.
- `veyra:incidents:escalate` (every minute, one server) fires a step once the incident has been open for its delay (measured from `started_at`, like reminders) **only while** the incident is open and unacknowledged and the monitor is confirmed `DOWN`. Maintenance, missing data (`UNKNOWN`, which includes paused monitors) postpone the step; acknowledgement and recovery stop the chain. Every step already due fires in the same run, in order, with an `escalated` timeline event.
- Idempotency: the incident row is locked and re-checked, `incidents.escalation_step` (last step fired) only moves forward, and the delivery key `(incident, channel, event, escalation_step)` rejects duplicates. Disabled channels and channels of another organization are skipped; the step still counts as fired.
- PagerDuty treats an escalation as a trigger of the incident's alert (same dedup key, no duplicate page).

## Weekly report (Sprint 20)
- Opt-in per member (`organization_members.weekly_report`), from the organization settings page; only verified addresses receive it.
- `veyra:reports:weekly` (hourly, one server) queues one report per organization with an opted-in member and a monitor, from Monday 08:00 in the organization time zone, covering the previous Monday 00:00 → Monday 00:00 local (167 or 169 hours across daylight saving changes). `weekly_reports (organization_id, period_start)` is unique, so each week is reported once; a scheduler outage catches up later in the week.
- `SendWeeklyReport` (queue `notifications`) builds the report and queues one e-mail per recipient; `weekly_reports.queued_at` is set in the same transaction, so a retry after that point sends nothing again.
- Content, in the organization language, dates in its time zone: uptime and coverage over raw checks of the exact window (maintenance excluded; no checks = "No data", never 100 %), the 10 monitors below 100 %, the 5 slowest monitors (average response time of successful checks), incidents overlapping the week with downtime clipped to it. Monitor names are escaped for the Markdown tables. With a check retention shorter than a week, coverage shows the week as partial.

## Check diagnostics (Sprint 23, ADR-050)
- Every HTTP check records its phases in milliseconds: **DNS** (the probe's own validated resolution, since the connection is pinned with `CURLOPT_RESOLVE` and cURL never resolves), **connect**, **TLS** (HTTPS only), **time to first byte** and **transfer** from the cURL transfer info of the final hop, and **redirects** (time spent on earlier hops). A target given as an IP address has no DNS phase; phases a failing check never reached are null.
- TCP checks record DNS and connect time (connect is null when the connection failed); DNS checks record the query time as DNS. Heartbeats and checks recorded before 1.11 have no timings. The total `duration_ms` is unchanged and remains the latency used for thresholds, uptime and analytics.
- The monitor page shows a compact bar per check with a detailed waterfall on demand, the waterfall of the latest check, and a latency chart that can be stacked by phase (averages over successful checks, from raw checks for 24 h and 7 d, from daily aggregates beyond).
- **Failure evidence**: a failing HTTP check that got a response (unexpected status, keyword assertion, unreadable body, too many redirects) carries the status, the HTTP version, an allow-list of response headers and an excerpt of at most 4 KiB of a text body (decoded from gzip or deflate under the existing 1 MiB response cap). It is stored with the check while the monitor has no active incident; when an incident opens, the evidence of the first failing check of the confirming streak (or the next one, if the first got no response, e.g. a timeout) is attached to it and the rest is discarded. Timeouts, DNS, connection, TLS and destination failures have no evidence: the incident cause is the explanation.
- Evidence is shown only on the incident page to members of the organization; it never appears in the API, notifications, webhooks, status pages, broadcasts or exports. It is deleted with its incident; unattached evidence is purged after 24 hours.
