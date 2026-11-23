# Veyra API v1

The full reference is [`openapi.yaml`](openapi.yaml) (OpenAPI 3.1). Load it in any OpenAPI viewer or client generator. Design notes are in ADR-044 (`docs/DECISIONS.md`).

## Tokens
An Owner or Admin creates tokens under **Organization → API tokens**:
- **Read only**: every `GET` endpoint (the token acts as a Viewer).
- **Read and write**: also creates, changes and deletes monitors and maintenance windows, and acknowledges incidents (it acts as a Member).

A token belongs to the organization, not to the member who created it, so it keeps working if that member leaves. It is shown once. It can expire after 30, 90 or 365 days and can be revoked at any time. Store it like a password, for example in your CI secret store, and prefer read-only tokens.

```sh
export VEYRA_URL=https://veyra.example.com
export VEYRA_TOKEN=veyra_…   # shown once when the token is created
```

Every request sends the token as a Bearer header and asks for JSON:

```sh
curl -fsS "$VEYRA_URL/api/v1/monitors" \
  -H "Authorization: Bearer $VEYRA_TOKEN" \
  -H "Accept: application/json"
```

## Examples

Create an HTTP monitor. The destination is validated like in the interface: allowed ports, public addresses only, no credentials in the URL.

```sh
curl -fsS -X POST "$VEYRA_URL/api/v1/monitors" \
  -H "Authorization: Bearer $VEYRA_TOKEN" \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{
        "type": "http",
        "name": "Website",
        "url": "https://example.com/health",
        "method": "GET",
        "interval": 60,
        "timeout": 10,
        "expected_statuses": "200-299",
        "follow_redirects": true
      }'
```

Create a heartbeat. Its ping URL is in `meta.ping_url` of this response only:

```sh
curl -fsS -X POST "$VEYRA_URL/api/v1/monitors" \
  -H "Authorization: Bearer $VEYRA_TOKEN" \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"type": "heartbeat", "name": "Nightly backup", "interval": 86400, "grace_minutes": 30}'
```

Pause a monitor during a deployment, then resume it:

```sh
curl -fsS -X POST "$VEYRA_URL/api/v1/monitors/$MONITOR_ID/pause"  -H "Authorization: Bearer $VEYRA_TOKEN" -H "Accept: application/json"
./deploy.sh
curl -fsS -X POST "$VEYRA_URL/api/v1/monitors/$MONITOR_ID/resume" -H "Authorization: Bearer $VEYRA_TOKEN" -H "Accept: application/json"
```

Or schedule a maintenance window. Checks during the window do not open incidents:

```sh
curl -fsS -X POST "$VEYRA_URL/api/v1/maintenance-windows" \
  -H "Authorization: Bearer $VEYRA_TOKEN" \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d "{
        \"title\": \"Release $(git rev-parse --short HEAD)\",
        \"starts_at\": \"$(date -u +%Y-%m-%dT%H:%M:%SZ)\",
        \"ends_at\": \"$(date -u -d '+30 minutes' +%Y-%m-%dT%H:%M:%SZ)\",
        \"monitor_ids\": [\"$MONITOR_ID\"]
      }"
```

List open incidents and acknowledge one:

```sh
curl -fsS "$VEYRA_URL/api/v1/incidents?status=open" -H "Authorization: Bearer $VEYRA_TOKEN" -H "Accept: application/json"
curl -fsS -X POST "$VEYRA_URL/api/v1/incidents/$INCIDENT_ID/acknowledge" -H "Authorization: Bearer $VEYRA_TOKEN" -H "Accept: application/json"
```

Read the latest checks of a monitor, page by page:

```sh
curl -fsS "$VEYRA_URL/api/v1/monitors/$MONITOR_ID/checks?per_page=100" -H "Authorization: Bearer $VEYRA_TOKEN" -H "Accept: application/json"
# then add &cursor=<meta.next_cursor> until next_cursor is null
```

List the monitors carrying a tag (tags are sent as `"tags": ["production"]` on create and replace; omitted, they are kept):

```sh
curl -fsS "$VEYRA_URL/api/v1/monitors?tag=production" -H "Authorization: Bearer $VEYRA_TOKEN" -H "Accept: application/json"
```

## Responses and errors
| Status | Meaning |
|---|---|
| `200`, `201`, `204` | Success. Single resources are under `data`; lists add `links` and `meta` (cursor pagination, `per_page` ≤ 100). |
| `401` | Missing, malformed, unknown, revoked or expired token. |
| `403` | The token is read-only. |
| `404` | Unknown identifier, or one that belongs to another organization. |
| `422` | Validation failed (`errors.<field>`), or an organization limit was reached (`errors.limit`). |
| `429` | Rate limited: retry after `Retry-After` seconds. The defaults are 120 requests/min per token and 600/min per IP (`VEYRA_API_REQUESTS_PER_MINUTE`, `VEYRA_API_REQUESTS_PER_MINUTE_PER_IP`). Monitor creation also follows `VEYRA_MONITORS_PER_HOUR`. |

Messages are in the organization language. Timestamps are ISO 8601 in UTC. A monitor's `status` is its confirmed state. Raw probe results are the checks.

## Not in v1
Recurring maintenance series, status pages, notification channels, members and organization settings are managed in the interface. Any breaking change will ship as `/api/v2`.
