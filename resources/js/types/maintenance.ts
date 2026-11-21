export type MaintenanceStatus =
    'scheduled' | 'active' | 'completed' | 'cancelled';

export type MaintenanceWindow = {
    id: string;
    title: string;
    description: string | null;
    starts_at: string;
    ends_at: string;
    cancelled_at: string | null;
    status: MaintenanceStatus;
    creator: string | null;
    monitors: { id: string; name: string }[];
};

export type MaintenanceMonitorOption = { id: string; name: string };

/** Running or next scheduled window of one monitor. */
export type MonitorMaintenance = {
    title: string;
    starts_at: string;
    ends_at: string;
    active: boolean;
};
