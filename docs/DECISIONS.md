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
**Decision:** Community core and optional Cloud extensions share a codebase but have explicit interfaces. Exact licensing is pending.

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
