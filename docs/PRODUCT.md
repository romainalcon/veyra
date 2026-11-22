# Veyra — Product specification

## Vision
An approachable, reliable open-core uptime monitoring platform with a polished interface.

## Editions
- **Community**: self-hosted, distributed with Docker Compose, core monitoring features.
- **Cloud**: hosted service using the same core, optional commercial entitlements/billing modules later.
- **Enterprise**: possible future SSO, audit and advanced controls; not part of V1.

The exact open-source license, commercial license boundaries and trademark/domain availability require verification before publication.

## V1 in scope
- Account registration/login, email verification and password reset.
- Organizations, membership invitations, Owner/Admin/Member/Viewer roles.
- HTTP/HTTPS monitors: GET/HEAD/POST/PUT/PATCH (encrypted body and basic auth), intervals, timeouts, expected statuses, keyword assertions, latency thresholds, secure custom headers.
- Dashboard with current status, latency history, uptime and incident timeline.
- Confirmed incident creation/resolution, acknowledgement and notes.
- Email and Discord notifications, retries and delivery history.
- Reverb updates with fallback resync; dark/light responsive UI.
- Docker Compose Community installation; Vito Deploy private deployment documentation.

## Added in 1.1 (Sprint 6)
- One-off planned maintenance windows: checks keep running, no incident or notification, excluded from uptime.
- Public status pages (`/status/{slug}` or a verified custom domain) with public incident updates.

## Added in 1.2 (Sprint 7)
- Monitor types: HTTP(S), TCP port and heartbeat (secret ping URL for cron jobs and workers), sharing states, incidents, notifications, analytics and status pages.
- TLS certificate expiry warnings for HTTPS monitors (14/7/1 days and on expiry).

## Added in 1.3 (Sprints 8–12)
- Organization language (English or French) for the interface, public status pages, notifications and invitations; optional landing page.
- Outgoing signed webhooks (Standard Webhooks) and Slack notification channels.
- Groundwork for hosted plans and optional extensions (entitlements, extension points); the Community edition limits nothing.

## Added in 1.5 (Sprints 13–16)
- Visual identity (light and dark themes, accessible status colours); public status pages stay neutral.
- Status page branding, e-mail subscriptions, Atom/RSS feeds and SVG badges.
- Advanced HTTP checks: POST/PUT/PATCH with a body and basic auth, keyword assertions, latency threshold (degraded).
- ntfy, Gotify, Telegram, Microsoft Teams and PagerDuty notification channels; two-factor authentication, which Owners can require for their organization.

## Added in 1.6 (Sprints 17–18)
- Recurring maintenance (daily, weekly, monthly) in the organization time zone, DST-safe; skip one occurrence; status pages show the next one.
- DNS monitors (A, AAAA, CNAME, MX, TXT, NS) with expected values, restricted to the system resolver or fixed public resolvers.
- Domain registration expiry warnings (RDAP) for HTTP and DNS monitors.

## Added in 1.7 (Sprint 19)
- Public REST API (`/api/v1`) for monitors, checks, incidents and maintenance windows, with organization API tokens (read or read/write, optional expiry, revocable) managed by Owners and Admins.

## Not in V1
Agent-based server metrics, multi-region checks, billing, SSO, enterprise SLAs.

## Acceptance definition
A fresh installation can create an organization and monitor, execute scheduled checks, detect confirmed downtime, send an alert, recover automatically and display history without manual intervention.
