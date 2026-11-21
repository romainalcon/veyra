---
paths:
  - "tests/**/*"
---
# Testing rules
- Use Pest for PHP, Vitest for isolated frontend behavior.
- Test each feature's success, failure, authorization, and tenant isolation paths.
- For monitoring, test duplicate jobs, delayed/out-of-order results, retries and recovery.
- Run integration tests against MariaDB rather than relying solely on SQLite.
- Never report a test as passing unless executed.
