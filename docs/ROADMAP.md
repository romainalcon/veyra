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
- [x] Heartbeat deadline evaluation through the outbox: a missed ping is recorded only when the evaluation runs on time and the scheduler was alive for the whole window; otherwise no check is written (UNKNOWN, never DOWN).
- [x] TLS certificate expiry for HTTPS monitors: certificate `notAfter`/issuer captured by the probe, warnings at 14/7/1 days and on expiry, sent to the monitor's channels once per certificate and threshold (deliveries no longer tied only to incidents) (ADR-026).
- [x] UI: type picker on creation, type-specific forms and detail pages (ping URL with copy/regenerate and curl/cron examples, last ping, certificate panel); status pages and analytics work unchanged for every type.
- [x] Docs: MONITORING, DATABASE, SECURITY, DECISIONS, CHANGELOG/UPGRADING for 1.2.

## Sprint 8 — Languages (1.3)
- [x] Organization language (`en`/`fr`) set by Owners/Admins; tenant pages, validation messages and flashes in that language; browser language outside an organization (ADR-027).
- [x] Public status pages (path and custom domain) in the organization language, snapshot cached per language.
- [x] E-mail and Discord notifications and invitations in the organization language.
- [x] Shared translation files (`lang/fr.json`, `lang/fr/*.php`) and a dependency-free `t()` helper for Vue; locale-aware dates and numbers.
- [x] App shell (navigation, menus, banners, footer) and organization settings translated.
- [x] Every application page translated (monitors, incidents, maintenance, status page management, notifications, members, authentication, profile), including enum labels, stored incident causes (translated on display) and domain validation errors.
- [x] Coverage tests: every `t()`/`__()` literal and enum label must exist in `lang/fr.json`, with the same placeholders.
- [x] Landing page on `/` for guests behind `VEYRA_LANDING_PAGE` (off by default); signed-in users keep the dashboard redirect (ADR-028).

## Sprint 9 — Entitlements (1.4)
- [x] `Entitlements` contract with a Community implementation that limits nothing; `Quota` service locking the organization row before counting (ADR-029).
- [x] Limits enforced on growth only: active monitors (creation, resume), members and pending invitations (invite, accept), status pages, custom domains, notification channels, minimum probe interval; per-organization raw-check retention.
- [x] `LimitReached` rendered as a `limit` validation error and toast; `veyra:doctor` fails a cloud edition without cloud entitlements.
- [x] UI: shared `currentOrganization.limits`, `LimitNotice` (usage, limit reached), disabled creation controls, intervals below the minimum hidden; French translations.
- [x] Tests: every enforcement point, tenant isolation, translated messages, Community non-regression, retention per organization, MariaDB lock test.

## Sprint 10a — Extension points (core)
- [x] Extension pages, translations, styles and tooling from `extensions/*` (git- and Docker-ignored); core pages and translations always win (ADR-030).
- [x] `OrganizationNavigation` registry shared as `currentOrganization.navigation` and rendered in the sidebar.
- [x] `OrganizationDeleting` event inside the deletion transaction; account deletion goes through `DeleteOrganization` (history purged, listeners run).
- [x] `RaiseMonitorIntervals` action for plan downgrades.
- [x] Tests: Community unchanged without extensions, navigation abilities and translation, deletion event and abort, account deletion purge, interval raising, page registry and dictionary merging.

## Done means
A clean install can register, create an organization and HTTP monitor, execute scheduled checks, detect confirmed downtime, notify, recover, and display history. A probe-worker outage must not masquerade as monitored-service downtime.

## Explicitly deferred
Recurring maintenance windows, DNS probes, agents, multi-region checks, billing, public API and SSO.
