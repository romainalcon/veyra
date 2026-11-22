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
    /** 24h / 7d / 30d uptime, empty when the page hides uptime. */
    uptime: Partial<Record<'24h' | '7d' | '30d', number | null>>;
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

/** Accent and branding files of a public page (ADR-036). */
export type StatusPageBranding = {
    accent: string | null;
    accent_foreground: string | null;
    /** Whether the accent is readable as text on the light / dark background. */
    text_light: boolean;
    text_dark: boolean;
    logo: { file: string; width: number; height: number } | null;
    favicon: string | null;
};

export type StatusPageSnapshot = {
    page: {
        title: string;
        description: string | null;
        show_uptime: boolean;
        subscriptions_enabled: boolean;
        branding: StatusPageBranding;
    };
    status: PublicComponentStatus;
    groups: { name: string | null; components: PublicComponent[] }[];
    incidents: PublicIncident[];
    maintenance: PublicMaintenance[];
    generated_at: string;
};

export type StatusPageSummary = {
    id: string;
    title: string;
    slug: string;
    is_published: boolean;
    components_count: number;
    public_url: string;
    custom_url: string | null;
};

export type StatusPageComponentInput = {
    monitor_id: string;
    display_name: string;
    group_name: string | null;
};

export type StatusPageDomain = {
    name: string;
    verified: boolean;
    verified_at: string | null;
    checked_at: string | null;
    failures: number;
    txt_name: string;
    txt_value: string;
};

export type StatusPageDetail = StatusPageSummary & {
    description: string | null;
    show_uptime: boolean;
    accent_color: string | null;
    subscriptions_enabled: boolean;
    domain: StatusPageDomain | null;
    components: StatusPageComponentInput[];
};

export type StatusPageFormOptions = {
    monitors: { id: string; name: string }[];
    maxComponents: number;
};

export type StatusPageAssetKind = 'logo' | 'favicon';

export type StatusPageAsset = {
    url: string;
    width: number;
    height: number;
    size: number;
};

/** Branding, subscribers (counts only), feeds and badges of a page. */
export type StatusPageAudience = {
    assets: Record<StatusPageAssetKind, StatusPageAsset | null>;
    limits: Record<
        StatusPageAssetKind,
        { max_kb: number; max_dimension: number; accept: string }
    >;
    subscribers: { confirmed: number; pending: number };
    feeds: { atom: string; rss: string };
    badges: { name: string; status: string; uptime: string | null }[];
    public_base: string;
};

export type SubscriptionPageState =
    'confirm' | 'confirmed' | 'unsubscribe' | 'unsubscribed' | 'invalid';
