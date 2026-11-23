# Veyra — Private deployment with Vito Deploy

Veyra runs the same code as the Docker distribution on a classic stack managed by Vito Deploy: Nginx + PHP-FPM 8.4, MariaDB 11.4, Redis, Supervisor and cron.

## Server prerequisites
- PHP 8.4 with `pdo_mysql`, `redis` (phpredis), `pcntl`, `posix`, `intl`, `zip`, `bcmath`, `opcache`.
- MariaDB 11.4+ with `default-time-zone = '+00:00'` and an `utf8mb4` database for Veyra.
- Redis 7+, Node.js 22 LTS and Composer 2 (for building assets on the server).

## Site setup
1. Create a Laravel/PHP site in Vito pointing to the repository, web root `public/`.
2. Create the `.env` from `.env.example`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://…`, `SESSION_SECURE_COOKIE=true`, database, Redis, mail and Reverb values. Generate `APP_KEY` with `php artisan key:generate` and `REVERB_APP_KEY`/`REVERB_APP_SECRET` with `openssl rand -hex 16`.
3. Use `deploy/vito/deploy.sh` as the deployment script (set `PHP_BIN` if needed).
4. Create two Vito workers (Supervisor programs) equivalent to `deploy/vito/supervisor/veyra-horizon.conf` and `veyra-reverb.conf`.
5. Add the scheduler cron from `deploy/vito/scheduler.cron` (Vito "Cron jobs").
6. Expose Reverb through the site's Nginx configuration:

```nginx
location ~ ^/(app|apps)/ {
    proxy_http_version 1.1;
    proxy_set_header Host $http_host;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "Upgrade";
    proxy_pass http://127.0.0.1:8080;
}
```

Then set `REVERB_HOST=127.0.0.1`, `REVERB_PORT=8080`, `REVERB_SCHEME=http` (server side) and `VITE_REVERB_HOST=<public host>`, `VITE_REVERB_PORT=443`, `VITE_REVERB_SCHEME=https` (browser side), and redeploy.

## Security
- Restrict outbound traffic of the user running Horizon to public destinations (see Docker guide) before enabling HTTP probes.
- If you use TCP monitors, allow the ports of `VEYRA_PROBES_TCP_ALLOWED_PORTS` (public destinations only) in that egress filter.
- DNS monitors need port 53 (UDP and TCP) to the system resolver and the public resolvers of `VEYRA_DNS_RESOLVERS`; domain expiry needs HTTPS to `data.iana.org` and the RDAP servers it lists.
- Heartbeat pings arrive on `/ping/{token}` through Nginx; keep `TRUSTED_PROXIES=127.0.0.1` so rate limits see the client IP, and keep the Laravel scheduler cron active (missed pings are only reported DOWN while it runs).
- Keep MariaDB and Redis bound to localhost or a private network.
- Set `VEYRA_ADMIN_EMAILS` to open `/horizon` to instance administrators only.

## Health checks and monitoring of the instance
- `GET /health` returns `{status, version}` (HTTP 503 when the database is down; `degraded` when Redis, Horizon or the scheduler are stopped). Add `Authorization: Bearer $VEYRA_HEALTH_TOKEN` for details. Point an external uptime check at it — preferably from another host.
- `GET /metrics` serves Prometheus metrics once `VEYRA_METRICS_ENABLED=true` with `VEYRA_METRICS_TOKEN` and/or `VEYRA_METRICS_ALLOWED_IPS` (the client address is resolved through `TRUSTED_PROXIES`); see `docs/operations/METRICS.md`.
- `php8.4 artisan veyra:doctor` after each configuration change.

## Updates and rollback
1. Back up the database (`deploy/vito/backup.sh` or Vito backups).
2. Deploy the new tag in Vito (runs `deploy/vito/deploy.sh`).
3. Check `/health` and `veyra:doctor`.
4. Rollback: redeploy the previous tag and restore the backup if migrations changed the schema.

## Backups
See `docs/operations/BACKUP.md`. Keep the site's `.env` (APP_KEY) in your secrets manager.

## Reverse proxy
Vito's Nginx terminates TLS: set `TRUSTED_PROXIES=127.0.0.1` and `SESSION_SECURE_COOKIE=true`.

### Custom status page domains (Sprint 6)
Vito's Nginx does not issue certificates on demand. For each verified custom domain of a status page, add the domain as an alias of the Veyra site in Vito and re-issue the Let's Encrypt certificate including it (or create a separate site proxying to Veyra with its own certificate). Requests for that host are restricted by Veyra to the status page; the TXT ownership check (`veyra:status-domains:verify`, hourly through the scheduler) still applies. `GET /status-domains/tls-check?domain=<host>` tells you whether Veyra currently serves a domain.

The paths in `deploy/vito/` (`/home/vito/veyra.example.com`, `php8.4`) are examples; adapt them to the site created in Vito.

## Notifications (Sprint 4)
The Horizon workers (queue `notifications`) need outbound access to your SMTP server (`MAIL_*`) and, for the channels you use, to `discord.com:443` (Discord), `hooks.slack.com:443` (Slack), `api.telegram.org:443` (Telegram), `events.pagerduty.com:443` (PagerDuty), `*.logic.azure.com:443` / `*.api.powerplatform.com:443` (Microsoft Teams) and your ntfy or Gotify server. Keep these destinations in the egress allow-list of the probe/worker hosts.

Outgoing webhook channels (ADR-033) POST to hosts chosen by organizations, on the ports of `VEYRA_PROBES_ALLOWED_PORTS`: the notification workers need the same public-only egress filtering as the probe workers (block private, loopback, link-local, CGNAT, multicast, IPv6 ULA/link-local and metadata ranges).
