# Veyra

Veyra is an open-core, self-hostable uptime monitoring platform for HTTP/HTTPS endpoints, TCP services and cron jobs (heartbeats), with incidents, notifications, analytics and organizations.

> Status: V1 feature-complete (Sprints 1–5); 1.1 adds maintenance and status pages, 1.2 TCP, heartbeat monitors and TLS certificate warnings, 1.3 French, outgoing webhooks and Slack. Installation: `docs/INSTALL.md`; operations: `docs/operations/BACKUP.md`, `UPGRADING.md`; security: `docs/SECURITY_REVIEW.md`; load baseline: `docs/benchmarks/`.

## Stack
Laravel 13 · PHP 8.4 · Vue 3 + TypeScript · Inertia 3 · Tailwind CSS 4 + shadcn-vue · MariaDB 11.4+ · Redis · Horizon · Reverb · Pest · Vitest.

## Local development
Requirements: PHP 8.4, Composer 2, Node.js 22, MariaDB 11.4+, Redis.

```bash
cp .env.example .env            # set DB_* / REDIS_* / REVERB_* values
composer setup                  # install, key, migrate, build
composer dev                    # server, Horizon, Reverb, logs and Vite
php artisan schedule:work       # in another terminal: runs the monitor scheduler
```

Create the databases `veyra` and `veyra_testing` (utf8mb4) first. `php artisan db:seed` creates `test@example.com` / `password` with an `acme` organization.

## Quality checks
```bash
composer lint:check   # Pint
composer analyse      # PHPStan / Larastan (level 7)
composer test         # Pest on MariaDB (veyra_testing)
npm run check         # ESLint, Prettier, vue-tsc, Vitest
```

## Deployment
- Community: Docker Compose, see `docs/deployment/DOCKER.md`.
- Private: Vito Deploy, see `docs/deployment/VITO.md`.

## Documentation
`docs/PRODUCT.md`, `docs/ARCHITECTURE.md`, `docs/DATABASE.md`, `docs/MONITORING.md`, `docs/SECURITY.md`, `docs/ROADMAP.md`, `docs/DECISIONS.md`.

## License
Veyra Community Edition is licensed under the **GNU Affero General Public License v3.0 only** (`AGPL-3.0-only`, see `LICENSE`).

If you run a modified version of Veyra as a network service, the AGPL requires you to offer its source code to the users of that service; set `VEYRA_SOURCE_URL` to your repository so the link in the interface points to it. Optional Cloud modules, if any, will live under `extensions/cloud` with their own license.
