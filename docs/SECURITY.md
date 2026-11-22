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
- Heartbeat pings (Sprint 7): `/ping/{token}` runs without session, cookies or CSRF and never reads the body; tokens are 40 random alphanumeric characters stored as SHA-256 hashes only, shown once and regenerable (Members and above); 30 pings/min per token and 600/min per IP; unknown tokens get a plain 404.
- TCP monitors (Sprint 7): same host and address rules (`HostPolicy`, `AddressPolicy`, every A/AAAA answer), a separate port allow-list `VEYRA_PROBES_TCP_ALLOWED_PORTS` (default `22,25,80,443,465,587,993,995,3306,5432,6379`), re-validated before every check; the socket connects to the validated IP (never the host name), sends and reads nothing and is closed immediately. No proxy, no TLS.
- TLS certificates (Sprint 7): the HTTPS probe enables `CURLOPT_CERTINFO` only on the first hop to the monitored host and keeps public facts of the leaf certificate (fingerprint, subject, issuer, validity); no private data exists in that chain. Certificates of redirect targets on other hosts are never recorded. Parse failures are ignored and never change a check result.
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

## Outgoing webhooks (Sprint 12a, ADR-033)
- Webhook URLs get the probe SSRF policy when saved and before every send: allowed ports (`VEYRA_PROBES_ALLOWED_PORTS`), host name rules, every DNS answer checked against the forbidden IPv4/IPv6 ranges, connection pinned to the validated IP, no redirects, no proxy, 5 s timeout, response body ignored and capped at 64 KiB. HTTPS is required unless the instance allows private networks (never on the cloud edition).
- Deliveries are signed with the Standard Webhooks scheme (HMAC-SHA256). The `whsec_` signing secret is generated server-side, stored encrypted with the channel configuration, shown once after creation or regeneration and never returned to the browser; regeneration is limited to Admins/Owners (10/min per user).
- The UI shows only the host of a webhook URL (paths and queries often carry tokens). Payloads never contain the monitored URL or host, request headers or recipients; stored errors and logs never contain the webhook URL.
- Notification workers now reach organization-chosen hosts: apply the probe egress filtering to the `notifications` workers as well (they run in the same Horizon service by default).

## Public status pages (Sprint 6)
- `/status/{slug}` runs without the `web` group: no session is started and no cookie is set; the Inertia middleware for public pages shares only the instance name, version and source link. Unpublished pages are 404.
- The payload is a whitelist (`StatusPageSnapshot`): public display names and statuses, uptime, public incident updates and maintenance title/description. Monitor IDs, names, URLs, causes, error codes, internal notes, organization slugs and user data are never published; a test asserts their absence from the rendered HTML. Public keys are per-page HMACs, not database identifiers.
- Missing data is "No data", never "Operational". Pages are cached 30 s, sent with `Cache-Control: public, max-age=30`, rate limited to 120 requests/min per IP, and refreshed by polling (no public WebSocket). The CSP (`frame-ancestors 'none'`) is unchanged.
- Management: Members and above; creation 10/h per user, updates 30/min; public incident updates 30/min.

## Custom domains (Sprint 6)
- Ownership is proven by a TXT record with a random 40-character token, renewed when the domain changes; verified domains are re-checked daily and revoked after 3 consecutive failures.
- Refused: IP literals, single-label names, `localhost`, `.local`, `.internal`, `.lan`, `home.arpa`, `.invalid`, `.onion`, ports or paths, and the `APP_URL` host and its subdomains.
- On a verified domain only `GET|HEAD /` reaches the public page; every other path or method is 404 before any session starts, so login forms and session cookies are never served on a customer-controlled host. Other hosts are not affected.
- `/status-domains/tls-check` approves certificates only for verified domains of published pages (on-demand TLS "ask"), rate limited to 60/min.
