# Architecture rules
- Respect the domain module boundaries in `docs/ARCHITECTURE.md`.
- Keep Community independent of Cloud extensions; use explicit interfaces for entitlements.
- No new microservices, separate probe daemons, or speculative abstractions.
- Treat MariaDB as the source of truth and Redis as a transient queue/cache.
- Document architectural changes in `docs/DECISIONS.md`.
