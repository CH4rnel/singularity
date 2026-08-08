/**
 * What the wallet reads about the rest of Cyberia.
 *
 * These are the only requests in the wallet that go to Laravel for something
 * other than a price. They carry no identity — the wallet has no session and no
 * account — so every one of them is a public read, and everything they return
 * is something the site already shows on a public page.
 *
 * Nothing here ever sends an address the user did not ask about: the profile
 * lookup is keyed by the address the user is looking at, and it is the only
 * call that mentions one at all.
 */

export type FeedPerson = {
    name: string;
    avatar: string | null;
    address: string | null;
    url: string;
};

export type FeedItem = {
    kind: 'post' | 'dao';
    id: string;
    at: string | null;
    who: FeedPerson | null;
    /** Present on DAO rows: the activity key, said in the wallet's language. */
    type?: string;
    text: string | null;
    meta: string | null;
    url: string;
};

export type DaoSummary = {
    id: number;
    name: string;
    address: string | null;
    proposals: number;
};

export type ProposalSummary = {
    id: number;
    title: string;
    summary: string;
    status: 'open' | 'closed';
    endsAt: string | null;
    dao: { id: number; name: string } | null;
    author: FeedPerson | null;
    comments: number;
    votes: number;
    /** Decimal strings: voting power is decimal(*,18) and only ever a ratio. */
    powerFor: string;
    powerAgainst: string;
    url: string;
    descriptionHtml?: string;
};

export type WalletAchievement = {
    id: number;
    key: string;
    title: string;
    description: string;
    icon: string;
    earned: boolean;
};

export type WalletProfile = {
    claimed: boolean;
    address: string;
    name?: string;
    onchainNickname?: string | null;
    avatar?: string | null;
    profileUrl?: string;
    joinedAt?: string | null;
    stats?: { proposals: number; votes: number; posts: number };
    achievements: WalletAchievement[];
};

const read = async <T>(path: string): Promise<T> => {
    const response = await fetch(path, {
        headers: { Accept: 'application/json' },
    });

    if (!response.ok) {
        throw new Error(`Cyberia returned ${response.status}`);
    }

    return (await response.json()) as T;
};

export const fetchFeed = async (
    tab: 'all' | 'posts' | 'dao' = 'all',
): Promise<FeedItem[]> =>
    (await read<{ items: FeedItem[] }>(`/api/wallet/feed?tab=${tab}`)).items;

export const fetchDao = (): Promise<{
    daos: DaoSummary[];
    proposals: ProposalSummary[];
}> => read('/api/wallet/dao');

export const fetchProposal = async (id: number): Promise<ProposalSummary> =>
    (
        await read<{ proposal: ProposalSummary }>(
            `/api/wallet/dao/proposals/${id}`,
        )
    ).proposal;

export const fetchProfile = (address: string): Promise<WalletProfile> =>
    read(`/api/wallet/profile/${address}`);

/**
 * The two sides of a tally as percentages of what was actually cast.
 *
 * Voting power arrives as a decimal string because it is a decimal(*,18); it is
 * only ever drawn as a proportion, so it is safe to take the ratio in floating
 * point here — and a tally with no votes is 0/0 rather than half each.
 */
export const tally = (
    powerFor: string,
    powerAgainst: string,
): { for: number; against: number; cast: number } => {
    const yes = Number(powerFor) || 0;
    const no = Number(powerAgainst) || 0;
    const cast = yes + no;

    return cast === 0
        ? { for: 0, against: 0, cast: 0 }
        : { for: (100 * yes) / cast, against: (100 * no) / cast, cast };
};
