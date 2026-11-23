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

## Sprint 9 — Entitlements (1.3)
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
- [x] CSP extra origins for `script-src`, `frame-src` and `img-src` (empty by default).
- [x] Tests: Community unchanged without extensions, navigation abilities and translation, deletion event and abort, account deletion purge, interval raising, page registry and dictionary merging.

## Sprint 10c — User menu extension point (core)
- [x] `UserNavigation` registry shared as `auth.navigation` on every authenticated page and rendered in the user menu (ADR-030).
- [x] `administerInstance` ability for instance administrators (`VEYRA_ADMIN_EMAILS`, verified, case-insensitive); `viewHorizon` defined from it.
- [x] Shared extension icon list with `shield`; sidebar and user menu use the same mapping.
- [x] Tests: empty on Community, ability and translation inside and outside an organization, entries without ability, unknown icons, administrator rule.

## Sprint 11a — Public site links (core)
- [x] `PublicNavigation` registry (header and footer placements) shared as `publicNavigation` on every `web` page, never on public status pages (ADR-032).
- [x] Links rendered in the landing page header and footer, under authentication pages and in the application footer.
- [x] Tests: empty on Community, translated for guests and signed-in users, absent from status pages, unknown placements refused.

## Sprint 12a — Outgoing webhooks (core)
- [x] `webhook` notification channel: versioned JSON payload for every notification event, Standard Webhooks signature (`webhook-id` = delivery id, stable across retries), server-generated `whsec_` secret shown once and regenerable (ADR-033).
- [x] Probe SSRF policy on save and before each send, connection pinned to the validated IP (`PinnedConnection`, shared with HTTP probes), no redirects or proxy, 5 s timeout, 64 KiB response cap; HTTPS unless private networks are allowed.
- [x] UI: type picker, endpoint field, one-time secret panel with copy, regeneration dialog, verification notes; host-only summary; French translations.
- [x] Tests: reference signature vector, SSRF rejections (IPv4/IPv6, ports, DNS rebinding at send time), response classification, retries with a stable id, payload redaction, organization language, authorization and tenant isolation, quota.

## Sprint 12b — Slack (core)
- [x] `slack` notification channel: Slack incoming webhook URL (`https://hooks.slack.com/services/T…/B…/…`) stored encrypted, re-validated before each send, 5 s timeout, no redirects or proxy (ADR-034).
- [x] Block Kit message in a coloured attachment (title, summary, fields, organization, "Open in Veyra" button) in the organization language; every untrusted value escaped so it can never mention anyone or forge a link.
- [x] Responses: 2xx sent; 429 (with `Retry-After`) and 5xx retried; other statuses permanent, with Slack's error code (`no_service`, `channel_is_archived`…) kept in the delivery error.
- [x] UI: type picker entry, Slack URL field, French translations.
- [x] Tests: URL rule, payload and escaping, language, response classification, retries, tampered URL, authorization, tenant isolation, quota.

## Sprint 13 — Visual identity (1.5)
- [x] Visual direction: palette, typography, logo/wordmark, iconography and tone; written down as design tokens (Tailwind 4 theme, light and dark).
- [x] App shell, dashboard, monitor list/detail, incidents and charts restyled with the new tokens; status colours (UP/DOWN/DEGRADED/UNKNOWN/MAINTENANCE) distinct and accessible (WCAG AA contrast, not colour alone).
- [x] Landing page, authentication pages, e-mails and public status pages aligned with the identity; status pages stay neutral enough for customers' own branding.
- [x] Empty states, loading skeletons and microcopy reviewed (en/fr).
- [x] No behaviour change: existing feature tests pass; Vitest/visual checks on key components; screenshots in the PR.

## Cloud extension (private repository)
- [x] Plans and Paddle billing, billing page, plan enforcement on downgrade (`Entitlements`, `RaiseMonitorIntervals`).
- [x] Operator console for instance administrators with audit log (ADR-031).
- [x] Pricing and legal public pages through `PublicNavigation`.
- [x] Operator impersonation through the core `Impersonation` contract and banner (ADR-038).
- Details live in `extensions/cloud/README.md`, never in this repository.

## Next — planned, not started
Order of work: 14 → 22. Criteria are a starting point, refined in the sprint's plan and ADR. The open Sprint 6 item (Caddy on a real host) is closed whenever a real host is available, before the next release at the latest.

## Sprint 14 — Status page audience (1.5)
- [x] Branding per status page: logo, accent colour, favicon (uploaded images validated and size-limited, served without cookies); contrast checked against the accent colour.
- [x] Visitor subscriptions by e-mail (double opt-in, one-click unsubscribe, rate-limited, addresses never shown) and RSS/Atom feeds for incidents and maintenance, in the organization language.
- [x] SVG badges (status, uptime over 24 h / 7 d / 30 d) per published component, served from the whitelisted snapshot, cacheable, no data for unpublished monitors.
- [x] Entitlements for subscribers and branding; Community limits nothing.
- [x] Tests: opt-in flow, unsubscribe tokens, no data leak for unpublished components, custom domains, translations.

## Sprint 15 — Advanced HTTP checks (1.5)
- [x] Response content assertion: keyword present / absent in the body, case option, read limited to the existing response cap; result recorded as a distinct failure reason.
- [x] Latency threshold per monitor: above it the check is DEGRADED, with the usual confirmation thresholds; no effect on DOWN logic.
- [x] POST/PUT/PATCH with a request body (size-limited, content type) and authentication headers / basic auth stored encrypted, redacted in logs, UI and webhook payloads.
- [x] SSRF rules unchanged for every method; rate limits on manual checks still apply.
- [x] Tests: assertions, DEGRADED transitions, encryption and redaction, validation, translations.

## Sprint 16 — More channels and account security (1.5)
- [x] Notification channels: ntfy and Gotify (self-hosted friendly, server URL under the probe SSRF policy), Telegram bot, Microsoft Teams (workflow webhook), PagerDuty Events v2 (trigger / resolve with a stable dedup key).
- [x] Same delivery guarantees as existing channels: credentials encrypted, retry-safe, response classification, organization language, untrusted values escaped.
- [x] Two-factor authentication (Fortify TOTP + recovery codes) for every user; Owners can require it for their organization (members without it are asked to enable it before access).
- [x] Tests: payloads per channel, retries, SSRF, 2FA enforcement and recovery, tenant isolation, translations.

## Sprint 17 — Recurring maintenance (1.6)
- [x] Recurring windows (daily/weekly/monthly rule, organization time zone, duration ≤ `VEYRA_MAINTENANCE_MAX_HOURS`), occurrences materialized ahead of time into the existing one-off windows (ADR-021 semantics unchanged).
- [x] DST-safe occurrence generation, idempotent and concurrency-safe; editing or deleting a series never alters past or running occurrences.
- [x] UI: create/edit series, skip one occurrence, upcoming occurrences list; status pages show the next occurrence.
- [x] Tests: rule expansion (DST, month ends), idempotent generation, tenant isolation, translations.

## Sprint 18 — DNS monitors and domain expiry (1.6)
- [x] `dns` `MonitorType` with its own config table: record name, type (A, AAAA, CNAME, MX, TXT, NS), optional expected values (contains / exact), timeout ≤ 30 s (ADR-024 dispatch by type).
- [x] Resolver choice restricted to the system resolver or an allow-listed set of public resolvers; no arbitrary resolver IP (SSRF), answers bounded in size.
- [x] Per-type confirmation thresholds; resolution failures vs. mismatched answers distinguished in check results.
- [x] Domain expiry for HTTP and DNS monitors through RDAP (bootstrap registry, fixed endpoints, daily at most), warnings at the same thresholds and once per expiry date, like certificates (ADR-026).
- [x] UI: type picker entry, form, detail page with last answers and domain expiry; status pages and analytics unchanged.
- [x] Tests: answer matching, timeouts, resolver restrictions, RDAP parsing and failures, tenant isolation, translations; docs MONITORING/SECURITY/DECISIONS.

## Sprint 19 — Public API (1.7)
- [x] Versioned REST API (`/api/v1`) with Eloquent API Resources: monitors (CRUD, pause/resume), checks (read), incidents (read, acknowledge), maintenance windows.
- [x] Organization-scoped API tokens with abilities (read / write), hashed at rest, shown once, revocable, last-used timestamp; Owners/Admins manage them.
- [x] Same Policies, Form Requests, quotas (`Entitlements`) and SSRF rules as the UI; rate limits per token; cursor pagination.
- [x] Reference documentation (OpenAPI) and examples.
- [x] Tests: authentication, abilities, tenant isolation, quotas, rate limits, validation parity with the UI.

## Sprint 20 — Escalation and reports (1.8)
- [x] Escalation policies: ordered steps (channel set + delay) per monitor or organization default; a step fires only while the incident is open and unacknowledged; acknowledgement or recovery stops the chain; idempotent under retries and concurrent workers.
- [x] Weekly report e-mail per organization (opt-in per member): uptime, incidents, slowest monitors, in the organization language and time zone.
- [x] Tests: escalation timing, stop conditions, idempotency, report content, tenant isolation, translations.

## Sprint 21 — Organization tools (1.8)
- [x] Audit log of member actions (monitors, channels, status pages, members, settings, API tokens): actor, action, target, before/after without secrets; append-only, retention configurable, Owners/Admins only.
- [x] Monitor tags with filters on lists, dashboard and API.
- [x] Monitor import/export (versioned JSON, secrets excluded), import validated like the UI with quotas and SSRF rules, dry-run preview.
- [x] Tests: audit coverage of every action, redaction, tag filters, import validation and quotas, tenant isolation.

## Sprint 22 — Operations (1.9)
- [x] Prometheus `/metrics` endpoint for the instance: queue sizes, outbox backlog and age, scheduler beat lag, probe durations, delivery failures; disabled by default, token or IP allow-list, no tenant data or labels with organization names (ADR-048).
- [x] Grafana dashboard example and alerting rules in `docs/operations/`.
- [x] Tests: access control, metric names stable, no tenant leak.

## Next — planned, not started (1.11 → 1.12)
Order of work: 23 → 25. Criteria are a starting point, refined in the sprint's plan and ADR.

## Sprint 23 — Check diagnostics (1.11)
- [ ] Timing breakdown per HTTP check: DNS, connect, TLS, time to first byte, transfer (from the cURL transfer info of the pinned connection), stored on the check; nullable for older checks and other types; TCP keeps connect time, DNS keeps resolution time.
- [ ] Failure evidence on the first failing check of an incident: status, response headers (allow-list, credentials and cookies never kept), bounded body excerpt (a few KiB, within the existing response cap, text content types only), failure reason; attached to the incident, purged with it.
- [ ] UI: waterfall of the timings on the check detail and latest checks, stacked latency chart by phase on the monitor page, evidence panel on the incident page; no evidence on public status pages, webhook payloads or the API unless explicitly redacted and documented.
- [ ] Retention: timings follow raw-check retention and aggregation; evidence follows the incident; batched purge unchanged.
- [ ] Tests: timing capture and nulls, redaction of headers and excerpt, size bounds, binary bodies skipped, tenant isolation, translations; docs MONITORING/DATABASE/SECURITY, ADR.

## Sprint 24 — Dependencies and groups (1.11)
- [ ] Monitor dependencies: a monitor may depend on parents in the same organization (no cycles, depth limited); while a parent is confirmed DOWN, a failing child is confirmed as `IMPACTED` (or flagged on the incident) without sending its own notifications or escalation; the child alerts normally once its parents are UP. Probe results and confirmed state stay distinct; UNKNOWN parents never suppress alerts.
- [ ] Monitor groups: named groups with an aggregated state (all UP, any DEGRADED, a configurable share of members DOWN → group DOWN); group incidents and notifications optional and deduplicated with member incidents.
- [ ] Transitions remain concurrency-safe and idempotent when a parent and its children change state in the same scheduler window; suppressed notifications are recorded so they can be explained.
- [ ] Status pages may publish a group as one component; API v1, import/export and audit log cover dependencies and groups; entitlements for groups (Community limits nothing).
- [ ] UI: dependency picker and group management, impacted monitors shown on the parent incident, dashboard filter by group.
- [ ] Tests: suppression and release, cycles refused, cross-organization parents refused, aggregation rules, concurrent transitions, status page snapshot, API parity, translations; docs MONITORING/DATABASE, ADR.

## Sprint 25 — Multi-location confirmation (1.12)
- [ ] ADR first: probe locations as additional Horizon supervisors on other hosts consuming per-location queues (`probes-<location>`), same application code and image, no external agent or separate daemon; MariaDB stays the source of truth, Redis per location or shared, egress isolation per host.
- [ ] Location registry configured by the instance (none by default: single-location behaviour unchanged); monitors choose locations within their entitlements; the outbox dispatches one job per location and check round.
- [ ] Confirmation rule: DOWN confirmed only when a configurable number of locations fail (default 2 when several are set); a silent or stale location is UNKNOWN and never counts as a failure; per-location results kept on checks.
- [ ] UI and analytics: results and latency per location, location shown in incidents; metrics endpoint exposes per-location queue lag and beat without tenant data.
- [ ] Tests: quorum rules, stale location, location outage not reported as downtime, idempotent rounds, upgrade from single location; Docker Compose and Vito Deploy guides for a second location.

## Done means
A clean install can register, create an organization and HTTP monitor, execute scheduled checks, detect confirmed downtime, notify, recover, and display history. A probe-worker outage must not masquerade as monitored-service downtime.

## Explicitly deferred
External probe agents, billing in the core (Cloud extension only) and SSO. Multi-location checks are planned only as Horizon workers (Sprint 25), never as agents.
