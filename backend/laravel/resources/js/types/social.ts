export type SocialUser = {
    id: number;
    name: string;
    avatar: string | null;
    profile_url: string;
    wallet_address: string | null;
};

export type Post = {
    id: number;
    user_id: number;
    body: string;
    created_at: string;
    updated_at: string;
    user: SocialUser;
};
