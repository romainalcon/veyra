<?php

/*
 * Public roadmap (ADR-049), in the order of work. Write for visitors, in
 * English, and add every string to lang/fr.json. Remove a milestone once it
 * ships: the changelog takes over. Statuses: in_progress, planned, exploring.
 * The internal plan with acceptance criteria lives in docs/ROADMAP.md.
 */

return [
    [
        'id' => 'check-diagnostics',
        'version' => '1.10',
        'status' => 'planned',
        'title' => 'Check diagnostics',
        'summary' => 'See where the time goes in every HTTP check, and what the server answered when an outage started.',
        'highlights' => [
            'Timing breakdown of each check: DNS, connection, TLS, first byte and transfer.',
            'Latency chart by phase on the monitor page.',
            'Evidence of the first failing check on the incident: status, safe headers and an excerpt of the response, with credentials never kept.',
        ],
    ],
    [
        'id' => 'dependencies-and-groups',
        'version' => '1.10',
        'status' => 'planned',
        'title' => 'Dependencies and groups',
        'summary' => 'One alert for one cause: when a server goes down, the services behind it are marked as impacted instead of paging you again.',
        'highlights' => [
            'Monitor dependencies: alerts from a monitor are held while the monitor it depends on is down.',
            'Monitor groups with an overall state, shown as a single component on status pages.',
            'Available in the interface, the API and import and export.',
        ],
    ],
    [
        'id' => 'multi-location',
        'version' => '1.11',
        'status' => 'planned',
        'title' => 'Multi-location confirmation',
        'summary' => 'Check from several locations and confirm an outage only when more than one of them sees it.',
        'highlights' => [
            'Extra locations run the same Veyra workers on other servers: no agent to install.',
            'An outage is confirmed when at least two locations fail; a silent location never counts as downtime.',
            'Results and latency per location in charts and incidents.',
        ],
    ],
];
