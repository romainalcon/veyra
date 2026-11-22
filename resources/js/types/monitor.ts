import type { ChannelOption } from './notification';

export type HttpMethod = 'GET' | 'HEAD' | 'POST' | 'PUT' | 'PATCH';

export type KeywordMode = 'contains' | 'not_contains';

export type MonitorType = 'http' | 'tcp' | 'dns' | 'heartbeat';

export type DnsRecordType = 'A' | 'AAAA' | 'CNAME' | 'MX' | 'TXT' | 'NS';

export type DnsMatchMode = 'contains' | 'exact';

export type DnsResolverOption = { value: string; label: string };

export type MonitorTypeOption = { value: MonitorType; label: string };

export type MonitorStatus =
    'up' | 'degraded' | 'down' | 'unknown' | 'maintenance';

export type MonitorState = {
    status: MonitorStatus;
    last_changed_at: string | null;
    active_incident_id: string | null;
};

export type MonitorCheck = {
    id: string;
    checked_at: string;
    success: boolean;
    /** Successful, but slower than the monitor's latency threshold. */
    slow: boolean;
    duration_ms: number | null;
    status_code: number | null;
    error_code: string | null;
    error_label: string | null;
    redirects: number;
    in_maintenance: boolean;
};

export type MonitorSummary = {
    id: string;
    name: string;
    type: MonitorType;
    target: string | null;
    url: string | null;
    method: HttpMethod | null;
    enabled: boolean;
    interval_seconds: number;
    latest_check: MonitorCheck | null;
    state: MonitorState;
};

export type CertificateStatus = 'valid' | 'expiring' | 'expired';

/** Last TLS certificate seen by an HTTPS monitor (public data only). */
export type MonitorCertificate = {
    subject: string | null;
    issuer: string | null;
    not_before: string;
    not_after: string;
    checked_at: string;
    days_left: number;
    status: CertificateStatus;
};

/** Query of a DNS monitor and the last answers received (bounded). */
export type MonitorDns = {
    record_name: string;
    record_type: DnsRecordType;
    resolver: string;
    resolver_label: string;
    expected_values: string[];
    match_mode: DnsMatchMode;
    /** Null until a name server answered; non-public addresses are redacted. */
    last_answers: string[] | null;
    last_resolved_at: string | null;
};

export type DomainStatus = 'unknown' | 'valid' | 'expiring' | 'expired';

/** Registration expiry of the monitored domain, read through RDAP. */
export type MonitorDomain = {
    host: string;
    domain: string | null;
    expires_at: string | null;
    registrar: string | null;
    checked_at: string | null;
    error: string | null;
    days_left: number | null;
    status: DomainStatus;
};

export type MonitorDetail = MonitorSummary & {
    timeout_seconds: number;
    expected_statuses: string | null;
    follow_redirects: boolean;
    header_names: string[];
    /** The body itself is never sent to the browser. */
    body: { content_type: string | null; bytes: number | null } | null;
    basic_auth_username: string | null;
    keyword: string | null;
    keyword_mode: KeywordMode | null;
    keyword_case_sensitive: boolean;
    latency_threshold_ms: number | null;
    host: string | null;
    port: number | null;
    heartbeat: {
        grace_seconds: number;
        last_ping_at: string | null;
        last_ping_failed: boolean;
        ping_url_prefix: string;
    } | null;
    dns: MonitorDns | null;
    domain: MonitorDomain | null;
    certificate: MonitorCertificate | null;
    notification_channel_ids: string[];
    next_check_at: string | null;
    created_at: string | null;
};

export type IntervalOption = { value: number; label: string };

export type MonitorFormOptions = {
    channels: ChannelOption[];
    intervals: IntervalOption[];
    methods: HttpMethod[];
    contentTypes: string[];
    keywordModes: KeywordMode[];
    maxBodyBytes: number;
    types: MonitorTypeOption[];
    tcpPorts: number[];
    heartbeatPeriods: IntervalOption[];
    maxGraceMinutes: number;
    dnsRecordTypes: DnsRecordType[];
    dnsResolvers: DnsResolverOption[];
    dnsMatchModes: DnsMatchMode[];
    maxDnsExpectedValues: number;
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
