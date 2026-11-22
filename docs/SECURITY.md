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
- Ports: `VEYRA_PROBES_ALLOWED_PORTS` (default `80,443,8080,8443`). Methods: GET, HEAD, POST, PUT, PATCH, all under the same destination, port, redirect, timeout and response-size rules (ADR-037). No credentials in URLs.
- Heartbeat pings (Sprint 7): `/ping/{token}` runs without session, cookies or CSRF and never reads the body; tokens are 40 random alphanumeric characters stored as SHA-256 hashes only, shown once and regenerable (Members and above); 30 pings/min per token and 600/min per IP; unknown tokens get a plain 404.
- TCP monitors (Sprint 7): same host and address rules (`HostPolicy`, `AddressPolicy`, every A/AAAA answer), a separate port allow-list `VEYRA_PROBES_TCP_ALLOWED_PORTS` (default `22,25,80,443,465,587,993,995,3306,5432,6379`), re-validated before every check; the socket connects to the validated IP (never the host name), sends and reads nothing and is closed immediately. No proxy, no TLS.
- DNS monitors (Sprint 18, ADR-042): queries go only to the worker's system name servers or to fixed public resolvers chosen from a list in code (`VEYRA_DNS_RESOLVERS` can only narrow it); an organization never supplies a resolver address. Record names follow `DnsRecordName`: no IP literals, `localhost`, `.local`, `.internal`, `.lan` or `.arpa` (the private suffixes are allowed only with `VEYRA_PROBES_ALLOW_PRIVATE_NETWORKS`). Responses are bounded (UDP 4 KiB read, TCP 16 KiB, 50 answers, TXT 1 KiB), must echo the query ID and question, and compression pointers may only point backwards. Addresses outside the probe policy returned by the system resolver are stored and shown as "non-public address", so split-horizon zones do not leak (residual: a mismatch result still tells whether an expected private address was returned). Port 53 (UDP and TCP) must be allowed in the worker egress rules for public resolvers and the system resolver.
- Domain expiry (Sprint 18, ADR-043): RDAP servers come only from the IANA bootstrap registry (`https://data.iana.org/rdap/dns.json`, HTTPS entries only); every request passes the probe destination policy, is pinned to the validated IP, follows no redirect, times out after 10 s and is capped (256 KiB, bootstrap 1 MiB). Only public registration data is kept (expiry, registrar name).
- TLS certificates (Sprint 7): the HTTPS probe enables `CURLOPT_CERTINFO` only on the first hop to the monitored host and keeps public facts of the leaf certificate (fingerprint, subject, issuer, validity); no private data exists in that chain. Certificates of redirect targets on other hosts are never recorded. Parse failures are ignored and never change a check result.
- A host is refused if **any** of its A/AAAA records is forbidden.
- Custom headers are stored with Laravel encryption (`encrypted:array`), shown by name only, never returned to the browser, never logged, and not forwarded across hosts on redirect.
- Logs use `UrlRedactor` (no query string, no credentials) and never include exception messages from cURL.
- Rate limits: monitor creation (`VEYRA_MONITORS_PER_HOUR`, default 30/user), manual checks (6/min per user, 1 per 30 s per monitor).
- Application checks do not replace network egress filtering of the Horizon workers (see deployment guides).

## Real-time payloads (Sprint 3)
Broadcasts on `private-organizations.{id}` carry identifiers and enum values only (`monitor_id`, `incident_id`, `status`). Clients fetch data over HTTP, where policies apply again; the channel itself is authorized for members only.

## Notification channels (Sprint 4)
- Channel configuration (recipients, Discord and Slack webhook URLs with their tokens) is stored with Laravel encryption, managed by Admin/Owner only, never returned to the browser (webhooks are summarized as "…abcd"; recipients are only shown to channel managers).
- Discord webhooks must be `https://{discord.com|discordapp.com|ptb.discord.com|canary.discord.com}/api/webhooks/{id}/{token}`; the URL is re-validated before each send, requests use a 5 s timeout, no redirects and no proxy, and `allowed_mentions` is empty so monitor names or causes can never ping anyone.
- Slack webhooks must be `https://hooks.slack.com/services/T…/B…/{token}` (no Workflow Builder `/triggers/` URLs); the URL is re-validated before each send with the same timeout, redirect and proxy rules, and every untrusted value is escaped (`&`, `<`, `>`) so it can never produce `<!channel>`, `<@user>` or a disguised link. Only Slack's short error codes are copied into `last_error`.
- Delivery errors are stored as generic messages (HTTP status or exception class); webhook URLs, tokens and recipient addresses never reach logs or `last_error`.
- Rate limits: channel creation 30/min, "send test" 5/min per channel.

## Outgoing webhooks (Sprint 12a, ADR-033)
- Webhook URLs get the probe SSRF policy when saved and before every send: allowed ports (`VEYRA_PROBES_ALLOWED_PORTS`), host name rules, every DNS answer checked against the forbidden IPv4/IPv6 ranges, connection pinned to the validated IP, no redirects, no proxy, 5 s timeout, response body ignored and capped at 64 KiB. HTTPS is required unless the instance allows private networks (never on the cloud edition).
- Deliveries are signed with the Standard Webhooks scheme (HMAC-SHA256). The `whsec_` signing secret is generated server-side, stored encrypted with the channel configuration, shown once after creation or regeneration and never returned to the browser; regeneration is limited to Admins/Owners (10/min per user).
- The UI shows only the host of a webhook URL (paths and queries often carry tokens). Payloads never contain the monitored URL or host, request headers or recipients; stored errors and logs never contain the webhook URL.
- Notification workers now reach organization-chosen hosts: apply the probe egress filtering to the `notifications` workers as well (they run in the same Horizon service by default).

## More channels (Sprint 16, ADR-039)
- ntfy and Gotify server URLs get the same probe SSRF policy as outgoing webhooks (saved and before every send, pinned connection, no redirects, no proxy, response capped at 64 KiB). Their tokens travel in headers (`Authorization: Bearer`, `X-Gotify-Key`), never in the URL, and are restricted to `[A-Za-z0-9_.-]` so they cannot inject headers.
- Telegram (`api.telegram.org`), Microsoft Teams (`*.logic.azure.com`, `*.api.powerplatform.com`, `/workflows/` paths) and PagerDuty (`events.pagerduty.com`) have fixed or allow-listed hosts, re-validated before each send. The Telegram bot token is part of the API path: exception messages are never stored or logged.
- Untrusted values are entity-escaped for Telegram's HTML mode and rendered as non-Markdown `TextRun` elements in Teams cards; ntfy, Gotify and PagerDuty receive plain text.
- Bot tokens, application tokens and routing keys are encrypted with the channel configuration and never returned to the browser (only their last four characters, the host, the topic or the chat id are shown). Provider error messages are copied into `last_error` only when they are short plain text that does not contain the secret.

## Two-factor authentication (Sprint 16, ADR-040)
- TOTP secrets and recovery codes are encrypted by Fortify and hidden from serialization; managing them requires a recent password confirmation. The QR code and setup key are only shown until the first code is confirmed; recovery codes only right after confirmation or regeneration.
- The challenge is limited to 5 attempts per minute per pending login; recovery codes are single-use and replaced when used.
- Owners can require a second factor for their organization (only with their own confirmed). Members without one are redirected away from every organization route except leaving it, and the organization's private Reverb channel is refused to them.

## Public status pages (Sprint 6)
- `/status/{slug}` runs without the `web` group: no session is started and no cookie is set; the Inertia middleware for public pages shares only the instance name, version and source link. Unpublished pages are 404.
- The payload is a whitelist (`StatusPageSnapshot`): public display names and statuses, uptime, public incident updates and maintenance title/description. Monitor IDs, names, URLs, causes, error codes, internal notes, organization slugs and user data are never published; a test asserts their absence from the rendered HTML. Public keys are per-page HMACs, not database identifiers.
- Missing data is "No data", never "Operational". Pages are cached 30 s, sent with `Cache-Control: public, max-age=30`, rate limited to 120 requests/min per IP, and refreshed by polling (no public WebSocket). The CSP (`frame-ancestors 'none'`) is unchanged.
- Management: Members and above; creation 10/h per user, updates 30/min; public incident updates 30/min.

## Custom domains (Sprint 6)
- Ownership is proven by a TXT record with a random 40-character token, renewed when the domain changes; verified domains are re-checked daily and revoked after 3 consecutive failures.
- Refused: IP literals, single-label names, `localhost`, `.local`, `.internal`, `.lan`, `home.arpa`, `.invalid`, `.onion`, ports or paths, and the `APP_URL` host and its subdomains.
- On a verified domain only `GET|HEAD /`, the feeds, badges and branding images, and `POST /subscribe` reach the public page (ADR-036); every other path or method is 404 before any session starts, so login forms and session cookies are never served on a customer-controlled host. Other hosts are not affected.
- `/status-domains/tls-check` approves certificates only for verified domains of published pages (on-demand TLS "ask"), rate limited to 60/min.

## Status page audience (Sprint 14, ADR-036)
- Branding images are identified from their bytes (signature, structure, `getimagesizefromstring()`), never from the client's name or type; SVG is refused. They are size- and dimension-limited, stored in the database and served cookie-free with `nosniff` and `Content-Security-Policy: default-src 'none'; sandbox`. Responses may set their own stricter CSP; `SecurityHeaders` no longer overrides it.
- Accent colours are validated as `#rrggbb` before use in styles; text contrast is checked server-side (`AccentColor`).
- Subscriber addresses are encrypted (`encrypted` cast) and looked up by HMAC; they never appear in the interface, Inertia props, queue payloads or logs (delivery errors keep the exception class only). The subscription POST gives the same answer for every address, has a honeypot and is rate-limited per IP and per page; the organization limit is not disclosed to visitors.
- Confirmation and unsubscribe links are signed (`URL::temporarySignedRoute` 48 h / `URL::signedRoute`). Their GET pages only show a button, are `no-store` and send no referrer; tampered or expired signatures show an "invalid link" page and change nothing.
- Announcement e-mails, feeds and badges are built from whitelisted public fields only (public names, public updates, maintenance title/description); tests assert that monitor names, causes, notes and ids are absent.

## Advanced HTTP checks (Sprint 15, ADR-037)
- Request bodies (≤ 16 KiB) and basic auth credentials are secrets like custom headers: stored with Laravel encryption (`encrypted`, `encrypted:array`), hidden from model serialization, never returned to the browser (the UI shows the content type and size of a body and the basic auth user name only; editing keeps them unless replaced), never logged, never copied into check metadata, incident causes or webhook payloads.
- Bodies, basic auth and custom headers are only sent to the monitored host: a redirect to another host drops them, and a request with a body continues as a plain GET.
- Every method goes through `DestinationGuard` on every hop (same SSRF rules); the response cap also bounds decompression for keyword checks. Monitor creation and manual-check rate limits are unchanged.
- Keywords are not secrets (they are shown to organization members) but are kept out of check results and notifications.

## Public API (Sprint 19, ADR-044)
- Organization API tokens are `veyra_` + 40 random alphanumeric characters, stored as SHA-256 hashes only, shown once after creation, never returned afterwards (lists show `veyra_…abcd`), optionally expiring, revocable at once. Only Owners and Admins create, list and revoke them (10 creations/min per user, 50 active per organization).
- Malformed, unknown, revoked and expired tokens all get the same `401`. A session cookie never authenticates the API and the API never starts a session or sets a cookie, so CSRF does not apply.
- The tenant is the token's organization, never a URL or body parameter; child identifiers are resolved through it (another organization's identifier is `404`, tested for every API route and method). `read` tokens act as Viewers and `write` tokens as Members through the interface's policies.
- Monitor and maintenance payloads go through the interface's Form Requests: same SSRF validation (every DNS answer, ports, credentials in URLs), quotas and limits. Responses are allow-listed resources without header values, bodies, basic auth passwords, ping tokens (a heartbeat's ping URL is returned once, on creation), check metadata, members' names or e-mail addresses.
- Rate limits: 120 requests/min per token and 600/min per IP (configurable), monitor creation per hour, maintenance writes and acknowledgements 30/min, keyed by token.
- Tokens are not subject to the organization's two-factor requirement (ADR-040); treat them like passwords and give automation the `read` ability unless it must write.
