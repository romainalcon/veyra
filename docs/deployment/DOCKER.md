# Veyra — Docker Compose (Community Edition)

The stack in `compose.yaml` runs one application image in several roles plus MariaDB and Redis.

| Service | Role |
|---|---|
| `migrate` | Runs `php artisan migrate --force` once, then exits |
| `app` | PHP-FPM serving the application |
| `web` | Nginx: static assets, PHP via FastCGI, Reverb WebSocket proxy (`/app`, `/apps`) |
| `horizon` | Horizon supervisors for `probes`, `incidents`, `notifications`, `maintenance`/`default` |
| `scheduler` | `php artisan schedule:work` |
| `reverb` | Reverb WebSocket server (internal, reached through `web`) |
| `mariadb` | MariaDB 11.4 (UTC) |
| `redis` | Redis 7.4 with AOF persistence |

## Install

```bash
cp deploy/docker/.env.docker.example .env.docker
# Fill DB_PASSWORD, DB_ROOT_PASSWORD, REVERB_APP_KEY/SECRET (and VITE_REVERB_APP_KEY = REVERB_APP_KEY), MAIL_*.
docker compose --env-file .env.docker build
docker compose --env-file .env.docker run --rm --no-deps app php artisan key:generate --show
# Paste the key into APP_KEY in .env.docker, then:
docker compose --env-file .env.docker up -d
```

Open `http://localhost:8000` (or `VEYRA_HTTP_PORT`). After creating the operator accounts you may set `VEYRA_REGISTRATION_ENABLED=false`; invited e-mails can still register.

## Operations
- Health: `GET /health` (used by the `web` healthcheck; details with `VEYRA_HEALTH_TOKEN`), `php artisan veyra:doctor`, `php artisan horizon:status`.
- Upgrade: pull/build the new image, `docker compose --env-file .env.docker up -d` (migrations run first through `migrate`).
- Horizon dashboard: `/horizon`, restricted to `VEYRA_ADMIN_EMAILS`.
- Backups: dump the `mariadb` volume regularly (`mariadb-dump`); Redis only holds transient queues/cache.

## Network isolation
- `backend` is an internal network: MariaDB and Redis have no internet access and are not published.
- Only `horizon` joins the `egress` network. Before HTTP probes ship (Sprint 2), restrict that egress at host/firewall level to public destinations (block RFC 1918, loopback, link-local, CGNAT, multicast, IPv6 ULA/link-local and cloud metadata such as `169.254.169.254`), in addition to the application-level SSRF checks.

## Backups
See `docs/operations/BACKUP.md` (`deploy/docker/backup.sh` and `restore.sh`).

## Notes
- `VITE_REVERB_*` values are compiled into the JavaScript bundle at build time; rebuild the images after changing them.
- The stack is built, started, smoke-tested and backed up/restored in CI (`.github/workflows/docker.yml`).

## TLS and custom status page domains (Sprint 6)
The stack serves plain HTTP on `VEYRA_HTTP_PORT`; terminate TLS in front of it. `deploy/docker/Caddyfile.example` shows a Caddy configuration that also issues certificates on demand for custom status page domains:
- Caddy asks `GET /status-domains/tls-check?domain=<host>` before requesting a certificate; Veyra answers 200 only for verified domains of published status pages.
- Requests whose `Host` is a verified custom domain only ever get the status page on `/` (everything else is 404); the instance host from `APP_URL` is never diverted.
- Keep `TRUSTED_PROXIES` set so that the forwarded scheme and client IP (used for rate limiting) are honoured.
- Users point their domain (CNAME or A/AAAA) at the server and prove ownership with the `_veyra-challenge.<domain>` TXT record shown in the UI; `veyra:status-domains:verify` (hourly) re-checks it daily and stops serving a domain after 3 consecutive failures.

This configuration is an example and is not exercised in CI.

## Monitor types (Sprint 7)
- TCP monitors connect to the ports of `VEYRA_PROBES_TCP_ALLOWED_PORTS`; allow them (to public destinations only) in the egress rules of the `horizon` service if you use TCP monitors.
- DNS monitors (Sprint 18) query port 53 (UDP and TCP) of the worker's resolver or of 1.1.1.1/1.0.0.1, 8.8.8.8/8.8.4.4, 9.9.9.9/149.112.112.112; domain expiry (HTTP and DNS monitors) queries RDAP servers over HTTPS (`data.iana.org` and the registry servers it lists). Allow them in the `horizon` egress rules, or set `VEYRA_DNS_RESOLVERS` / `VEYRA_DOMAIN_EXPIRY_ENABLED=false`.
- Heartbeat monitors are pinged on `/ping/{token}` by your jobs: the path must be reachable through your reverse proxy, and `TRUSTED_PROXIES` must be set for per-IP rate limits. Keep the `scheduler` service running: without its minute beats, missed pings are reported as UNKNOWN, not DOWN.

## Notifications (Sprint 4)
The Horizon workers (queue `notifications`) need outbound access to your SMTP server (`MAIL_*`) and, for the channels you use, to `discord.com:443` (Discord), `hooks.slack.com:443` (Slack), `api.telegram.org:443` (Telegram), `events.pagerduty.com:443` (PagerDuty), `*.logic.azure.com:443` / `*.api.powerplatform.com:443` (Microsoft Teams) and your ntfy or Gotify server. Keep these destinations in the egress allow-list of the probe/worker hosts.

Outgoing webhook channels (ADR-033) POST to hosts chosen by organizations, on the ports of `VEYRA_PROBES_ALLOWED_PORTS`: the notification workers need the same public-only egress filtering as the probe workers (block private, loopback, link-local, CGNAT, multicast, IPv6 ULA/link-local and metadata ranges).
