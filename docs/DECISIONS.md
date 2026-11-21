# Veyra — Architecture decision log

## ADR-001 — Name
**Decision:** Veyra replaces the working name Pulse.

## ADR-002 — Database
**Decision:** MariaDB 11.4+ replaces PostgreSQL. V1 uses ordinary indexed history tables with batched retention, not PostgreSQL-style partitions.

## ADR-003 — Probe runtime
**Decision:** All probes run in Laravel Horizon workers. No mandatory external agent, microservice or separate language runtime.

## ADR-004 — Deployment
**Decision:** Docker Compose for public Community distribution; Vito Deploy for private installations. Same application code.

## ADR-005 — Open Core
**Decision:** Community core and optional Cloud extensions share a codebase but have explicit interfaces. The Community core is licensed under **AGPL-3.0-only** (confirmed for the 1.0.0 release, source at https://github.com/romainalcon/veyra); optional Cloud modules under `extensions/cloud` may use a separate commercial license. A contribution policy (DCO or CLA) must be defined before accepting external contributions, and trademark/domain availability for "Veyra" still needs verification.

## ADR-006 — Scheduling reliability
**Decision:** MariaDB-backed atomic scheduling and outbox; idempotent Horizon jobs and ordered state transitions.

## ADR-007 — Authentication and UI foundation
**Decision:** Adapt the official Laravel Vue starter kit (Fortify headless auth, shadcn-vue on reka-ui, Wayfinder typed routes) on **Inertia 3** instead of Inertia 2. Two-factor auth, passkeys, `laravel/chisel` and `vite-plus` are removed; standard Vite 8, ESLint and Prettier are used. Fortify actions live in `app/Domain/Identity`.
**Why:** Maintained, conventional code paths for auth; Inertia 3 is the current major used by the kit (layout props, built-in HTTP client).

## ADR-008 — Tenant resolution through the URL
**Decision:** Tenant routes are prefixed `/o/{organization:slug}`. `ResolveOrganization` requires membership and answers **404** for non-members. Child resources use scoped route bindings; policies re-check organization ownership. The last visited organization is kept in session only as a redirect hint.
**Why:** Explicit tenant context, safe across browser tabs, shareable links, no hidden "current organization" state.

## ADR-009 — UUIDv7 identifiers for users
**Decision:** `users` and all organization tables use UUIDv7 primary keys stored as `CHAR(36)` (`HasUuids`). Slugs are immutable after creation.
**Why:** Consistency with business entities, no sequential ID enumeration. Binary storage only if benchmarks justify it.

## ADR-010 — Fixed roles as an enum
**Decision:** Owner/Admin/Member/Viewer are a PHP enum with capability methods, enforced by `OrganizationPolicy`. No permission package. Invariant: an organization always keeps at least one owner (owner rows locked with `SELECT … FOR UPDATE`).
**Why:** Four fixed V1 roles do not justify a dynamic permission system.

## ADR-011 — Edition boundary
**Decision:** `config('veyra.edition')` identifies the edition (`community` by default). No Cloud code or entitlement interface is introduced in Sprint 1; the first real entitlement (e.g. monitor limits) will add an explicit contract with a Community implementation, and Cloud modules will live under `extensions/cloud`.
**Why:** Avoid speculative abstractions while keeping the boundary documented.

## ADR-012 — Invitations
**Decision:** Pending invitations store only a SHA-256 hash of a 64-character random token, expire after `VEYRA_INVITATION_TTL_HOURS` (default 168), are unique per (organization, e-mail) and are deleted when accepted or revoked. Acceptance locks the row, requires a verified account with the invited e-mail. The invitation mail is queued encrypted on `notifications`. When public registration is disabled (`VEYRA_REGISTRATION_ENABLED=false`), only invited e-mails may register.

## ADR-013 — SSRF strategy and private networks
**Decision:** Validate scheme, port, every DNS answer and every redirect hop in the application (`DestinationGuard`), pin the connection to the validated IP with `CURLOPT_RESOLVE`, follow redirects manually and ignore environment proxies. Private networks are reachable only through the instance-level opt-in `VEYRA_PROBES_ALLOW_PRIVATE_NETWORKS` (forced off on Cloud); loopback, link-local and metadata endpoints are never reachable.
**Why:** Self-hosted users legitimately monitor internal services, while the default and Cloud must not allow internal scanning. Network egress filtering remains required in addition.

## ADR-014 — Scheduling outbox and catch-up rules
**Decision:** Due monitors are claimed with `SKIP LOCKED` into `monitor_dispatches` in the same transaction that advances `next_check_at`; a relay publishes rows to Redis and redelivers stale ones (3 attempts max). Missed slots are skipped, stale dispatches are marked `skipped`, and no check is recorded in either case.
**Why:** MariaDB stays the source of truth; Redis loss or worker outages cannot lose schedules or fabricate failures ("missing data is UNKNOWN, never DOWN").

## ADR-015 — Confirmed state semantics
**Decision:** States are recomputed from the latest checks under a row lock (no incremental counters). DEGRADED means 1–2 unconfirmed failures and never opens an incident; DOWN needs 3 consecutive failures, recovery 2 consecutive successes (configurable per instance). Missing data yields UNKNOWN and never opens or resolves an incident.
**Why:** Idempotent, order-safe transitions, and a worker outage can never masquerade as service downtime.

## ADR-016 — Real-time by identifiers
**Decision:** Reverb events contain only identifiers; pages reload their props over HTTP (debounced), resync after reconnection and fall back to 30 s polling while disconnected.
**Why:** Reverb stays a delivery mechanism; authorization and data shaping remain in HTTP controllers.

## ADR-017 — Idempotent notification delivery
**Decision:** Domain events create delivery rows keyed by (incident, channel, event); a unique queued job sends each row with bounded, exponential retries and distinguishes transient from permanent failures. Mail is sent synchronously inside that job (no second queue hop).
**Why:** Replayed events, duplicate jobs and worker restarts must never spam, and every delivery outcome must be visible.

## ADR-018 — Charts with shadcn-vue Charts (@unovis)
**Decision:** Add `@unovis/vue` and `@unovis/ts` (pinned to 1.8.1) with the shadcn-vue `chart` components (copied from the official registry, adapted to direct `@unovis` subpath imports) for latency and incident charts; uptime bars are plain styled elements.
**Why:** Consistent with the shadcn-vue UI and themes, without hand-writing axes, tooltips and crosshairs. The shadcn-vue CLI requires Node 22, so the registry files were reviewed and added manually.

## ADR-019 — Uptime over observed checks with explicit coverage
**Decision:** Uptime is computed only over observed checks; coverage (observed ÷ expected) is reported alongside, and periods without data are "No data".
**Why:** A worker or scheduler outage must not masquerade as downtime nor inflate uptime to 100 %.

## ADR-020 — Release operations
**Decision:** `/health` (public status, token-protected details), a scheduler heartbeat and a "monitoring paused" banner expose worker/scheduler outages explicitly; `veyra:doctor` validates production configuration; backups are logical MariaDB dumps plus the APP_KEY; images are published to GHCR on `vX.Y.Z` tags with provenance and SBOM; the Docker stack is smoke-tested and backup/restore-drilled in CI.
**Why:** The V1 acceptance requires that a probe-worker outage never masquerades as downtime and that a clean install can be operated and restored without manual knowledge.

## ADR-021 — Planned maintenance: probe and flag
**Decision:** One-off maintenance windows (UTC, `[starts_at, ends_at)`, at most `VEYRA_MAINTENANCE_MAX_HOURS`, default 168) keep probing their monitors. Checks are flagged `in_maintenance` from their scheduled slot; while the newest check is flagged (or a window is running) the confirmed state is `MAINTENANCE`, which never opens, resolves or confirms an incident and suppresses reminders. Flagged checks are excluded from uptime, coverage, expected checks and latency. When the window ends the state becomes `UNKNOWN` with streaks restarting from the window end, exactly as after a resume. `veyra:maintenance:transition` (every minute) applies entries and exits on time under the `monitor_states` row lock. A started window keeps its start; only future windows can be cancelled, running ones are ended early. Recurring windows are deferred.
**Why:** Operators see when a service comes back, history stays truthful, and planned work neither alerts nor degrades uptime; results from before a window can never confirm an outage after it.

## ADR-022 — Public status pages: whitelisted, sessionless snapshot
**Decision:** Status pages are served at `/status/{slug}` (global unique slug, reserved words refused) by a route without the `web` middleware group: no session, no cookies, no CSRF, and a dedicated Inertia middleware sharing only the instance name, version and source link. The payload is built by `StatusPageSnapshot` from a whitelist (public display names, public statuses, 90-day uptime, public incident updates, maintenance title/description) and cached 30 s; monitor identifiers, names, URLs, causes, error codes and internal notes never leave the server; incidents, updates and windows are keyed by per-page HMACs. `UNKNOWN` is shown as "No data", never "Operational"; the overall status is the worst status among components with data. Incidents appear automatically with a generic title; public updates are a distinct incident event type. The page polls every 60 s and never uses Reverb.
**Why:** A public surface must not leak tenant data or internal topology, must stay cheap under load and must not present missing data as health.

## ADR-023 — Custom status page domains
**Decision:** A status page may have one custom domain, proven by a `_veyra-challenge.<domain>` TXT record (`veyra-verification=<token>`, token renewed whenever the domain changes). `veyra:status-domains:verify` (hourly) retries pending domains for about a week and re-checks verified ones daily; three consecutive failures revoke a verified domain. A global middleware serves only the status page on `/` for a verified, published domain (all other paths 404, before any session starts); every other host, including the `APP_URL` host, IPs and `localhost`, is untouched. The instance host and its subdomains, IP literals and local suffixes are refused. TLS is delegated to the reverse proxy; `/status-domains/tls-check` is the on-demand "ask" endpoint (Caddy example for Docker, manual aliases for Vito).
**Why:** Domain ownership must be proven and re-proven (domain hand-over), the application must never be reachable through a customer-controlled host name, and certificates must not be issued for arbitrary domains pointed at the server.

## ADR-024 — Monitor types behind one probe contract
**Decision:** `monitors.type` is `http`, `tcp` or `heartbeat`. Every type uses the same outbox, `monitor_checks` history, confirmed-state machine, incidents, notifications and statistics; only the probe (`MonitorProbe`, resolved by `ProbeRegistry`) and a type-specific configuration table differ (`monitor_http_configs` today, one table per new type). The relay dispatches `ExecuteProbe`; `ExecuteHttpProbe` remains as a deprecated subclass so jobs serialized by 1.1 still run after an upgrade (removed in 1.3). A type without a probe, or a monitor without its configuration, has its dispatch `skipped` and records no check (UNKNOWN, never DOWN). Confirmation thresholds are resolved per type: HTTP/TCP use `VEYRA_DOWN_AFTER_FAILURES`/`VEYRA_UP_AFTER_SUCCESSES` (3/2); heartbeats use `VEYRA_HEARTBEAT_DOWN_AFTER_MISSES`/`VEYRA_HEARTBEAT_UP_AFTER_PINGS` (1/1), because a missed ping is only recorded once its grace period has elapsed.
**Why:** New check kinds must inherit the reliability guarantees (idempotency, ordering, "missing data is UNKNOWN") instead of re-implementing them, and an hourly cron job must not take three hours to be reported down.

## ADR-025 — Heartbeat monitors: pings through the regular check pipeline
**Decision:** A heartbeat monitor has a secret ping URL `/ping/{token}` (and `/ping/{token}/fail`), accepting GET, POST and HEAD outside the `web` middleware group (no session, cookies or CSRF, body never read), rate-limited to 30/min per token and 600/min per IP. The token is 40 random alphanumeric characters; only its SHA-256 hash is stored, the plain URL is flashed once on creation or regeneration (regeneration invalidates the previous URL). The period (`interval_seconds`, 1 min to 1 week; the column is widened to `INT UNSIGNED`) plus a grace time (1 min to 24 h) define the deadline `last ping + period + grace`, stored in `next_check_at`. A ping is recorded as a check through a `ping` dispatch and `RecordProbeResult` (same idempotency, maintenance flag and state machine) only when it carries information: first successful ping of the period (since the streak start), first ping after a failure, or a `/fail` signal (`heartbeat_failed`); other pings only move the deadline. Heartbeats confirm on one result (ADR-024). Pings of paused monitors are answered 200 and ignored; unknown tokens and deleted monitors get 404. Heartbeats cannot be checked manually. Missed deadlines are evaluated in the next lot (ADR-025 continues there).
**Why:** Pings must inherit the reliability guarantees of probes instead of a parallel state path; about one check per period keeps uptime and coverage comparable with probed monitors; a leaked database must not reveal working ping URLs, and cron jobs must never be broken by a paused monitor.
