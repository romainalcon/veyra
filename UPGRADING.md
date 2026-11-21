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
### 1.0.2
The Compose file no longer defines the `storage` volume. After upgrading you may remove the now unused volume: `docker volume rm <project>_storage`. Application logs are available with `docker compose logs`.

### 1.0.1
Packaging fix only (Docker images); nothing to migrate.

### 1.0.0
First release — nothing to migrate.
