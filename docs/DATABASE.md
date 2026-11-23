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
| users | id, name, email, password, two_factor_secret / two_factor_recovery_codes (encrypted, ADR-040), two_factor_confirmed_at, email_verified_at |
| organizations | id, name, slug, locale (ADR-027), require_two_factor (ADR-040), settings, created_at |
| organization_members | organization_id, user_id, role, joined_at |
| organization_invitations | id, organization_id, email, role, token_hash, expires_at |
| monitors | id, organization_id, name, type, enabled, interval_seconds, timeout_seconds, next_check_at, deleted_at |
| monitor_http_configs | monitor_id, url, method (≤ 8), expected_statuses (json), follow_redirects, headers_encrypted, body_encrypted, body_content_type, basic_auth_encrypted, keyword, keyword_mode, keyword_case_sensitive, latency_threshold_ms |
| monitor_tcp_configs | monitor_id, host, port |
| monitor_dns_configs | monitor_id (PK), record_name, record_type, resolver, expected_values (json), match_mode, last_answers (json), last_resolved_at |
| monitor_heartbeat_configs | monitor_id, token_hash (unique, SHA-256), grace_seconds, last_ping_at, last_ping_failed |
| monitor_states | monitor_id, status, consecutive_failures, consecutive_successes, last_checked_at, last_changed_at, last_applied_check_at |
| monitor_checks | id, monitor_id, scheduled_for, checked_at, success, slow, duration_ms, status_code, error_code, metadata (json) |
| monitor_dispatches | id, monitor_id, scheduled_for, status, dispatched_at, attempts |
| incidents | id, monitor_id, status, started_at, resolved_at, acknowledged_at, cause |
| incident_events | id, incident_id, type, message, created_at |
| notification_channels | id, organization_id, type, name, config_encrypted, enabled |
| monitor_notification_channels | monitor_id, notification_channel_id |
| monitor_tls_certificates | monitor_id (PK), fingerprint (SHA-256), subject, issuer, not_before, not_after (indexed), checked_at |
| tls_certificate_alerts | id, organization_id, monitor_id, fingerprint, threshold_days, not_after, created_at — unique (monitor_id, fingerprint, threshold_days) |
| monitor_domains | monitor_id (PK), host, domain, expires_at (indexed), registrar, checked_at (indexed), last_error |
| domain_expiry_alerts | id, organization_id, monitor_id, domain, threshold_days, expires_at, created_at — unique (monitor_id, domain, expires_at, threshold_days) |
| notification_deliveries | id, incident_id (nullable), certificate_alert_id (nullable), domain_alert_id (nullable), channel_id, event_type, status, attempts, sent_at |
| monitor_daily_stats | monitor_id, day, checks_count, success_count, failure_count, latency_avg_ms |
| maintenance_windows | id, organization_id, series_id (nullable), occurrence_date (nullable), title, description, starts_at, ends_at, cancelled_at, created_by — unique (series_id, occurrence_date) |
| maintenance_window_monitors | maintenance_window_id, monitor_id |
| maintenance_series | id, organization_id, title, description, frequency, weekdays (json), month_day, start_time, duration_minutes, starts_on, ends_on, created_by |
| maintenance_series_monitors | maintenance_series_id, monitor_id |
| status_pages | id, organization_id, slug (unique), title, description, is_published, show_uptime, accent_color, subscriptions_enabled, custom_domain (unique), domain_verification_token, domain_verified_at, domain_checked_at, domain_failures |
| status_page_components | id, status_page_id, monitor_id, display_name, group_name, position |
| status_page_assets | id, status_page_id, kind (logo/favicon), mime, sha256, width, height, size, content (MEDIUMBLOB) |
| status_page_subscribers | id, organization_id, status_page_id, email (encrypted), email_hash, confirmed_at, confirmation_sent_at |
| status_page_announcements | id, organization_id, status_page_id, subject_id, event, payload (json) |
| status_page_subscriber_deliveries | id, announcement_id, subscriber_id, status, attempts, sent_at, last_error |
| api_tokens | id, organization_id, name, ability (read/write), token_hash (unique, SHA-256), last_four, created_by (nullable), last_used_at, expires_at, revoked_at |
| audit_logs | id, organization_id, actor_type, actor_id, actor_name, action, subject_type, subject_id, subject_label, changes (json), metadata (json), created_at (ADR-046) |
| tags | id, organization_id, name — unique (organization_id, name) (ADR-047) |
| monitor_tag | monitor_id, tag_id (PK both) |

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
- `notification_deliveries` is unique on `(incident_id, channel_id, event_type)` — the idempotency key of the delivery pipeline. Certificate deliveries are unique on `(certificate_alert_id, channel_id)` and domain deliveries on `(domain_alert_id, channel_id)`; a CHECK constraint requires exactly one of `incident_id`, `certificate_alert_id` and `domain_alert_id` (ADR-026, ADR-043).
- Raw-check retention is per organization (`Quota::checkRetentionDays()`, ADR-029): `VEYRA_CHECK_RETENTION_DAYS` on the Community edition, an entitlement otherwise.
- `monitor_daily_stats` (PK `(monitor_id, day)`, UTC days) is maintained with `INSERT … SELECT … ON DUPLICATE KEY UPDATE`; it survives raw-check retention.
- Retention deletes use `DELETE … LIMIT 1000` loops on indexed columns (`monitor_checks (monitor_id, checked_at)`, `monitor_dispatches (status, completed_at)`, `notification_deliveries (created_at)`). `tests/Integration/AggregationTest.php` covers 10 000 checks.

## Maintenance and status pages (Sprint 6)
- `monitor_checks.in_maintenance` (boolean, default false) is a trailing column added instantly by MariaDB 11.4; it is not indexed (always read with the existing `(monitor_id, …)` indexes). `monitor_daily_stats.maintenance_count` stores flagged checks per day.
- `maintenance_windows` is indexed by `(organization_id, starts_at)` and `(starts_at, ends_at)`; `maintenance_window_monitors` has PK `(maintenance_window_id, monitor_id)` and an index on `monitor_id`.
- `status_pages.slug` and `status_pages.custom_domain` are globally unique (several NULL domains are allowed). `status_page_components` is unique on `(status_page_id, monitor_id)`.
- Pivot and component rows cascade on hard deletes only (organization deletion); soft-deleted monitors are filtered out when building public pages.

## Status page audience (Sprint 14)
- `status_page_assets` is unique on `(status_page_id, kind)`; `content` is altered to `MEDIUMBLOB` (16 MB max, the application caps it at 256 KB) because `Blueprint::binary()` creates a 64 KB `BLOB`.
- `status_page_subscribers` is unique on `(status_page_id, email_hash)` (the HMAC of the normalized address; the address itself is encrypted text and cannot be indexed), indexed by `(status_page_id, confirmed_at)` for fan-out, `(organization_id, created_at)` for limits and `(confirmed_at, created_at)` for the purge.
- `status_page_announcements` is unique on `(status_page_id, subject_id, event)` (`sp_announcements_page_subject_event_unique`) and `status_page_subscriber_deliveries` on `(announcement_id, subscriber_id)` (`sp_deliveries_announcement_subscriber_unique`): explicit names, the generated ones exceed MariaDB's 64-character limit. Both are indexed by `created_at` for the batched 30-day purge in `PurgeExpiredHistory`; unconfirmed subscribers are purged after 7 days.

## Monitor types (Sprint 7)
- `monitor_tcp_configs` and `monitor_heartbeat_configs` mirror `monitor_http_configs` (PK = `monitor_id`, cascade on hard delete).
- `monitors.interval_seconds` is widened from `SMALLINT UNSIGNED` to `INT UNSIGNED` for heartbeat periods up to one week (non-destructive `ALTER`).
- `scheduler_beats` (PK `beat_at`, one row per minute) is written by `veyra:heartbeat` and purged by `veyra:history:purge` after 9 days; gap detection uses the `LAG()` window function (MariaDB 10.2+).
- Heartbeat checks reference a `monitor_dispatches` row with `trigger = ping`; the unique `(monitor_id, scheduled_for)` key deduplicates pings within the same second.
- Sprint 15 (ADR-037): `monitor_checks.slow` (boolean, default false) is added like `in_maintenance` (instant ADD COLUMN, not indexed). `monitor_http_configs.method` is widened to `VARCHAR(8)` for `PATCH`; the new columns are nullable or defaulted, so the migration is additive.
- Sprint 17 (ADR-041): `organizations.timezone` (`VARCHAR(64)`, default `UTC`), `maintenance_series` and its monitor pivot are added; `maintenance_windows` gains a nullable `series_id` (FK, `ON DELETE SET NULL`) and `occurrence_date` with a unique `(series_id, occurrence_date)` key (one-off windows keep `NULL`, which MariaDB never treats as a duplicate). Additive only.

## API tokens (Sprint 19, ADR-044)
- `api_tokens.token_hash` is unique (the authentication lookup); `(organization_id, revoked_at)` serves the management list and the per-organization cap. Rows cascade with the organization and are never deleted otherwise (revocation sets `revoked_at`); `created_by` is `ON DELETE SET NULL`. Additive only.
- API acknowledgements store the token in `incident_events.metadata` (`api_token_id`, `api_token_name`); no column was added to `incidents`.

## Escalation and weekly reports (Sprint 20, ADR-045)
- `escalation_policies` (indexed by `(organization_id, is_default)`), `escalation_policy_steps` (unique `(policy_id, position)`) and the `escalation_step_channels` pivot cascade with their parents; `monitors.escalation_policy_id` is `ON DELETE SET NULL`, so deleting a policy sends its monitors back to the organization default.
- `incidents.escalation_step` and `notification_deliveries.escalation_step` are `TINYINT UNSIGNED DEFAULT 0`. The delivery key becomes `(incident_id, channel_id, event_type, escalation_step)` (`deliveries_incident_channel_event_step_unique`), created before the old `(incident_id, channel_id, event_type)` key is dropped so the `incident_id` foreign key always keeps an index; existing rows get step 0 and stay unique.
- `organization_members.weekly_report` (boolean, default false) and `weekly_reports` (unique `(organization_id, period_start)`, cascade with the organization). Additive only.

## Audit log and tags (Sprint 21, ADR-046, ADR-047)
- `audit_logs` is append-only: no foreign key on the actor or the subject (their names are copied), `created_at` is `DATETIME(6)`, indexed by `(organization_id, created_at)`, `(organization_id, subject_type, created_at)` for the list and its filter, and `created_at` for the batched retention purge. Rows cascade with the organization only.
- `tags` cascades with the organization; `monitor_tag` (PK `(monitor_id, tag_id)`, index on `tag_id`) cascades with both. Monitors are soft deleted, so their pivot rows stay until the organization is deleted; orphan tags are removed by the application. Additive only.
