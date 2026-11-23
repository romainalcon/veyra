export type IncidentStatus = 'open' | 'resolved';

export type IncidentSummary = {
    id: string;
    status: IncidentStatus;
    cause: string | null;
    started_at: string;
    resolved_at: string | null;
    acknowledged_at: string | null;
    duration_seconds: number;
    monitor: { id: string; name: string; deleted: boolean } | null;
};

export type IncidentEvent = {
    id: string;
    type: string;
    label: string;
    message: string | null;
    user: string | null;
    created_at: string;
};

/** What the server answered on the first failing check (ADR-050). */
export type IncidentEvidence = {
    checked_at: string;
    status_code: number;
    http_version: string | null;
    error_code: string;
    error_label: string;
    content_type: string | null;
    headers: Record<string, string>;
    body_excerpt: string | null;
    body_truncated: boolean;
    body_omitted_reason: 'head' | 'empty' | 'binary' | 'encoded' | null;
};

export type IncidentDetail = IncidentSummary & {
    acknowledged_by: string | null;
    events: IncidentEvent[];
    evidence: IncidentEvidence | null;
};
