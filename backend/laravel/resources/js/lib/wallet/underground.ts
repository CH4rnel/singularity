/**
 * The underground, from the wallet: the directory of token homepages
 * (`/api/underground`, UndergroundController) and publishing one.
 *
 * Publishing is not a new endpoint. A homepage has always been part of a
 * token's launchpad metadata — one HTML file and a subdomain, signed by the
 * token's creator (`LaunchpadController::store`) — so the workshop signs the
 * same sentence `/launchpad` does, with the vault's own key, and uploads the
 * page the template produced. Who may publish is the server's question: the
 * creator recorded for the token is read off the chain when it launches.
 */

import { metadataMessage } from '@/lib/wallet/launchpad';

export type UndergroundEntry = {
    chain_id: number;
    address: string;
    symbol: string | null;
    name: string | null;
    description: string | null;
    creator: string | null;
    image_url: string | null;
    site_subdomain: string | null;
    site_url: string | null;
    site_hits: number;
    site_updated_at: string | null;
    cover_url: string;
    counter_url: string | null;
    buy_url: string;
    dao_url: string | null;
    x_url: string | null;
    telegram_url: string | null;
    website_url: string | null;
    launched_at: string | null;
};

export type UndergroundDirectory = {
    underground_url: string;
    ring: string[];
    homes: UndergroundEntry[];
    lots: UndergroundEntry[];
};

export const fetchUnderground = async (): Promise<UndergroundDirectory> => {
    const response = await fetch('/api/underground', {
        headers: { Accept: 'application/json' },
    });

    if (!response.ok) {
        throw new Error('The underground is unreachable right now.');
    }

    return (await response.json()) as UndergroundDirectory;
};

/**
 * Tokens this wallet launched: the ones whose recorded creator is one of its
 * addresses. Homes and lots alike — a token with a page can have it replaced.
 */
export const tokensBy = (
    directory: UndergroundDirectory,
    addresses: readonly string[],
): UndergroundEntry[] => {
    const mine = new Set(addresses.map((address) => address.toLowerCase()));

    return [...directory.homes, ...directory.lots].filter(
        (entry) => entry.creator !== null && mine.has(entry.creator),
    );
};

/** The links a template prints for a token, from what its creator set. */
export const entryLinks = (
    entry: UndergroundEntry,
): { label: string; url: string }[] => {
    const candidates: [string, string | null][] = [
        ['DAO', entry.dao_url],
        ['X', entry.x_url],
        ['Telegram', entry.telegram_url],
        ['www', entry.website_url],
    ];

    return candidates.flatMap(([label, url]) =>
        url === null ? [] : [{ label, url }],
    );
};

/**
 * Sign and upload a homepage. Resolves with the token as the server now
 * describes it; rejects with the server's own words (a taken or reserved
 * subdomain, a signer who is not the creator).
 */
export const publishHomepage = async (request: {
    token: string;
    chainId: number;
    html: string;
    subdomain: string;
    sign: (message: string) => Promise<string>;
}): Promise<{ site_url: string | null; site_subdomain: string | null }> => {
    const message = metadataMessage(request.token, request.chainId);
    const signature = await request.sign(message);
    const form = new FormData();

    form.append('address', request.token);
    form.append('chain_id', String(request.chainId));
    form.append('message', message);
    form.append('signature', signature);
    form.append('site_subdomain', request.subdomain);
    form.append(
        'html',
        new Blob([request.html], { type: 'text/html' }),
        'index.html',
    );

    const response = await fetch('/api/launchpad/tokens', {
        method: 'POST',
        headers: { Accept: 'application/json' },
        body: form,
    });

    const body = (await response.json().catch(() => null)) as {
        message?: string;
        errors?: Record<string, string[]>;
        token?: { site_url: string | null; site_subdomain: string | null };
    } | null;

    if (!response.ok || !body?.token) {
        const first = body?.errors ? Object.values(body.errors)[0]?.[0] : null;

        throw new Error(first ?? body?.message ?? `HTTP ${response.status}`);
    }

    return body.token;
};
