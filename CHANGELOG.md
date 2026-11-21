# Changelog

All notable changes to Veyra are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.0.0] — unreleased

First Community release.

### Added
- **Foundations** — Laravel 13 / PHP 8.4, Vue 3 + TypeScript, Inertia 3, Tailwind CSS 4 + shadcn-vue; MariaDB 11.4+, Redis, Horizon, Reverb.
- **Accounts and organizations** — registration (can be closed), e-mail verification, password reset; organizations with Owner/Admin/Member/Viewer roles, invitations with hashed tokens, URL-scoped tenancy.
- **HTTP monitoring** — GET/HEAD monitors with intervals, timeouts, expected status ranges and encrypted custom headers; SSRF-safe probes (validated DNS answers and redirects, pinned connections, private networks only by operator opt-in); MariaDB outbox scheduler on the Horizon `probes` queue; idempotent check history.
- **States and incidents** — confirmed UP/DEGRADED/DOWN/UNKNOWN states (3 failures / 2 successes), single active incident per monitor, acknowledgement and notes; missing data is UNKNOWN, never DOWN; real-time updates over Reverb with HTTP resync.
- **Notifications** — e-mail and Discord channels selected per monitor; idempotent, retried deliveries; reminder for unacknowledged incidents.
- **Analytics and retention** — daily aggregates, uptime over observed checks with explicit coverage, latency and incident charts; batched retention of raw checks (30 days by default).
- **Operations** — `/health` endpoint, `veyra:doctor`, monitoring-paused banner, backup/restore scripts, Docker Compose distribution and Vito Deploy guide, load-test baseline.

### Security
- Tenant isolation on every route, policy and broadcast channel; secrets encrypted at rest and never sent to the browser; security headers (CSP, HSTS); rate limits on sensitive actions. See `docs/SECURITY_REVIEW.md`.
