<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Edition
    |--------------------------------------------------------------------------
    |
    | The running edition of Veyra. The Community core never depends on Cloud
    | code; organization limits are resolved through the Entitlements
    | contract, never from this value (see docs/DECISIONS.md, ADR-011/029).
    |
    */

    'edition' => env('VEYRA_EDITION', 'community'),

    /*
    |--------------------------------------------------------------------------
    | Interface Languages
    |--------------------------------------------------------------------------
    |
    | Languages with shipped translations (`lang/{code}.json`), keyed by code
    | with their native name. Each organization picks one; pages outside an
    | organization follow the browser (see docs/DECISIONS.md, ADR-027).
    |
    */

    'locales' => [
        'en' => 'English',
        'fr' => 'Français',
    ],

    /*
    |--------------------------------------------------------------------------
    | Release
    |--------------------------------------------------------------------------
    |
    | VEYRA_VERSION is injected when building release images. Veyra is
    | licensed under the AGPL-3.0: users interacting with an instance over a
    | network must be able to obtain its source code (section 13), hence the
    | source link shown in the interface.
    |
    */

    'version' => env('VEYRA_VERSION', 'dev'),

    'source_url' => env('VEYRA_SOURCE_URL', 'https://github.com/romainalcon/veyra'),

    /*
    |--------------------------------------------------------------------------
    | Public Registration
    |--------------------------------------------------------------------------
    |
    | Self-hosted instances may close public sign-ups once their operators are
    | registered. Invited users can still join through an invitation.
    |
    */

    'registration_enabled' => (bool) env('VEYRA_REGISTRATION_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Landing Page
    |--------------------------------------------------------------------------
    |
    | When enabled, guests visiting "/" see the public Veyra landing page.
    | Disabled by default so that self-hosted instances send their visitors
    | straight to the login page (see docs/DECISIONS.md, ADR-028).
    |
    */

    'landing_page' => (bool) env('VEYRA_LANDING_PAGE', false),

    /*
    |--------------------------------------------------------------------------
    | Organization Invitations
    |--------------------------------------------------------------------------
    */

    'invitations' => [
        'ttl_hours' => (int) env('VEYRA_INVITATION_TTL_HOURS', 168),
        'max_per_hour' => (int) env('VEYRA_INVITATIONS_PER_HOUR', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP and TCP Probes
    |--------------------------------------------------------------------------
    |
    | Loopback, link-local, metadata, multicast and reserved ranges are always
    | blocked. Private networks (RFC 1918, CGNAT, IPv6 ULA) may be enabled by
    | the operator of a self-hosted instance; never on Cloud.
    |
    */

    'probes' => [
        'allow_private_networks' => (bool) env('VEYRA_PROBES_ALLOW_PRIVATE_NETWORKS', false),
        'allowed_ports' => array_values(array_filter(array_map(
            'intval',
            explode(',', (string) env('VEYRA_PROBES_ALLOWED_PORTS', '80,443,8080,8443')),
        ))),
        'tcp_allowed_ports' => array_values(array_filter(array_map(
            'intval',
            explode(',', (string) env('VEYRA_PROBES_TCP_ALLOWED_PORTS', '22,25,80,443,465,587,993,995,3306,5432,6379')),
        ))),
        'max_redirects' => 3,
        'user_agent' => 'Veyra-Uptime-Monitor/1.0',
    ],

    /*
    | TLS certificate expiry warnings for HTTPS monitors, in days before expiry
    | (one warning per threshold and certificate, plus one on expiry).
    */

    'certificates' => [
        'warning_days' => array_values(array_filter(array_map(
            'intval',
            explode(',', (string) env('VEYRA_CERTIFICATE_WARNING_DAYS', '14,7,1')),
        ))),
    ],

    /*
    | DNS monitors (ADR-042): resolvers organizations may choose from (a subset
    | of system, cloudflare, google, quad9; never arbitrary addresses) and the
    | resolver configuration read for the system resolver.
    */

    'dns' => [
        'resolvers' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('VEYRA_DNS_RESOLVERS', 'system,cloudflare,google,quad9')),
        ))),
        'resolv_conf' => '/etc/resolv.conf',
    ],

    /*
    | Domain registration expiry (ADR-043), read through RDAP at most once a
    | day per monitor. Warnings use the certificate thresholds.
    */

    'domains' => [
        'enabled' => (bool) env('VEYRA_DOMAIN_EXPIRY_ENABLED', true),
        'bootstrap_url' => 'https://data.iana.org/rdap/dns.json',
        'lookups_per_run' => 200,
    ],

    'monitors' => [
        'max_per_hour' => (int) env('VEYRA_MONITORS_PER_HOUR', 30),
        // Import and export (ADR-047): requests per hour per member, monitors per file.
        'imports_per_hour' => max(1, (int) env('VEYRA_MONITOR_IMPORTS_PER_HOUR', 10)),
        'max_per_import' => 100,
    ],

    /*
    | Audit log (ADR-046): entries older than this are purged in batches.
    */

    'audit' => [
        'retention_days' => max(1, (int) env('VEYRA_AUDIT_RETENTION_DAYS', 365)),
    ],

    /*
    | Public API (ADR-044): requests per minute per token and per IP.
    */

    'api' => [
        'requests_per_minute' => max(1, (int) env('VEYRA_API_REQUESTS_PER_MINUTE', 120)),
        'requests_per_minute_per_ip' => max(1, (int) env('VEYRA_API_REQUESTS_PER_MINUTE_PER_IP', 600)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Confirmed State
    |--------------------------------------------------------------------------
    |
    | Consecutive results required to confirm DOWN / UP, and how long a monitor
    | may go without results before its state becomes UNKNOWN.
    |
    */

    'monitoring' => [
        'down_after_failures' => (int) env('VEYRA_DOWN_AFTER_FAILURES', 3),
        'up_after_successes' => (int) env('VEYRA_UP_AFTER_SUCCESSES', 2),
        'heartbeat_down_after_misses' => (int) env('VEYRA_HEARTBEAT_DOWN_AFTER_MISSES', 1),
        'heartbeat_up_after_pings' => (int) env('VEYRA_HEARTBEAT_UP_AFTER_PINGS', 1),
        'dns_down_after_failures' => (int) env('VEYRA_DNS_DOWN_AFTER_FAILURES', env('VEYRA_DOWN_AFTER_FAILURES', 3)),
        'dns_up_after_successes' => (int) env('VEYRA_DNS_UP_AFTER_SUCCESSES', env('VEYRA_UP_AFTER_SUCCESSES', 2)),
        'stale_grace_seconds' => 120,
        'check_retention_days' => max(1, (int) env('VEYRA_CHECK_RETENTION_DAYS', 30)),
    ],

    /*
    | Planned maintenance windows (one-off, UTC).
    */

    'maintenance' => [
        'max_duration_hours' => max(1, (int) env('VEYRA_MAINTENANCE_MAX_HOURS', 168)),
    ],

    'notifications' => [
        'reminder_after_minutes' => max(1, (int) env('VEYRA_REMINDER_AFTER_MINUTES', 30)),
        'max_email_recipients' => 10,
    ],

    /*
    | Status page audience (ADR-036): branding image sizes and visitor
    | subscription limits. Subscriptions per IP and per page are hourly.
    */

    'status_pages' => [
        'logo_max_kb' => 256,
        'favicon_max_kb' => 64,
        'subscriptions_per_ip_per_hour' => 5,
        'subscriptions_per_page_per_hour' => 100,
        'confirmation_resend_minutes' => 10,
        'confirmation_valid_hours' => 48,
        'unconfirmed_retention_days' => 7,
        'announcement_retention_days' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Instance Administrators
    |--------------------------------------------------------------------------
    |
    | Comma-separated e-mail addresses allowed to open instance-level tools
    | such as the Horizon dashboard in non-local environments.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Health Endpoint
    |--------------------------------------------------------------------------
    |
    | GET /health returns a minimal public status; detailed checks require
    | "Authorization: Bearer <VEYRA_HEALTH_TOKEN>".
    |
    */

    'health_token' => env('VEYRA_HEALTH_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Prometheus Metrics (ADR-048)
    |--------------------------------------------------------------------------
    |
    | GET /metrics exposes instance-level metrics (queues, outbox, scheduler,
    | probe durations, notification deliveries) in the Prometheus text format.
    | Disabled by default. Once enabled, scrapes need the bearer token, an
    | address from the allow-list (IPs or CIDR ranges, IPv4 or IPv6), or both
    | when both are set; with neither, every scrape is refused.
    |
    */

    'metrics' => [
        'enabled' => (bool) env('VEYRA_METRICS_ENABLED', false),
        'token' => env('VEYRA_METRICS_TOKEN'),
        'allowed_ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('VEYRA_METRICS_ALLOWED_IPS', ''))))),
    ],

    /*
    | Reverse proxies allowed to set X-Forwarded-* headers ("*" or a list).
    */

    'trusted_proxies' => env('TRUSTED_PROXIES'),

    /*
    | Simulated probe "latency_ms:failure_rate" used by veyra:benchmark only;
    | ignored in production.
    */

    'benchmark_probe' => env('VEYRA_BENCHMARK_PROBE'),

    /*
    |--------------------------------------------------------------------------
    | Content Security Policy
    |--------------------------------------------------------------------------
    |
    | Extra `connect-src` origins: the browser-facing Reverb WebSocket endpoint
    | (derived from VITE_REVERB_*) unless VEYRA_CSP_CONNECT_SRC overrides it.
    | Optional extensions may add the origins they need (for instance a
    | payment provider's script and checkout frame) from their provider
    | (ADR-030); without extensions these lists are empty.
    |
    */

    'csp' => [
        'connect_src' => array_values(array_filter(env('VEYRA_CSP_CONNECT_SRC')
            ? explode(' ', (string) env('VEYRA_CSP_CONNECT_SRC'))
            : (env('VITE_REVERB_HOST')
                ? [(env('VITE_REVERB_SCHEME', 'https') === 'https' ? 'wss://' : 'ws://').env('VITE_REVERB_HOST').':'.env('VITE_REVERB_PORT', 443)]
                : []))),
        'script_src' => [],
        'frame_src' => [],
        'img_src' => [],
    ],

    'admin_emails' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('VEYRA_ADMIN_EMAILS', '')),
    ))),

];
