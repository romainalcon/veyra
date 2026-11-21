export type PublicComponentStatus =
    'operational' | 'degraded' | 'outage' | 'maintenance' | 'no_data';

export type PublicUptimeDay = {
    day: string;
    uptime: number | null;
    coverage: number | null;
};

export type PublicComponent = {
    id: string;
    name: string;
    status: PublicComponentStatus;
    uptime_90d: number | null;
    days: PublicUptimeDay[];
};

export type PublicIncidentUpdate = {
    key: string;
    type: 'investigating' | 'update' | 'resolved';
    message: string | null;
    at: string;
};

export type PublicIncident = {
    key: string;
    title: string;
    component: string;
    status: 'ongoing' | 'resolved';
    started_at: string;
    resolved_at: string | null;
    updates: PublicIncidentUpdate[];
};

export type PublicMaintenance = {
    key: string;
    title: string;
    description: string | null;
    starts_at: string;
    ends_at: string;
    active: boolean;
    components: string[];
};

export type StatusPageSnapshot = {
    page: { title: string; description: string | null; show_uptime: boolean };
    status: PublicComponentStatus;
    groups: { name: string | null; components: PublicComponent[] }[];
    incidents: PublicIncident[];
    maintenance: PublicMaintenance[];
    generated_at: string;
};
