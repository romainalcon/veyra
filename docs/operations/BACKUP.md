# Backups and restore

## What to back up
| Item | Why | How |
|---|---|---|
| MariaDB database | All business data: users, organizations, monitors, checks, incidents, channels | `deploy/docker/backup.sh` (Docker) or `deploy/vito/backup.sh` / Vito database backups |
| `APP_KEY` (`.env.docker` / `.env`) | Encrypts custom monitor headers, notification channel secrets and sessions. **A dump restored with another key loses these secrets.** | Store the env file in a password manager or secrets vault, separately from the dumps |
| Application logs | Written to stderr in Docker (`docker compose logs`), to `storage/logs` on Vito | Ship them to your log system if needed |
| Redis | Transient queues and cache; rebuilt automatically (the MariaDB outbox republishes pending dispatches) | Not needed |

## Schedule
- Daily dump, keep 14 (`BACKUP_KEEP`), copy off-site (object storage, another host).
- Before every upgrade (see `UPGRADING.md`).

Example cron on the Docker host (repository root):

```cron
30 2 * * * cd /opt/veyra && ./deploy/docker/backup.sh /var/backups/veyra >> /var/log/veyra-backup.log 2>&1
```

## Restore
1. Make sure the env file contains the **same APP_KEY** as the instance that produced the dump.
2. `./deploy/docker/restore.sh /var/backups/veyra/veyra-<timestamp>.sql.gz` — stops the application services, imports the dump, runs migrations and restarts the stack.
3. Check `GET /health` and log in. Monitor states start from the restored data; missing time is shown as UNKNOWN, never as downtime.

On Vito: restore the dump with `zcat dump.sql.gz | mariadb <database>` (or Vito's restore), then `php8.4 artisan migrate --force` and `php8.4 artisan horizon:terminate`.

## Restore drill (quarterly)
1. Start a scratch stack (`docker compose -p veyra-drill --env-file .env.drill up -d`) with the production APP_KEY.
2. Restore the latest dump into it with `ENV_FILE=.env.drill ./deploy/docker/restore.sh <dump> --yes`.
3. Log in, open a monitor with custom headers (proves APP_KEY), check incidents and history.
4. Record the date, dump age and duration; destroy the scratch stack (`down -v`).

The Docker CI workflow (`.github/workflows/docker.yml`) runs a backup → restore cycle on every change to the deployment files.
