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
