# Veyra

Veyra is an open-core, self-hostable uptime monitoring platform for HTTP/HTTPS endpoints, with incidents, notifications, analytics and organizations.

> Status: Sprint 2: HTTP/HTTPS monitors with SSRF-safe probes, a MariaDB outbox scheduler and check history, on top of the Sprint 1 foundations. Confirmed states and incidents arrive in Sprint 3 (see `docs/ROADMAP.md`).

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
To be decided (see ADR-005).
