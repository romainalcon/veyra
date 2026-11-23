# Changelog

All notable changes to Veyra are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- **Check timings** — every HTTP check records where its time went: DNS, connection, TLS, time to first byte, transfer and redirects; TCP checks record DNS and connect time, DNS checks their query time. The monitor page shows a waterfall per check and for the latest check, and the latency chart can be stacked by phase. Checks recorded before this version and heartbeats have no breakdown. The API returns `timings` on checks (ADR-050).
- **Failure evidence** — when an incident opens, the incident page shows what the server answered on the first failing check: status, a fixed list of safe response headers and up to 4 KiB of a text body. Cookies, credentials and other headers are never kept, binary bodies are skipped, and the evidence never appears in the API, notifications, webhooks or status pages. It is deleted with the incident (ADR-050).

## [1.10.0] — 2026-11-23

### Added
- **Changelog and roadmap pages** — with `VEYRA_LANDING_PAGE=true`, `/changelog` lists every release of `CHANGELOG.md` (newest first, with a link per version) and `/roadmap` shows the upcoming milestones and the latest release, in English or French; both are linked from the landing page header and the site footers. Release notes stay in English. Without the landing page, both pages are not found and nothing is linked (ADR-049).

## [1.9.0] — 2026-11-23

### Added
- **Prometheus metrics** — `GET /metrics` exposes the health of the instance: Redis queue sizes, outbox backlog and age, failed dispatches, scheduler heartbeat age, Horizon supervisors and failed jobs, monitors by state, probe durations (histogram by type and result) and notification deliveries (by channel type, event and result). Disabled by default; enable it with `VEYRA_METRICS_ENABLED` and protect it with `VEYRA_METRICS_TOKEN` and/or `VEYRA_METRICS_ALLOWED_IPS` (IPs or CIDR ranges). Metrics never contain organization, monitor, URL or user data (ADR-048).
- An example Grafana dashboard and Prometheus alerting rules in `docs/operations/` (`METRICS.md`, `grafana-dashboard.json`, `prometheus-alerts.yml`).
- `veyra:doctor` checks the metrics configuration.

## [1.8.0] — 2026-11-23

### Added
- **Escalation policies** — ordered steps (a delay and a set of channels) notify more people while an incident stays open, unacknowledged and confirmed down. Acknowledging or recovering stops the escalation; maintenance and missing data postpone it. Each monitor picks a policy or uses the organization default; Owners and Admins manage policies on the notifications page. Webhooks receive `incident.escalated`; PagerDuty re-triggers the same alert (ADR-045).
- **Weekly report e-mail** — every member can opt in from the organization settings to receive, on Monday morning in the organization time zone and language, last week's uptime with its coverage, the monitors below 100 %, the slowest monitors and the incidents with their downtime.
- The API reads and writes `escalation_policy_id` on monitors.
- **Audit log** — Owners and Admins see who changed what under **Audit log**: monitors, notification channels, escalation policies, status pages, members, invitations, organization settings and API tokens, with the member, API token or system that did it and the changed fields (never secrets: only whether headers, bodies, credentials or webhook URLs were replaced). Entries cannot be edited and are kept for `VEYRA_AUDIT_RETENTION_DAYS` days (365 by default) (ADR-046).
- **Monitor tags** — up to 10 tags per monitor to filter the monitor list, the dashboard and the API (`?tag=`, `tags` field) (ADR-047).
- **Monitor import and export** — export the monitors (or one tag) to a versioned JSON file without secrets, and import such a file into any organization: a preview checks every monitor with the form's rules (destinations, ports, limits), skips the ones that already exist, and nothing is created unless every monitor is valid. Imports are limited to 100 monitors per file and `VEYRA_MONITOR_IMPORTS_PER_HOUR` (10) requests per member and hour (ADR-047).

## [1.7.0] — 2026-11-23

### Added
- **Public REST API** (`/api/v1`) — list, read, create, replace, delete, pause and resume monitors; read their checks; list, read and acknowledge incidents; schedule, replace, cancel and end maintenance windows. Requests are JSON, lists use cursor pagination, and validation, quotas and destination (SSRF) rules are exactly those of the interface. The reference is `docs/api/openapi.yaml` (OpenAPI 3.1), with examples in `docs/api/README.md` (ADR-044).
- **Organization API tokens** — Owners and Admins create tokens under **API tokens**: read-only (acts as a Viewer) or read and write (acts as a Member), optionally expiring after 30, 90 or 365 days, revocable at once, with their last use shown. A token belongs to the organization, so it keeps working when the member who created it leaves. It is shown once and stored as a hash only.
- Incident timelines name the API token that acknowledged an incident.
- Rate limits for the API: 120 requests per minute per token and 600 per IP (`VEYRA_API_REQUESTS_PER_MINUTE`, `VEYRA_API_REQUESTS_PER_MINUTE_PER_IP`); monitor creation and write limits apply per token.

## [1.6.0] — 2026-11-22

### Added
- **Recurring maintenance** — maintenance windows that repeat daily, weekly (on chosen days) or monthly (a day past the end of a month means its last day), at a local start time, for a duration up to `VEYRA_MAINTENANCE_MAX_HOURS`, from a first day and until an optional last day. Occurrences are created 14 days ahead as ordinary maintenance windows, so they behave exactly like one-off windows (ADR-041).
- Daylight saving time is handled: occurrences keep their local start time; a start that falls in the spring-forward gap moves forward, one in the fall-back overlap takes the first instant.
- Editing a series only changes its future occurrences; running and past ones are never altered. A single occurrence can be skipped or ended early. Deleting a series removes its future occurrences and keeps the history.
- Status pages show the next (or running) occurrence of each series, marked "Recurring"; subscribers are told about a new series once, not about every occurrence.
- **Organization time zone** — Owners and Admins choose the time zone used by recurring maintenance in the organization settings (default UTC).
- **DNS monitors** — a new monitor type checks an A, AAAA, CNAME, MX, TXT or NS record, optionally against expected values (contains or exact match), with a timeout up to 30 s. Results tell a missing domain, an empty answer, an unexpected answer and a resolver failure apart, and the detail page shows the last answers (ADR-042).
- DNS queries go to the server's resolver or to Cloudflare, Google or Quad9 only, never to an arbitrary address (`VEYRA_DNS_RESOLVERS` narrows the list). Record names that are IP addresses or local names are refused. DNS monitors have their own confirmation thresholds (`VEYRA_DNS_DOWN_AFTER_FAILURES` / `VEYRA_DNS_UP_AFTER_SUCCESSES`, default 3/2).
- **Domain expiry** — HTTP and DNS monitors follow the registration expiry of their domain through RDAP, at most once a day, and warn at the certificate thresholds (`VEYRA_CERTIFICATE_WARNING_DAYS`), once per expiry date, like certificates. Webhooks receive `domain.expiring` and `domain.expired` events. Set `VEYRA_DOMAIN_EXPIRY_ENABLED=false` to turn it off (ADR-043).

## [1.5.0] — 2026-11-22

1.4 was not released on its own: this release includes the planned 1.4 work (visual identity, status page audience) and the 1.5 work (advanced HTTP checks, more channels, two-factor authentication).

### Added
- **ntfy and Gotify** — push notifications to ntfy.sh or your own ntfy or Gotify server, with an optional access token. Server addresses follow the same network safety rules as monitored URLs (ADR-039).
- **Telegram** — a bot posts incidents, certificate warnings and tests to a chat, group or channel.
- **Microsoft Teams** — notifications as Adaptive Cards through a Teams workflow ("When a Teams webhook request is received").
- **PagerDuty** — incidents trigger, acknowledge and resolve a single PagerDuty alert (Events API v2); reminders are left to PagerDuty's own escalations.
- **Two-factor authentication** — every user can protect their account with an authenticator app and recovery codes. Owners can require it for their organization: members without it are asked to enable it before they can open the organization (ADR-040).
- **POST, PUT and PATCH checks** — HTTP monitors can send a request body (up to 16 KiB, JSON validated) and basic auth credentials. Both are encrypted and never shown again after saving; they are only sent to the monitored host, never to a redirect target on another host (ADR-037).
- **Content checks** — a keyword the response must contain, or must not contain, with a case-sensitivity option. Failures are reported as "Expected keyword not found" or "Forbidden keyword found".
- **Latency threshold** — responses slower than the threshold are marked "Slow"; three in a row make the monitor degraded (never down), two fast responses bring it back up.
- **Status page branding** — a logo, a favicon and an accent colour per status page. Images are checked and size-limited (no SVG). The accent colour is only used for text where it stays readable in light and dark themes (ADR-036).
- **E-mail subscriptions** — visitors can subscribe to a status page by e-mail, once enabled in the page settings. They confirm their address first, then receive incident openings, public updates, resolutions and planned maintenance in the organization language. Every e-mail has a one-click unsubscribe link. Addresses are encrypted and never shown: page managers only see counts.
- **Atom and RSS feeds** of public incidents and maintenance for every published page.
- **SVG badges** — current status and 24 h / 7 d / 30 d uptime per published component, ready to embed in a README or a website.
- Feeds, badges, branding images and the subscription form also work on verified custom domains.
- Organization limits for status page subscribers and branding (`Entitlements`). The Community edition limits nothing.

### Changed
- **Visual identity** — Veyra has its own look: an indigo accent on cool slate neutrals, Inter and JetBrains Mono, a new pulse mark and favicon, in light and dark themes. The application, landing page, sign-in pages and e-mails use it; public status pages stay neutral so they suit your own brand (ADR-035).
- Statuses (up, degraded, down, maintenance, unknown) have their own accessible colours, each paired with an icon and a label so they never rely on colour alone; every text colour meets WCAG AA contrast.
- Empty states and loading placeholders are consistent across pages.
- **Landing page** — redesigned with a product preview, a "how it works" section and a feature grid. It now carries search and link-preview metadata (description, Open Graph, X/Twitter card) with a share image in English or French, rendered on the server so social networks and chat apps can read it.

## [1.3.0] — 2026-11-22

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
- **Slack** — a new notification channel type posts incidents, certificate warnings and tests to a Slack channel through an incoming webhook, with colours, details and a link back to Veyra, in the organization language. Monitor names can never mention `@channel` or anyone else (ADR-034).

### Changed
- Dates, percentages and numbers are formatted according to the active language.
- Raw-check retention is resolved per organization (still `VEYRA_CHECK_RETENTION_DAYS` on the Community edition). No migration.

### Fixed
- Deleting an account now purges the monitoring history of the organizations deleted with it (raw checks were previously left behind).
### Removed
- The deprecated `ExecuteHttpProbe` job alias, announced in 1.2. Probe jobs are `ExecuteProbe` only. See `UPGRADING.md` if you upgrade straight from 1.1.

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

[Unreleased]: https://github.com/romainalcon/veyra/compare/v1.10.0...HEAD
[1.10.0]: https://github.com/romainalcon/veyra/compare/v1.9.0...v1.10.0
[1.9.0]: https://github.com/romainalcon/veyra/compare/v1.8.0...v1.9.0
[1.8.0]: https://github.com/romainalcon/veyra/compare/v1.7.0...v1.8.0
[1.7.0]: https://github.com/romainalcon/veyra/compare/v1.6.0...v1.7.0
[1.6.0]: https://github.com/romainalcon/veyra/compare/v1.5.0...v1.6.0
[1.5.0]: https://github.com/romainalcon/veyra/compare/v1.3.0...v1.5.0
[1.3.0]: https://github.com/romainalcon/veyra/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/romainalcon/veyra/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/romainalcon/veyra/compare/v1.0.2...v1.1.0
[1.0.2]: https://github.com/romainalcon/veyra/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/romainalcon/veyra/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/romainalcon/veyra/releases/tag/v1.0.0
