# Veyra — Security baseline

## Tenant isolation
Every tenant-owned query, policy, job and broadcast channel must enforce `organization_id`. Test IDOR cases and cross-organization foreign-key associations.

## SSRF
Reject forbidden destination IP ranges for IPv4/IPv6, including loopback, private, link-local, multicast and cloud metadata. Validate each DNS answer and redirect; bind connections to the validated IP to prevent rebinding. Restrict ports, methods, redirects, response size and duration. Apply OS/container network egress restrictions to probe workers.

## Secrets
Encrypt notification credentials and sensitive HTTP headers. Redact credentials and URL query tokens in logs, error messages, events and Inertia props. Never commit `.env` files or production keys.

## Operational controls
Use rate limits, idempotency keys, bounded queue retries, health checks, audited administrative changes, secure session cookies, CSRF protection, and backups with restore drills. Reverb private channels must verify tenant membership.
