---
name: review-feature
description: Review Veyra changes for correctness, security, tenancy, monitoring reliability, and maintainability.
---
# Review feature
1. Review diff and affected architecture/specs.
2. Prioritize bugs and risks over style observations.
3. Check authorization, tenant scoping, SSRF, secrets, concurrency, idempotency, and retries.
4. Check MariaDB migration and index compatibility.
5. Check tests and run relevant checks if possible.
6. Report findings by severity with file paths, then unresolved test gaps.
