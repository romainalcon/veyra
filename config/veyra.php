<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Edition
    |--------------------------------------------------------------------------
    |
    | The running edition of Veyra. The Community core never depends on Cloud
    | code; Cloud-only capabilities will be resolved through explicit
    | contracts when they are introduced (see docs/DECISIONS.md, ADR-011).
    |
    */

    'edition' => env('VEYRA_EDITION', 'community'),

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
    | Organization Invitations
    |--------------------------------------------------------------------------
    */

    'invitations' => [
        'ttl_hours' => (int) env('VEYRA_INVITATION_TTL_HOURS', 168),
        'max_per_hour' => (int) env('VEYRA_INVITATIONS_PER_HOUR', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Probes
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
        'max_redirects' => 3,
        'user_agent' => 'Veyra-Uptime-Monitor/1.0',
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
        'stale_grace_seconds' => 120,
        'check_retention_days' => max(1, (int) env('VEYRA_CHECK_RETENTION_DAYS', 30)),
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

    'admin_emails' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('VEYRA_ADMIN_EMAILS', '')),
    ))),

];
