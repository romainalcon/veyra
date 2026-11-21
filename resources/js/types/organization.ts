export type OrganizationRole = 'owner' | 'admin' | 'member' | 'viewer';

export type OrganizationSummary = {
    id: string;
    name: string;
    slug: string;
};

export type CurrentOrganization = OrganizationSummary & {
    role: OrganizationRole;
    openIncidents: number;
    can: {
        update: boolean;
        delete: boolean;
        manageMembers: boolean;
    };
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
