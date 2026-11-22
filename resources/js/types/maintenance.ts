export type MaintenanceStatus =
    'scheduled' | 'active' | 'completed' | 'cancelled';

export type MaintenanceWindow = {
    id: string;
    /** Set on occurrences of a recurring series (ADR-041). */
    series_id: string | null;
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

export type MaintenanceFrequency = 'daily' | 'weekly' | 'monthly';

/** Recurring maintenance rule, local to the organization time zone. */
export type MaintenanceSeries = {
    id: string;
    title: string;
    description: string | null;
    frequency: MaintenanceFrequency;
    /** ISO-8601 days: 1 = Monday … 7 = Sunday. */
    weekdays: number[];
    month_day: number | null;
    /** HH:MM */
    start_time: string;
    duration_minutes: number;
    starts_on: string;
    ends_on: string | null;
    creator: string | null;
    monitors: { id: string; name: string }[];
};
