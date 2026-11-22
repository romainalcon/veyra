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
### Unreleased (1.4)
One migration runs automatically: `create_status_page_audience_tables` adds two columns to `status_pages` (subscriptions are off by default) and creates the branding, subscriber and announcement tables. Nothing changes on existing pages until a manager enables subscriptions or adds branding. Subscription e-mails use the existing mail configuration (`MAIL_*`); configure it before enabling subscriptions. Changing `APP_KEY` invalidates subscriber lookups and links, as it already does for encrypted channel credentials.

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
