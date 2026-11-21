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
- HTTP/HTTPS monitors: GET/HEAD, intervals, timeouts, expected statuses, secure custom headers.
- Dashboard with current status, latency history, uptime and incident timeline.
- Confirmed incident creation/resolution, acknowledgement and notes.
- Email and Discord notifications, retries and delivery history.
- Reverb updates with fallback resync; dark/light responsive UI.
- Docker Compose Community installation; Vito Deploy private deployment documentation.

## Not in V1
Status pages, TCP/DNS/heartbeat probes, agent-based server metrics, multi-region checks, public API, billing, SSO, enterprise SLAs.

## Acceptance definition
A fresh installation can create an organization and monitor, execute scheduled checks, detect confirmed downtime, send an alert, recover automatically and display history without manual intervention.
