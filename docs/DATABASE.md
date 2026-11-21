# Veyra — MariaDB data model (V1)

## Database decisions
- MariaDB **11.4+**, Laravel `DB_CONNECTION=mysql` with a MariaDB-compatible driver.
- UUIDv7 for business identifiers; choose one consistent storage representation (V1: `CHAR(36)` via Laravel UUID columns; binary optimization later only if measured).
- Store application timestamps in UTC (`datetime(6)` where microsecond precision is needed); set DB session timezone consistently.
- Use MariaDB `JSON` / Laravel `json()` for metadata, not PostgreSQL `jsonb`.
- Monitoring history stays in a **regular table** for V1, indexed by `(monitor_id, checked_at)`; cleanup uses bounded batches. Revisit partitioning after load tests.

## Tables
| Table | Key fields |
|---|---|
| users | id, name, email, password, email_verified_at |
| organizations | id, name, slug, settings, created_at |
| organization_members | organization_id, user_id, role, joined_at |
| organization_invitations | id, organization_id, email, role, token_hash, expires_at |
| monitors | id, organization_id, name, type, enabled, interval_seconds, timeout_seconds, next_check_at, deleted_at |
| monitor_http_configs | monitor_id, url, method, expected_statuses (json), follow_redirects, headers_encrypted |
| monitor_states | monitor_id, status, consecutive_failures, consecutive_successes, last_checked_at, last_changed_at, last_applied_check_at |
| monitor_checks | id, monitor_id, scheduled_for, checked_at, success, duration_ms, status_code, error_code, metadata (json) |
| monitor_dispatches | id, monitor_id, scheduled_for, status, dispatched_at, attempts |
| incidents | id, monitor_id, status, started_at, resolved_at, acknowledged_at, cause |
| incident_events | id, incident_id, type, message, created_at |
| notification_channels | id, organization_id, type, name, config_encrypted, enabled |
| monitor_notification_channels | monitor_id, notification_channel_id |
| notification_deliveries | id, incident_id, channel_id, event_type, status, attempts, sent_at |
| monitor_daily_stats | monitor_id, day, checks_count, success_count, failure_count, latency_avg_ms |
| audit_logs | id, organization_id, actor_id, action, subject_type, subject_id, metadata |

## Constraints and indexes
- Unique organization membership `(organization_id, user_id)`.
- Unique scheduled dispatch `(monitor_id, scheduled_for)`.
- Index monitors `(enabled, next_check_at)` for scheduler; `(organization_id, deleted_at)` for lists.
- Index checks `(monitor_id, checked_at)` and incidents `(monitor_id, started_at)`.
- Enforce at most one active incident per monitor using a locked state transition or a dedicated active-incident pointer; MariaDB does not offer PostgreSQL-style partial unique indexes.
- Enforce tenant consistency for cross-resource joins in services/validation; consider composite FKs where useful.
- Use explicit retention and purge jobs; avoid expensive unbounded deletes and cascades on the high-volume history table.

## Caveats
- Do not copy PostgreSQL `PARTITION BY RANGE`, `timestamptz`, `jsonb` or partial-index examples into MariaDB migrations.
- MariaDB's JSON is text-backed; benchmark JSON filtering before designing indexes on metadata.
- Evaluate row volume, indexes, backup time and cleanup cost before considering monthly partitions.

## Monitoring tables (Sprint 2)
- New temporal columns use `DATETIME` (UTC) instead of `TIMESTAMP` to avoid the 2038 limit.
- `monitor_checks` and `monitor_dispatches` have **no foreign key** to `monitors`: deleting an organization cascades to monitors and configs, then `PurgeMonitorHistory` (queue `maintenance`) deletes history in batches of 1000.
- Monitors are soft deleted; their history is kept until retention (Sprint 4).
- The scheduler relies on `FOR UPDATE SKIP LOCKED`, available since MariaDB 10.6; `tests/Integration/MonitorClaimingTest.php` verifies the server version and disjoint claiming.

## State and incidents (Sprint 3)
- `monitor_states` (one row per monitor) is only modified under `SELECT … FOR UPDATE`; `active_incident_id` replaces a partial unique index.
- `incidents.organization_id` is denormalized for tenant-scoped listing (`(organization_id, status, started_at)`); `incident_events` is the append-only timeline.
- Added index `monitor_checks (monitor_id, scheduled_for)` for streak evaluation.

## Notifications and statistics (Sprint 4)
- `notification_deliveries` is unique on `(incident_id, channel_id, event_type)` — the idempotency key of the delivery pipeline.
- `monitor_daily_stats` (PK `(monitor_id, day)`, UTC days) is maintained with `INSERT … SELECT … ON DUPLICATE KEY UPDATE`; it survives raw-check retention.
- Retention deletes use `DELETE … LIMIT 1000` loops on indexed columns (`monitor_checks (monitor_id, checked_at)`, `monitor_dispatches (status, completed_at)`, `notification_deliveries (created_at)`). `tests/Integration/AggregationTest.php` covers 10 000 checks.
