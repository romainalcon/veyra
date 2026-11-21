# Veyra — V1 roadmap and acceptance criteria

## Sprint 1 — Foundation
- [ ] Laravel 13, PHP 8.4, MariaDB, Redis, Horizon, Reverb, Vue 3/Inertia 3 and Tailwind initialized.
- [ ] Quality tools and CI; `.env.example` without secrets.
- [ ] AppShell, responsive navigation and theme.
- [ ] Users, organizations, invitations and roles with tenant isolation tests.

## Sprint 2 — HTTP monitoring
- [x] Monitor CRUD with GET/HEAD, intervals, timeouts, expected status, secure headers.
- [x] Safe HTTP probe with SSRF defenses and bounded redirects.
- [x] Atomic due-monitor scheduler, MariaDB outbox, Horizon `probes` queue.
- [x] Idempotent check persistence and history list.

## Sprint 3 — Incidents and real time
- [ ] UP/DOWN/DEGRADED/UNKNOWN state machine.
- [ ] Confirmation thresholds, unique active incident, recovery and acknowledgement.
- [ ] Reverb status events and UI resync fallback.

## Sprint 4 — Notifications and analytics
- [ ] Email/Discord channels and retry-safe delivery.
- [ ] Dashboard, uptime, latency and incident charts.
- [ ] Aggregation and batched retention.

## Sprint 5 — Release
- [ ] MariaDB integration tests, concurrency/SSRF tests, load test baseline.
- [ ] Public Docker Compose deployment and Vito Deploy guide.
- [ ] Backups, restore instructions, health checks, security review.

## Done means
A clean install can register, create an organization and HTTP monitor, execute scheduled checks, detect confirmed downtime, notify, recover, and display history. A probe-worker outage must not masquerade as monitored-service downtime.

## Explicitly deferred
Status pages, heartbeat/TCP/DNS probes, agents, multi-region checks, billing, public API and SSO.
