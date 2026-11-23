# Upgrading Veyra

Veyra follows [Semantic Versioning](https://semver.org/): patch and minor releases are backwards compatible and migrate automatically; major releases may require manual steps, always listed below and in `CHANGELOG.md`.

## Docker Compose
1. Back up: `./deploy/docker/backup.sh` (and keep `.env.docker`).
2. Read the `CHANGELOG.md` entries between your version and the target.
3. Set `VEYRA_VERSION` to the target in `.env.docker` (or `git pull` and rebuild for local builds).
4. `docker compose --env-file .env.docker pull && docker compose --env-file .env.docker up -d --wait` — the `migrate` service runs migrations before the application starts.
5. `docker compose --env-file .env.docker exec app php artisan veyra:doctor` and check `/health`.

Rollback: restore the backup taken in step 1 with the previous `VEYRA_VERSION` (migrations are not rolled back automatically).

## Vito Deploy
Deploy the new tag with the site's deployment script (`deploy/vito/deploy.sh`): it installs dependencies, builds assets, migrates, caches the configuration and restarts Horizon and Reverb. Take a database backup first.

## Version notes
### 1.8.0
Two migrations run automatically; take a backup first as usual. Both are additive.
- `create_escalation_and_weekly_report_tables` creates the escalation policy tables and `weekly_reports`, and adds `monitors.escalation_policy_id`, `incidents.escalation_step`, `notification_deliveries.escalation_step` and `organization_members.weekly_report` (all defaulted). It replaces the delivery idempotency key `(incident_id, channel_id, event_type)` with `(incident_id, channel_id, event_type, escalation_step)`; the new key is built before the old one is dropped, so on a large `notification_deliveries` table the migration takes a little longer. Nothing escalates until an Owner or Admin creates a policy, and no report is sent until a member opts in.
- `create_audit_logs_and_tags_tables` creates `audit_logs`, `tags` and `monitor_tag`. The audit log starts empty: actions before the upgrade are not recorded.
- New scheduled commands run through the existing scheduler: `veyra:incidents:escalate` (every minute) and `veyra:reports:weekly` (hourly; Monday 08:00 in each organization's time zone). Weekly reports use the existing mail configuration (`MAIL_*`).
- Optional: `VEYRA_AUDIT_RETENTION_DAYS` (default 365; old entries are purged by `veyra:history:purge`) and `VEYRA_MONITOR_IMPORTS_PER_HOUR` (default 10 import previews or imports per member and hour).
- For API clients: monitors gain `escalation_policy_id` and `tags`; `GET /api/v1/monitors` accepts `?tag=`. Both fields are kept when omitted from a replacement, so existing clients are unaffected.
- For extension authors: deleting a notification channel, an escalation policy or a status page, and changing the two-factor requirement, now go through Actions (`DeleteNotificationChannel`, `DeleteEscalationPolicy`, `DeleteStatusPage`, `SetTwoFactorRequirement`) that write the audit log; call them rather than deleting models directly. `CreateMonitor::handle()` takes an optional `$auditMetadata` argument and `IncidentSeries::forOrganization()` an optional `$monitorIds` filter.

### 1.7.0
One migration runs automatically; take a backup first as usual.
- `create_api_tokens_table` creates the `api_tokens` table. It is additive: nothing changes until an Owner or Admin creates a token.
- The API is served under `/api/v1` by the existing application. Reverse proxies must pass `/api/*` and the `Authorization` header through (the bundled Nginx configurations do). Keep `TRUSTED_PROXIES` accurate so that the per-IP limit sees the real client address. On verified custom status page domains, `/api/*` stays 404.
- Optional: `VEYRA_API_REQUESTS_PER_MINUTE` (default 120 per token) and `VEYRA_API_REQUESTS_PER_MINUTE_PER_IP` (default 600).
- For extension authors: the monitor, incident and maintenance window policies now take an `OrganizationActor` (a `User` or an `ApiToken`) as first argument, and `IncidentAcknowledged::$userId` and `incidents.acknowledged_by` are null when an API token acknowledged the incident (the token is recorded in the event metadata).

### 1.6.0
Two migrations run automatically; take a backup first as usual.
- `create_maintenance_series_tables` adds `organizations.timezone` (default `UTC`), creates the recurring maintenance tables and adds two nullable columns and a foreign key to `maintenance_windows`. Existing windows and organizations are unchanged; set the organization time zone before creating recurring maintenance. New scheduled command `veyra:maintenance:materialize` (hourly) runs through the existing scheduler.
- `create_dns_and_domain_expiry_tables` creates the DNS monitor and domain expiry tables and adds `notification_deliveries.domain_alert_id`, whose CHECK constraint now allows exactly one of incident, certificate alert and domain alert. MariaDB rebuilds `notification_deliveries` (deliveries are kept 90 days, so the table stays small). New scheduled command `veyra:domains:check` (hourly) runs through the existing scheduler.
- Domain expiry is on by default: after the upgrade, existing HTTP monitors start sending domain expiry warnings to their channels when a domain is within `VEYRA_CERTIFICATE_WARNING_DAYS` of its expiry. Webhook endpoints receive the new `domain.expiring` / `domain.expired` events: make sure they ignore event types they do not know. Set `VEYRA_DOMAIN_EXPIRY_ENABLED=false` to keep the 1.5 behaviour.
- Egress: DNS monitors need port 53 (UDP and TCP) from the `probes` workers to the system resolver and to the public resolvers of `VEYRA_DNS_RESOLVERS` (1.1.1.1/1.0.0.1, 8.8.8.8/8.8.4.4, 9.9.9.9/149.112.112.112); domain expiry needs HTTPS from the `maintenance` workers to `data.iana.org` and the RDAP servers it lists. See the deployment guides.
- Optional: `VEYRA_DNS_RESOLVERS` (default `system,cloudflare,google,quad9`), `VEYRA_DNS_DOWN_AFTER_FAILURES` / `VEYRA_DNS_UP_AFTER_SUCCESSES` (default: the HTTP values, 3/2), `VEYRA_DOMAIN_EXPIRY_ENABLED` (default `true`).

### 1.5.0
There is no 1.4.0 release: upgrade from 1.3 straight to 1.5. Three migrations run automatically; take a backup first as usual.
- `create_status_page_audience_tables` adds two columns to `status_pages` (subscriptions are off by default) and creates the branding, subscriber and announcement tables. Nothing changes on existing pages until a manager enables subscriptions or adds branding. Subscription e-mails use the existing mail configuration (`MAIL_*`); configure it before enabling subscriptions. Changing `APP_KEY` invalidates subscriber lookups and links, as it already does for encrypted channel credentials.
- `add_advanced_http_checks` adds nullable columns to `monitor_http_configs` (small table) and a `monitor_checks.slow` column (instant `ADD COLUMN` on MariaDB 11.4, no rebuild of the check history). Existing monitors keep their behaviour: no body, no keyword, no latency threshold.
- `add_two_factor_authentication` adds the Fortify two-factor columns to `users` and `organizations.require_two_factor` (default off). Nothing changes until a user enables two-factor authentication or an Owner requires it. Two-factor secrets are encrypted with `APP_KEY`: changing it disables every user's second factor.
- New notification channels need outbound access from the `notifications` workers when used: `api.telegram.org:443` (Telegram), `events.pagerduty.com:443` (PagerDuty), `*.logic.azure.com:443` or `*.api.powerplatform.com:443` (Microsoft Teams workflows), and your ntfy or Gotify server (same public-only rules as outgoing webhooks).

### 1.3.0
One migration runs automatically: `add_locale_to_organizations_table` adds `organizations.locale` (default `en`, so existing organizations stay in English). The table is small; take a backup first as usual.
- Organization language: Owners and Admins can switch an organization to French in its settings. Nothing changes until they do.
- The deprecated `ExecuteHttpProbe` job alias is removed. Upgrading from 1.2 needs nothing. Upgrading **straight from 1.1**, let the `probes` queue drain first (stop the scheduler, wait until Horizon shows no pending `probes` jobs, then upgrade). A 1.1 job left in the queue fails once in `failed_jobs`, and its dispatch is redelivered as `ExecuteProbe` after 5 minutes: one check may be missing, never a false DOWN.
- Optional: `VEYRA_LANDING_PAGE=true` shows a public landing page to guests on `/` (default `false`: the login page, as before).
- Outgoing webhook channels (ADR-033) send requests from the notification workers to hosts chosen by organizations. No migration. If you filter egress per service, give the `notifications` workers the same public-only rules as the probe workers (see the deployment guides).
- Slack channels (ADR-034) need outbound access from the `notifications` workers to `hooks.slack.com:443`.

### 1.2.0
Four migrations run automatically: `create_monitor_tcp_configs_table`, `create_monitor_heartbeat_configs_table` (also widens `monitors.interval_seconds` to `INT UNSIGNED`), `create_scheduler_beats_table` and `create_tls_certificate_tables` (new tables; `notification_deliveries.incident_id` becomes nullable and gains a `certificate_alert_id` column). The two altered tables are small (`monitors`, and deliveries kept 90 days), but MariaDB rebuilds them: take a backup first as usual.
- Existing monitors become HTTP monitors; nothing changes for them. Probe jobs already queued by 1.1 keep running.
- New scheduled command `veyra:certificates:check` (hourly) runs through the existing scheduler; `veyra:heartbeat` now also records a durable beat every minute, used to tell a missed ping from a stopped scheduler. Nothing to configure, but keep the scheduler running: while it is stopped, heartbeat deadlines are reported as UNKNOWN, not DOWN.
- Heartbeat monitors need `/ping/*` reachable from the machines running your jobs through your reverse proxy, with `TRUSTED_PROXIES` set so that per-IP rate limits see the real client address.
- TCP monitors connect to the ports in `VEYRA_PROBES_TCP_ALLOWED_PORTS`: open those ports (to public destinations only) in the egress rules of the Horizon workers if you use TCP monitors.
- Optional: `VEYRA_PROBES_TCP_ALLOWED_PORTS`, `VEYRA_CERTIFICATE_WARNING_DAYS` (default `14,7,1`), `VEYRA_HEARTBEAT_DOWN_AFTER_MISSES` / `VEYRA_HEARTBEAT_UP_AFTER_PINGS` (default 1/1).

### 1.1.0
Two migrations run automatically: `create_maintenance_tables` adds a `monitor_checks.in_maintenance` column (instant on MariaDB 11.4, no table rebuild) and maintenance tables; `create_status_page_tables` adds the status page tables. Take a backup first as usual.
- New scheduled commands `veyra:maintenance:transition` (every minute) and `veyra:status-domains:verify` (hourly) run through the existing scheduler; nothing to configure.
- Optional: `VEYRA_MAINTENANCE_MAX_HOURS` (default 168).
- Custom status page domains need TLS on your reverse proxy: see "TLS and custom status page domains" in `docs/deployment/DOCKER.md` (Caddy, on-demand TLS) or `docs/deployment/VITO.md` (manual aliases). Without it, status pages remain available on `/status/{slug}`.

### 1.0.2
The Compose file no longer defines the `storage` volume. After upgrading you may remove the now unused volume: `docker volume rm <project>_storage`. Application logs are available with `docker compose logs`.

### 1.0.1
Packaging fix only (Docker images); nothing to migrate.

### 1.0.0
First release — nothing to migrate.
