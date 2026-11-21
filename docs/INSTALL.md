# Installing Veyra (Community Edition)

Requirements: a Linux host with Docker Engine 24+ and the Compose plugin, a DNS name and TLS termination (reverse proxy or load balancer) for production.

## Quick start (prebuilt images)
```bash
git clone https://github.com/romainalcon/veyra.git veyra && cd veyra
cp deploy/docker/.env.docker.example .env.docker
# Edit .env.docker: DB_PASSWORD, DB_ROOT_PASSWORD, REVERB_APP_KEY/SECRET (+ VITE_REVERB_APP_KEY), MAIL_*, APP_URL,
# VEYRA_IMAGE_PREFIX=ghcr.io/romainalcon and VEYRA_VERSION=1.0.0 to pull the published images.
docker compose --env-file .env.docker run --rm --no-deps app php artisan key:generate --show   # paste into APP_KEY
docker compose --env-file .env.docker up -d --wait
```
Open `APP_URL`, create the first account, then:
1. `docker compose --env-file .env.docker exec app php artisan veyra:doctor` and fix every **FAIL**.
2. Set `VEYRA_REGISTRATION_ENABLED=false` once your team is registered (invited people can still join), then `docker compose --env-file .env.docker up -d`.
3. Set `VEYRA_ADMIN_EMAILS` to open `/horizon`, and `VEYRA_HEALTH_TOKEN` for detailed `/health`.
4. If you run a modified version, set `VEYRA_SOURCE_URL` to your own repository (AGPL-3.0 §13); it defaults to the upstream repository.
5. Schedule backups (`docs/operations/BACKUP.md`) and restrict outbound traffic of the `horizon` service (`docs/deployment/DOCKER.md`).

Building images locally instead: keep `VEYRA_IMAGE_PREFIX=veyra` and run `docker compose --env-file .env.docker build` first.

## Behind a reverse proxy
Terminate TLS in front of the `web` service, forward `Host` and `X-Forwarded-*`, and set `TRUSTED_PROXIES` (e.g. the proxy IP, or `*` when only the proxy can reach the container) plus `SESSION_SECURE_COOKIE=true`. WebSocket upgrades on `/app` and `/apps` must be forwarded for real-time updates (`VITE_REVERB_*` must describe the public endpoint).

## Private deployment
See `docs/deployment/VITO.md`.
