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
- Health: `GET /up` (used by the `web` healthcheck); `php artisan horizon:status` for workers.
- Upgrade: pull/build the new image, `docker compose --env-file .env.docker up -d` (migrations run first through `migrate`).
- Horizon dashboard: `/horizon`, restricted to `VEYRA_ADMIN_EMAILS`.
- Backups: dump the `mariadb` volume regularly (`mariadb-dump`); Redis only holds transient queues/cache.

## Network isolation
- `backend` is an internal network: MariaDB and Redis have no internet access and are not published.
- Only `horizon` joins the `egress` network. Before HTTP probes ship (Sprint 2), restrict that egress at host/firewall level to public destinations (block RFC 1918, loopback, link-local, CGNAT, multicast, IPv6 ULA/link-local and cloud metadata such as `169.254.169.254`), in addition to the application-level SSRF checks.

## Notes
- `VITE_REVERB_*` values are compiled into the JavaScript bundle at build time; rebuild the images after changing them.
- Docker Compose validation (`docker compose config`) and a full `up` still need to be run on a host with Docker.

## Notifications (Sprint 4)
The Horizon workers (queue `notifications`) need outbound access to your SMTP server (`MAIL_*`) and, if Discord channels are used, to `discord.com:443`. Keep these destinations in the egress allow-list of the probe/worker hosts.
