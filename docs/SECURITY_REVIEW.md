# Veyra V1 — Security review

Scope: Community core at the end of Sprint 5 (Laravel 13 / Inertia 3 monolith, MariaDB, Redis, Horizon, Reverb). Method: code review of every domain, targeted automated tests (Pest, MariaDB integration), dependency audits. This is an internal review, **not** an external penetration test — schedule one before a public Cloud offering.

## Threat model (summary)
| Actor | Goal | Main controls |
|---|---|---|
| Anonymous visitor | Account takeover, enumeration, abuse of sign-up | Fortify login throttling (5/min), e-mail verification, closable registration, generic errors, 404 on unknown tenants |
| Authenticated user of another organization | Read/modify foreign data (IDOR) | URL tenancy middleware (404), scoped route bindings, policies re-checking `organization_id`, whitelisted Inertia props, private broadcast channels |
| Member with low role | Privilege escalation | Role enum capabilities, policies on every action, last-owner invariant under row locks, Form Request authorization before validation |
| Monitor author | SSRF / internal scanning through probes | URL/port policy, every DNS answer and redirect validated, IP pinning (`CURLOPT_RESOLVE`), no proxies, private ranges opt-in only, rate limits |
| Notification channel author | SSRF through webhooks, secret exfiltration | Discord host allow-list re-validated at send time, no redirects, encrypted config, secrets never sent to the browser |
| Operator mistakes | Debug mode, http, lost APP_KEY | `veyra:doctor`, documented backups including APP_KEY |

## Checklist
| Area | Status | Evidence |
|---|---|---|
| Tenant isolation | ✅ | `tests/Feature/Security/TenancySweepTest.php` sweeps **every** route with `{organization}` (all methods, real foreign IDs) → 404; IDOR tests per domain |
| Authorization | ✅ | Policies for organizations, monitors, incidents, channels; role matrix unit tests; viewer/member 403 tests |
| SSRF | ✅ | `AddressPolicy`, `UrlPolicy`, `DestinationGuard`, `HttpProbe`; `tests/Feature/Security/SsrfEdgeCasesTest.php` (decimal/octal/hex IPs, `localhost.`, IPv4-mapped IPv6, metadata, relative redirect to another port, DNS flip after validation); real cURL integration test |
| Secrets at rest | ✅ | `encrypted:array` for headers and channel configuration; tests read raw columns |
| Secrets in transit to the browser | ✅ | Presenters whitelist props; tests assert absence in props and HTML |
| Logging | ✅ | `UrlRedactor`; cURL messages never logged; delivery errors generic; tests with `Log::spy` |
| CSRF / sessions | ✅ | Laravel CSRF on all state-changing web routes; secure cookies enforced by `veyra:doctor` in production |
| XSS | ✅ | Vue escaping; no `v-html`; CSP `script-src 'self' 'nonce-…'`, `object-src 'none'`, `frame-ancestors 'none'` (`SecurityHeadersTest`) |
| Clickjacking / headers | ✅ | `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy`, `Permissions-Policy`, HSTS over HTTPS |
| Mass assignment | ✅ | `#[Fillable]` on every model; immutable slugs/tenants; DTOs from validated input |
| Rate limiting | ✅ | Login, invitations, monitor creation, manual checks, notes/acks, channel creation and tests, `/health` |
| Concurrency | ✅ | Row locks + MariaDB integration tests (ownership, state machine, scheduler with a competing transaction) |
| Idempotency | ✅ | Unique keys on dispatches, checks, deliveries; replay tests |
| Broadcasting | ✅ | Private channel per organization, members only; identifiers-only payloads |
| Dependencies | ✅ | `composer audit` and `npm audit --omit=dev`: no known vulnerabilities (2026-11-21); both run in CI |
| Health endpoint | ✅ | Public output limited to status and version; details behind a constant-time compared bearer token |

## Findings fixed during the review
1. **Dispatch status race** — the relay and the manual check pushed the job *before* marking the dispatch `dispatched`; a fast worker's `completed` status could be overwritten, causing a redundant second HTTP probe five minutes later (no duplicate check thanks to the unique key). Fixed by marking first, plus a guard that skips probing when a check already exists. Covered by `OutboxTest`.
2. **Disguised hosts** — numeric/hex host names (`2130706433`, `0x7f.1`) and `localhost`/`*.localhost` are now rejected before DNS resolution; `.local`/`.internal`/`.lan` only with the private-network opt-in.
3. **Authorization order** — channel Form Requests validated before authorizing (members saw validation errors instead of 403). Authorization now runs first.

## Residual risks and recommendations
- **Network egress** is the operator's responsibility (documented for Docker and Vito); application checks are defence in depth, not a firewall.
- **Private-network opt-in** lets any Member probe internal hosts on that instance: enable it only when all members are trusted.
- **Style CSP** allows `'unsafe-inline'` for style attributes required by UI components.
- **Brute force across IPs** is limited per e-mail+IP; consider an upstream WAF or fail2ban for public instances.
- **Licensing** — `@unovis/ts` depends on `elkjs` (EPL-2.0); it is not included in the built bundle (direct subpath imports), verified on 2026-11-21. Re-check when upgrading `@unovis`. `nette/*` are used under their BSD-3-Clause option.
- **External penetration test** and a responsible-disclosure policy (`SECURITY.md` contact) are recommended before public launch.
