---
paths:
  - "app/**/*.php"
  - "routes/**/*.php"
---
# Laravel backend rules
- Follow Laravel 13 conventions and use strict types where useful.
- Controllers orchestrate, Actions/Services implement business logic.
- Use Form Requests for validation, Policies for authorization.
- Prefer transactions for state changes; publish events/jobs after commit.
- Queue jobs must be idempotent, bounded, retry-safe and observable.
- No new dependencies without a documented reason.
