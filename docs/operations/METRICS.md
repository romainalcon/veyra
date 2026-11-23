# Prometheus metrics

`GET /metrics` exposes the health of the Veyra **instance** (queues, outbox, scheduler, Horizon, probe durations, notification deliveries) in the Prometheus text format (ADR-048). It does not describe the monitored services: their uptime and incidents stay in Veyra.

## Enable and protect
The endpoint is **disabled by default** and answers 404.

| Variable | Meaning |
|---|---|
| `VEYRA_METRICS_ENABLED` | `true` to serve `/metrics` |
| `VEYRA_METRICS_TOKEN` | Bearer token (32 characters or more, e.g. `openssl rand -hex 32`) |
| `VEYRA_METRICS_ALLOWED_IPS` | Comma-separated IPs or CIDR ranges, IPv4 or IPv6 (`10.0.0.0/8,2001:db8::/32`) |

- With a token, scrapes need `Authorization: Bearer <token>` (401 otherwise).
- With an allow-list, the client address must match (403 otherwise). Behind a reverse proxy the address comes from `X-Forwarded-For` only when the proxy is listed in `TRUSTED_PROXIES`.
- With both, both are required. With neither, the endpoint stays 404: it never runs open.
- Scrapes are limited to 120 per minute per address. `php artisan veyra:doctor` reports a missing restriction, a short token or a malformed range.

Prometheus job:
```yaml
scrape_configs:
  - job_name: veyra
    scheme: https
    metrics_path: /metrics
    scrape_interval: 30s
    authorization:
      type: Bearer
      credentials_file: /etc/prometheus/veyra-token
    static_configs:
      - targets: ['veyra.example.com']
```

Each scrape runs a few indexed MariaDB queries and Redis reads. A scrape interval of 15–60 s is fine. When several web nodes serve Veyra, scrape one of them (or the load balancer): every node returns the same instance-wide values.

## Metrics
Names, types and label sets are a stable contract: removing or renaming a metric or a label is a breaking change, announced in `UPGRADING.md`. New metrics or label values may be added in minor versions.

| Metric | Type | Labels | Meaning |
|---|---|---|---|
| `veyra_info` | gauge | `version` | Always 1; the running version |
| `veyra_metrics_collector_up` | gauge | `collector` (`queues`, `outbox`, `scheduler`, `horizon`, `monitors`, `counters`) | 0 when a source (MariaDB, Redis, Horizon) could not be read during the scrape; that collector's metrics are missing or partial |
| `veyra_queue_jobs` | gauge | `queue` (`probes`, `incidents`, `notifications`, `maintenance`, `default`), `state` (`pending`, `delayed`, `reserved`) | Jobs in the Redis queue |
| `veyra_outbox_dispatches` | gauge | `status` (`pending`, `dispatched`) | Scheduled checks not completed: not yet pushed to Redis, or pushed and waiting for a result |
| `veyra_outbox_oldest_age_seconds` | gauge | `status` | Seconds since the oldest pending dispatch was due, and since the oldest dispatched one was pushed (0 when none) |
| `veyra_outbox_failed_dispatches_last_hour` | gauge | — | Dispatches given up in the last hour: checks that never ran |
| `veyra_scheduler_heartbeat_age_seconds` | gauge | — | Seconds since the last `veyra:heartbeat` (`+Inf` when none was ever recorded). Above 180 the scheduler is considered stopped and monitors turn UNKNOWN |
| `veyra_horizon_master_supervisors` | gauge | `status` (`running`, `paused`) | Horizon master supervisors |
| `veyra_horizon_recent_failed_jobs` | gauge | — | Jobs failed within Horizon's recent failed retention window |
| `veyra_monitors` | gauge | `state` (`up`, `degraded`, `down`, `unknown`, `maintenance`) | Enabled monitors of the whole instance by confirmed state |
| `veyra_probe_duration_seconds` | histogram | `type` (`http`, `tcp`, `dns`), `result` (`success`, `failure`), `le` | Duration of recorded probes. Buckets: 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10, 30 s |
| `veyra_notification_deliveries_total` | counter | `channel` (channel type), `event` (`opened`, `resolved`, `escalated`, …), `result` (`sent`, `failed`) | Finished notification deliveries; `failed` means the last attempt failed |

**No tenant data.** Labels only take values from fixed lists (types, states, queue names). No organization, monitor, URL, user or channel name ever appears in a metric. `veyra_monitors` is a count over the whole instance.

**Counters** (`veyra_probe_duration_seconds`, `veyra_notification_deliveries_total`) are kept in Redis and shared by all web and Horizon processes. They count only while metrics are enabled, and reset when Redis is flushed; Prometheus treats that as a counter reset (`rate()` and `increase()` stay correct). Heartbeat monitors are not timed: a ping has no probe duration.

## Grafana and alerts
- `grafana-dashboard.json`: import it in Grafana (**Dashboards → New → Import**) and pick your Prometheus data source. Panels: scheduler heartbeat, outbox backlog and age, queues, Horizon, monitors by state, probe duration percentiles and failure rate by type, notification deliveries.
- `prometheus-alerts.yml`: alerting rules to load in Prometheus (`rule_files`). Thresholds are starting points; adjust them to your instance.

| Alert | Fires when | First things to check |
|---|---|---|
| `VeyraMetricsUnavailable` | the target is down or a collector failed | `/health`, MariaDB and Redis reachability |
| `VeyraSchedulerStopped` | no scheduler heartbeat for 3 minutes | the `scheduler` container or the cron entry (`schedule:run`) |
| `VeyraHorizonDown` | no running Horizon master | `php artisan horizon:status`, supervisor |
| `VeyraOutboxBacklog` | the oldest pending dispatch is more than 2 minutes late | the scheduler, Redis |
| `VeyraProbesStuck` | a dispatched probe has had no result for 10 minutes | Horizon workers, the `probes` queue |
| `VeyraProbeQueueBacklog` | more than 1,000 jobs wait on `probes` for 10 minutes | add `probes` workers (`config/horizon.php`) |
| `VeyraDispatchesFailing` | dispatches were given up in the last hour | Redis, Horizon logs |
| `VeyraNotificationFailures` | more than 20 % of deliveries failed over 15 minutes | channel credentials and the deliveries list in the UI |
| `VeyraManyMonitorsUnknown` | more than 20 % of monitors are UNKNOWN | worker or scheduler outage (missing data, not downtime) |

Monitor the instance from outside too: an alert that needs Prometheus is lost when Prometheus runs on the same failed host. Point an external uptime check at `/health`.
