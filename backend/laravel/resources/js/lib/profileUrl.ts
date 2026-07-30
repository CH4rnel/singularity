import { legacy as legacyProfile } from '@/routes/users';

type PublicProfileUser = {
    profile_url?: string | null;
};

export function profileUrl(
    user: PublicProfileUser | null | undefined,
    userId: number,
): string {
    return user?.profile_url || legacyProfile(userId).url;
}
