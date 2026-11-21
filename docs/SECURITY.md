# Veyra — Security baseline

## Tenant isolation
Every tenant-owned query, policy, job and broadcast channel must enforce `organization_id`. Test IDOR cases and cross-organization foreign-key associations.

## SSRF
Reject forbidden destination IP ranges for IPv4/IPv6, including loopback, private, link-local, multicast and cloud metadata. Validate each DNS answer and redirect; bind connections to the validated IP to prevent rebinding. Restrict ports, methods, redirects, response size and duration. Apply OS/container network egress restrictions to probe workers.

## Secrets
Encrypt notification credentials and sensitive HTTP headers. Redact credentials and URL query tokens in logs, error messages, events and Inertia props. Never commit `.env` files or production keys.

## Operational controls
Use rate limits, idempotency keys, bounded queue retries, health checks, audited administrative changes, secure session cookies, CSRF protection, and backups with restore drills. Reverb private channels must verify tenant membership.

## Probe destinations (implemented in Sprint 2)
- Always blocked: loopback, unspecified, link-local (incl. `169.254.169.254`), `fd00:ec2::254`, `100.100.100.200`, multicast, broadcast/reserved, documentation and benchmark ranges, NAT64, 6to4, Teredo, IPv4-compatible/mapped IPv6 (mapped addresses are normalized and re-checked).
- Private networks (RFC 1918, CGNAT `100.64.0.0/10`, ULA `fc00::/7`) are blocked unless the operator sets `VEYRA_PROBES_ALLOW_PRIVATE_NETWORKS=true`. This is an instance setting, never an organization setting, and it is forced off when `VEYRA_EDITION=cloud`.
- Ports: `VEYRA_PROBES_ALLOWED_PORTS` (default `80,443,8080,8443`). Methods: GET/HEAD. No credentials in URLs.
- A host is refused if **any** of its A/AAAA records is forbidden.
- Custom headers are stored with Laravel encryption (`encrypted:array`), shown by name only, never returned to the browser, never logged, and not forwarded across hosts on redirect.
- Logs use `UrlRedactor` (no query string, no credentials) and never include exception messages from cURL.
- Rate limits: monitor creation (`VEYRA_MONITORS_PER_HOUR`, default 30/user), manual checks (6/min per user, 1 per 30 s per monitor).
- Application checks do not replace network egress filtering of the Horizon workers (see deployment guides).

## Real-time payloads (Sprint 3)
Broadcasts on `private-organizations.{id}` carry identifiers and enum values only (`monitor_id`, `incident_id`, `status`). Clients fetch data over HTTP, where policies apply again; the channel itself is authorized for members only.

## Notification channels (Sprint 4)
- Channel configuration (recipients, Discord webhook URL with its token) is stored with Laravel encryption, managed by Admin/Owner only, never returned to the browser (webhooks are summarized as "…abcd"; recipients are only shown to channel managers).
- Discord webhooks must be `https://{discord.com|discordapp.com|ptb.discord.com|canary.discord.com}/api/webhooks/{id}/{token}`; the URL is re-validated before each send, requests use a 5 s timeout, no redirects and no proxy, and `allowed_mentions` is empty so monitor names or causes can never ping anyone.
- Delivery errors are stored as generic messages (HTTP status or exception class); webhook URLs, tokens and recipient addresses never reach logs or `last_error`.
- Rate limits: channel creation 30/min, "send test" 5/min per channel.
