export type ChannelType =
    | 'email'
    | 'discord'
    | 'webhook'
    | 'slack'
    | 'ntfy'
    | 'gotify'
    | 'telegram'
    | 'teams'
    | 'pagerduty';

export type NotificationChannelSummary = {
    id: string;
    type: ChannelType;
    name: string;
    summary: string;
    enabled: boolean;
    is_default: boolean;
    monitors_count: number;
    recipients: string[];
};

export type ChannelOption = {
    id: string;
    name: string;
    type: ChannelType;
    is_default: boolean;
    enabled: boolean;
};

export type Delivery = {
    id: string;
    channel: string;
    channel_type?: ChannelType;
    event: string;
    status: 'pending' | 'sent' | 'failed';
    attempts: number;
    last_error: string | null;
    sent_at: string | null;
    monitor?: string;
    incident_id?: string | null;
    created_at?: string | null;
};
