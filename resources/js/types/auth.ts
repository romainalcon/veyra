import type { ExtensionNavItem } from './organization';

export type User = {
    id: string;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
};

export type Auth = {
    user: User | null;
    /** User menu entries contributed by optional extensions; empty on the Community edition. */
    navigation: ExtensionNavItem[];
    /** Operator acting as this user, from an optional extension; always null on the Community edition. */
    impersonation: Impersonation | null;
};

export type Impersonation = {
    operator: string;
    stopUrl: string;
};
