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
};
