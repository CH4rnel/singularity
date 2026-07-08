export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    wallet_address?: string | null;
    solana_wallet_address?: string | null;
    twitter_id?: string | null;
    twitter_username?: string | null;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
};

export type TwoFactorConfigContent = {
    title: string;
    description: string;
    buttonText: string;
};
