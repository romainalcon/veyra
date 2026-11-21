import type { ChannelOption } from './notification';

export type HttpMethod = 'GET' | 'HEAD';

export type MonitorStatus = 'up' | 'degraded' | 'down' | 'unknown';

export type MonitorState = {
    status: MonitorStatus;
    last_changed_at: string | null;
    active_incident_id: string | null;
};

export type MonitorCheck = {
    id: string;
    checked_at: string;
    success: boolean;
    duration_ms: number | null;
    status_code: number | null;
    error_code: string | null;
    error_label: string | null;
    redirects: number;
};

export type MonitorSummary = {
    id: string;
    name: string;
    url: string | null;
    method: HttpMethod | null;
    enabled: boolean;
    interval_seconds: number;
    latest_check: MonitorCheck | null;
    state: MonitorState;
};

export type MonitorDetail = MonitorSummary & {
    timeout_seconds: number;
    expected_statuses: string | null;
    follow_redirects: boolean;
    header_names: string[];
    notification_channel_ids: string[];
    next_check_at: string | null;
    created_at: string | null;
};

export type IntervalOption = { value: number; label: string };

export type MonitorFormOptions = {
    channels: ChannelOption[];
    intervals: IntervalOption[];
    methods: HttpMethod[];
};

export type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    links: PaginationLink[];
};

export type CursorPaginated<T> = {
    data: T[];
    next_cursor: string | null;
    prev_cursor: string | null;
    next_page_url: string | null;
    prev_page_url: string | null;
};
