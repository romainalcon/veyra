import type { MonitorStatus } from './monitor';

export type UptimeFigure = {
    uptime: number | null;
    coverage: number | null;
    partial: boolean;
    checks: number;
};

export type UptimeDay = {
    day: string;
    uptime: number | null;
    coverage: number | null;
    checks: number;
};

export type LatencyPoint = {
    t: string;
    checks: number;
    successes: number;
    failures: number;
    maintenance: number;
    latency_avg: number | null;
    latency_max: number | null;
    /** Average of each timing phase over successful checks (ADR-050). */
    phases: Record<TimingPhase, number | null>;
};

export type TimingPhase = 'dns' | 'connect' | 'tls' | 'ttfb' | 'transfer';

export type StatsRange = '24h' | '7d' | '30d' | '90d';

export type MonitorStats = {
    uptime: Record<'24h' | '7d' | '30d', UptimeFigure>;
    days: UptimeDay[];
    latency: LatencyPoint[];
};

export type IncidentDay = {
    day: string;
    incidents: number;
    downtime_minutes: number;
};

export type OrganizationAnalytics = {
    uptime: Record<'24h' | '7d' | '30d', UptimeFigure>;
    monitors: {
        id: string;
        name: string;
        status: MonitorStatus;
        uptime_7d: number | null;
        partial: boolean;
        latency_24h: number | null;
    }[];
    incidents: { days: IncidentDay[]; mttr_minutes: number | null };
};
