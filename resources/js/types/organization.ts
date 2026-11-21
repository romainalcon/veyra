export type OrganizationRole = 'owner' | 'admin' | 'member' | 'viewer';

export type OrganizationSummary = {
    id: string;
    name: string;
    slug: string;
};

/** Counted features an edition may limit (ADR-029). */
export type LimitFeature =
    | 'monitors'
    | 'members'
    | 'status_pages'
    | 'custom_domains'
    | 'notification_channels';

export type LimitUsage = {
    used: number;
    limit: number;
};

export type CurrentOrganization = OrganizationSummary & {
    role: OrganizationRole;
    openIncidents: number;
    can: {
        update: boolean;
        delete: boolean;
        manageMembers: boolean;
    };
    /** Only limited features are present; empty on the Community edition. */
    limits: Partial<Record<LimitFeature, LimitUsage>>;
};

export type Member = {
    id: string;
    name: string;
    email: string;
    role: OrganizationRole;
    joined_at: string;
    is_current_user: boolean;
    can_update: boolean;
    can_remove: boolean;
};

export type PendingInvitation = {
    id: string;
    email: string;
    role: OrganizationRole;
    expires_at: string;
    can_revoke: boolean;
};

export type RoleOption = {
    value: OrganizationRole;
    label: string;
};
