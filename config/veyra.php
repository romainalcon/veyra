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

    'monitors' => [
        'max_per_hour' => (int) env('VEYRA_MONITORS_PER_HOUR', 30),
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
