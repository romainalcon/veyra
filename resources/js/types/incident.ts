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

export type IncidentDetail = IncidentSummary & {
    acknowledged_by: string | null;
    events: IncidentEvent[];
};
