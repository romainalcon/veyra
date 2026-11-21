---
paths:
  - "database/**/*"
  - "app/**/Models/**/*.php"
---
# MariaDB rules
- Target MariaDB 11.4+ using Laravel `mysql` connection.
- No PostgreSQL-only column types, indexes or SQL syntax.
- UUIDv7 business identifiers; UTC timestamps; `json` for variable metadata.
- Use composite indexes for tenant lookups and `(monitor_id, checked_at)` for history.
- Keep V1 checks in a regular indexed table; implement chunked retention cleanup.
- Use migrations for all schema changes and test migrations against MariaDB.
- Beware MariaDB JSON is an alias backed by text, not PostgreSQL JSONB.
