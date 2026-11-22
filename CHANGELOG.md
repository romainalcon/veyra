# Changelog

All notable changes to Veyra are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- **Organization language** — Owners and Admins choose the language of an organization (English or French) in its settings. Members see the organization in that language; pages outside an organization (sign-in, profile) follow the browser language. New organizations start in the language of their creator's browser (ADR-027).
- Public status pages, including custom domains, are shown in the organization language, whatever the visitor's browser.
- E-mail and Discord notifications and member invitations are written in the organization language.
- Complete French translation of the interface: every page, status and type label, incident cause, validation error, e-mail and notification.

- **Landing page** — with `VEYRA_LANDING_PAGE=true`, guests visiting `/` see a public presentation of Veyra (features, self-hosting quick start), in English or French. Signed-in users still go straight to their dashboard; with the flag off (default), guests are sent to the login page (ADR-028).

- **Organization limits (groundwork for hosted plans)** — an `Entitlements` contract can limit active monitors, members (with pending invitations), status pages, custom domains, notification channels, the minimum check interval and raw-check retention per organization. The Community edition limits nothing and shows nothing new. Limits only stop growth: nothing is deleted or paused when a limit is lowered (ADR-029).
- `veyra:doctor` reports which entitlements are installed and fails when `VEYRA_EDITION=cloud` runs without Cloud entitlements.

- **Extension points** — optional extensions installed from their own repositories into `extensions/<name>/` can add pages, translations and organization navigation entries, react to organization deletion (`OrganizationDeleting`) and raise monitor intervals. Nothing changes without extensions; Community images never include them (ADR-030).

- **Outgoing webhooks** — a new notification channel type POSTs a signed, versioned JSON payload for every notification (incidents, certificate warnings, tests) to your endpoint: incident tools, automation platforms or your own scripts. Deliveries use the Standard Webhooks signature scheme; the signing secret is shown once and can be regenerated. Webhook URLs get the same SSRF protections as HTTP probes (ADR-033).

### Changed
- Dates, percentages and numbers are formatted according to the active language.
- Raw-check retention is resolved per organization (still `VEYRA_CHECK_RETENTION_DAYS` on the Community edition). No migration.

### Fixed
- Deleting an account now purges the monitoring history of the organizations deleted with it (raw checks were previously left behind).

## [1.2.0] — 2026-11-21

### Added
- **Monitor types** — every monitor now has a type chosen on creation: HTTP(S) (unchanged), TCP or heartbeat. All types share the scheduler, check history, confirmed states, incidents, notifications, analytics and status pages (ADR-024).
- **TCP monitors** — host + port connection checks: every DNS answer validated, connection pinned to the validated IP, nothing sent or read, timeout up to 30 s. Ports are limited to `VEYRA_PROBES_TCP_ALLOWED_PORTS` (default `22,25,80,443,465,587,993,995,3306,5432,6379`).
- **Heartbeat monitors** — jobs call a secret ping URL (`/ping/{token}`, GET/POST/HEAD) or `/ping/{token}/fail`. Period (1 min to 1 week) and grace (1 min to 24 h); one missed ping confirms DOWN, one ping confirms UP. The URL is shown once, can be regenerated, and comes with curl/cron examples. A deadline is reported as missed only when the scheduler was demonstrably alive for the whole window; otherwise the monitor goes UNKNOWN, never DOWN (ADR-025).
- **TLS certificate expiry** — HTTPS monitors record the certificate of the monitored host (subject, issuer, validity), shown on the monitor page. The monitor's channels are warned at 14, 7 and 1 days before expiry and on expiry, once per certificate and threshold (`VEYRA_CERTIFICATE_WARNING_DAYS`). A renewed certificate starts over (ADR-026).

### Changed
- Notification deliveries can belong to a certificate alert instead of an incident; the delivery history shows both.
- Probe jobs are dispatched as `ExecuteProbe`. `ExecuteHttpProbe` remains as a deprecated alias so jobs queued by 1.1 still run after the upgrade; it will be removed in 1.3.

### Security
- Ping tokens are 40 random characters stored as SHA-256 hashes only; the ping endpoint runs without session, cookies or CSRF, never reads the body and is rate limited per token and per IP. TCP monitors apply the same destination rules as HTTP probes with their own port allow-list. Certificate capture only reads the public chain presented by the monitored host. See `docs/SECURITY.md`.

## [1.1.0] — 2026-11-21

### Added
- **Planned maintenance** — one-off maintenance windows covering one or more monitors (UTC, up to `VEYRA_MAINTENANCE_MAX_HOURS`, 168 h by default). Checks keep running and are flagged; the new `MAINTENANCE` state opens no incident, sends no notification or reminder, and flagged checks are excluded from uptime, coverage and latency. Monitors return to `UNKNOWN` after a window and are confirmed again from fresh results. Management page, monitor banner and chart marker.
- **Public status pages** — `/status/{slug}` pages showing selected monitors under public names and groups: overall status, 90-day uptime, ongoing and recent incidents, running and planned maintenance. Served without a session or cookies from a cached, whitelisted snapshot; missing data is shown as "No data", never as operational. Pages refresh automatically every minute.
- **Public incident updates** — messages published on status pages, distinct from internal incident notes.
- **Custom status page domains** — ownership proven with a `_veyra-challenge` TXT record, re-checked daily and revoked after three consecutive failures. A verified domain only serves its status page. `/status-domains/tls-check` lets a reverse proxy issue certificates on demand; Caddy example in `deploy/docker/Caddyfile.example`.

### Changed
- Release images are built natively per architecture (amd64 and arm64 runners, no QEMU emulation) and assembled into multi-arch manifests with build provenance attestations.

### Security
- Public pages never expose monitor identifiers, internal names, URLs, incident causes, internal notes or user data, and are rate limited per IP. Custom domains refuse IP literals, local suffixes and the instance's own host; the application (login, dashboards, session cookies) is never served on a custom domain. See `docs/SECURITY.md` and ADR-021 to ADR-023.

## [1.0.2] — 2026-11-21

### Fixed
- Docker Compose: removed the shared `storage` volume, whose concurrent first mount by several services could fail (`mkdir …/logs: file exists`); application containers log to stderr.
- Docker image: `php artisan tinker` failed because PsySH could not write its configuration in the `www-data` home directory.

## [1.0.1] — 2026-11-21

### Fixed
- Docker images: build the phpredis extension from its pinned, checksum-verified GitHub release instead of PECL, so builds no longer fail when pecl.php.net is unavailable (the 1.0.0 image publication failed for this reason; 1.0.0 has no published images).

## [1.0.0] — 2026-11-21

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

[Unreleased]: https://github.com/romainalcon/veyra/compare/v1.2.0...HEAD
[1.2.0]: https://github.com/romainalcon/veyra/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/romainalcon/veyra/compare/v1.0.2...v1.1.0
[1.0.2]: https://github.com/romainalcon/veyra/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/romainalcon/veyra/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/romainalcon/veyra/releases/tag/v1.0.0
