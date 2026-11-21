# Veyra — V1 roadmap and acceptance criteria

## Sprint 1 — Foundation
- [x] Laravel 13, PHP 8.4, MariaDB, Redis, Horizon, Reverb, Vue 3/Inertia 3 and Tailwind initialized.
- [x] Quality tools and CI; `.env.example` without secrets.
- [x] AppShell, responsive navigation and theme.
- [x] Users, organizations, invitations and roles with tenant isolation tests.

## Sprint 2 — HTTP monitoring
- [x] Monitor CRUD with GET/HEAD, intervals, timeouts, expected status, secure headers.
- [x] Safe HTTP probe with SSRF defenses and bounded redirects.
- [x] Atomic due-monitor scheduler, MariaDB outbox, Horizon `probes` queue.
- [x] Idempotent check persistence and history list.

## Sprint 3 — Incidents and real time
- [x] UP/DOWN/DEGRADED/UNKNOWN state machine.
- [x] Confirmation thresholds, unique active incident, recovery and acknowledgement.
- [x] Reverb status events and UI resync fallback.

## Sprint 4 — Notifications and analytics
- [x] Email/Discord channels and retry-safe delivery.
- [x] Dashboard, uptime, latency and incident charts.
- [x] Aggregation and batched retention.

## Sprint 5 — Release
- [x] MariaDB integration tests, concurrency/SSRF tests, load test baseline.
- [x] Public Docker Compose deployment and Vito Deploy guide.
- [x] Backups, restore instructions, health checks, security review.

## Sprint 5 notes
- Docker distribution validated in CI (`.github/workflows/docker.yml`): build, start, `/health`, scheduled check through Horizon and backup/restore drill passed on 2026-11-21.

## Sprint 6 — Public communication (1.1)
- [x] One-off maintenance windows: probe and flag, `MAINTENANCE` state, no incident/notification, excluded from uptime (ADR-021).
- [x] Maintenance management UI, monitor banner, chart marker.
- [x] Public status pages: sessionless whitelisted snapshot, no-data semantics, polling (ADR-022).
- [x] Public incident updates, distinct from internal notes.
- [x] Status page management UI (components, groups, order, publication).
- [x] Custom domains: TXT verification, daily re-check and revocation, host-restricted routing, on-demand TLS ask endpoint (ADR-023).
- [ ] Manual browser walkthrough and validation of the Caddy example on a real host.

## Sprint 7 — More monitor types (1.2)
- [x] Probe abstraction: `MonitorType` `http` / `tcp` / `heartbeat`, one config table per type (TCP and heartbeat tables arrive with their probes), probe job dispatched by type through the existing outbox (in-flight `ExecuteHttpProbe` jobs stay valid during upgrade), per-type confirmation thresholds (ADR-024).
- [x] TCP monitors: host + port connect check, every DNS answer validated by `AddressPolicy`, connection pinned to the validated IP, separate `VEYRA_PROBES_TCP_ALLOWED_PORTS` allow-list, timeout ≤ 30 s, no payload sent or read; type picker and TCP form.
- [x] Heartbeat monitors: secret per-monitor ping URL (`/ping/{token}`, GET/POST/HEAD, sessionless, rate-limited, token hashed at rest and regenerable), period + grace, optional `/fail` signal (ADR-025).
- [ ] Heartbeat deadline evaluation through the outbox: a missed ping is recorded only when the evaluation runs on time and the scheduler was alive for the whole window; otherwise no check is written (UNKNOWN, never DOWN).
- [ ] TLS certificate expiry for HTTPS monitors: certificate `notAfter`/issuer captured by the probe, warnings at 14/7/1 days and on expiry, sent to the monitor's channels once per certificate and threshold (deliveries no longer tied only to incidents) (ADR-026).
- [ ] UI: type picker on creation, type-specific forms and detail pages (ping URL with copy/regenerate and curl/cron examples, last ping, certificate panel); status pages and analytics work unchanged for every type.
- [ ] Docs: MONITORING, DATABASE, SECURITY, DECISIONS, CHANGELOG/UPGRADING for 1.2.

## Done means
A clean install can register, create an organization and HTTP monitor, execute scheduled checks, detect confirmed downtime, notify, recover, and display history. A probe-worker outage must not masquerade as monitored-service downtime.

## Explicitly deferred
Recurring maintenance windows, DNS probes, agents, multi-region checks, billing, public API and SSO.
