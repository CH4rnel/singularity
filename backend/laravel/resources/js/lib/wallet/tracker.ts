import type { TorrentFileEntry } from '@/lib/wallet/bencode';
import type { NftMetadata } from '@/lib/wallet/nft';

/**
 * The tracker, from the wallet's side.
 *
 * A release here is a torrent that exists on the index because a token names
 * it: the mint is the publication, and this server's part is to read the token
 * and run the announce endpoint the swarm reports to. So there is no login on
 * this screen and no upload — what travels to Laravel when something is
 * published is a chain id and a token id, and every field on the row is then
 * read back off the chain.
 *
 * That shape is why the interesting function in this file is
 * `buildReleaseMetadata`: it writes the document the CID is made of, and that
 * document is the release, permanently. It is pure, and it is pinned in
 * tests/Frontend/WalletTrackerTest.mjs.
 */

export type ReleaseFile = { path: string; length: number };

export type TrackerRelease = {
    info_hash: string;
    name: string;
    description: string;
    category: string;
    size_bytes: number;
    file_count: number;
    files: ReleaseFile[];
    magnet: string;
    /** `video`, `audio`, `mixed` or `other` — what the player opens as. */
    media_kind: string;
    /** Something playable without joining the swarm, when one was pinned. */
    preview_url: string | null;
    cover_url: string | null;
    seeders: number;
    leechers: number;
    completed: number;
    chain_id: number;
    contract: string;
    token_id: string;
    owner: string;
    token_uri: string;
    token_url: string | null;
    published_at: string | null;
    last_announce_at: string | null;
};

export type TrackerContext = {
    announce_url: string;
    categories: string[];
    sorts: string[];
    chain_id: number;
    collection: string | null;
    explorer_url: string | null;
};

export type TrackerFilters = {
    q?: string;
    category?: string;
    sort?: string;
    owner?: string;
    page?: number;
};

export type TrackerPage = {
    releases: TrackerRelease[];
    total: number;
    page: number;
    pages: number;
    filters: Required<Omit<TrackerFilters, 'page'>>;
    context: TrackerContext;
};

const csrfToken = (): string => {
    if (typeof document === 'undefined') {
        return '';
    }

    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
};

/**
 * One request, with the server's own sentence on failure.
 *
 * Registration fails in three genuinely different ways — the chain does not
 * know the token yet, the document is not metadata, the metadata names no
 * torrent — and each has a different next step. Flattening them into "could
 * not publish" would leave someone who minted thirty seconds ago retrying
 * something that will work on its own in ten.
 */
const request = async <T>(url: string, init?: RequestInit): Promise<T> => {
    const response = await fetch(url, {
        credentials: 'same-origin',
        ...init,
        headers: {
            Accept: 'application/json',
            ...(init?.body ? { 'Content-Type': 'application/json' } : {}),
            ...(init?.method && init.method !== 'GET'
                ? { 'X-XSRF-TOKEN': csrfToken() }
                : {}),
            ...(init?.headers as Record<string, string> | undefined),
        },
    });

    const body = (await response.json().catch(() => ({}))) as Partial<
        T & { message: string; errors: Record<string, string[]> }
    >;

    if (!response.ok) {
        const first = Object.values(body.errors ?? {})[0]?.[0];

        throw new Error(first ?? body.message ?? 'The tracker did not answer.');
    }

    return body as T;
};

export const fetchReleases = async (
    filters: TrackerFilters = {},
): Promise<TrackerPage> => {
    const query = new URLSearchParams();

    for (const [key, value] of Object.entries(filters)) {
        if (value !== undefined && value !== null && String(value) !== '') {
            query.set(key, String(value));
        }
    }

    const suffix = query.toString();

    return request<TrackerPage>(
        `/api/tracker/releases${suffix === '' ? '' : `?${suffix}`}`,
    );
};

export const fetchRelease = async (
    infoHash: string,
): Promise<{ release: TrackerRelease; context: TrackerContext }> =>
    request(`/api/tracker/releases/${infoHash}`);

/**
 * Put a minted token on the index.
 *
 * Two fields, and deliberately: anything else this call could carry would be a
 * field a submitter can lie in. The server reads the owner, the URI and the
 * whole description from the chain.
 */
export const registerRelease = async (
    chainId: number,
    tokenId: string,
): Promise<TrackerRelease> => {
    const body = await request<{ release: TrackerRelease }>(
        '/api/tracker/releases',
        {
            method: 'POST',
            body: JSON.stringify({ chain_id: chainId, token_id: tokenId }),
        },
    );

    return body.release;
};

/* ------------------------------------------------------------ composing -- */

const VIDEO = [
    'mp4',
    'mkv',
    'webm',
    'avi',
    'mov',
    'm4v',
    'mpg',
    'mpeg',
    'wmv',
    'flv',
    'ogv',
    'ts',
];

const AUDIO = [
    'mp3',
    'flac',
    'wav',
    'ogg',
    'oga',
    'opus',
    'm4a',
    'aac',
    'wma',
    'aiff',
    'alac',
    'ape',
];

export const extensionOf = (path: string): string => {
    const dot = path.lastIndexOf('.');

    return dot === -1 ? '' : path.slice(dot + 1).toLowerCase();
};

/**
 * What a release mostly is, from the names of the files in it.
 *
 * Mirrors `App\Services\Tracker\ReleaseMetadata::mediaKind` — the server
 * decides this for the row, and the page decides it before there is a row, so
 * both have to answer the same. `mixed` is a real answer and not a shrug: it
 * is what a season with a soundtrack folder is, and the player reads it as
 * "offer both" rather than guessing wrong twice.
 */
export const mediaKindOf = (files: readonly ReleaseFile[]): string => {
    let video = 0;
    let audio = 0;

    for (const file of files) {
        const extension = extensionOf(file.path);

        if (VIDEO.includes(extension)) {
            video += 1;
        } else if (AUDIO.includes(extension)) {
            audio += 1;
        }
    }

    if (video > 0 && audio > 0) {
        return 'mixed';
    }

    if (video > 0) {
        return 'video';
    }

    return audio > 0 ? 'audio' : 'other';
};

/** Whether a file is something a media element could be pointed at. */
export const isPlayable = (path: string): boolean => {
    const extension = extensionOf(path);

    return VIDEO.includes(extension) || AUDIO.includes(extension);
};

export const isVideo = (path: string): boolean =>
    VIDEO.includes(extensionOf(path));

/** A magnet for a hash, carrying this tracker so nobody waits on the DHT. */
export const magnetFor = (
    infoHash: string,
    name: string,
    announceUrl: string,
): string =>
    `magnet:?xt=urn:btih:${infoHash}` +
    (name === '' ? '' : `&dn=${encodeURIComponent(name)}`) +
    (announceUrl === '' ? '' : `&tr=${encodeURIComponent(announceUrl)}`);

/**
 * ERC-721 metadata with the two fields a release adds.
 *
 * `animation_url` is standard and is where marketplaces look for playable
 * media; `torrent` is this index's own, ignored by everything that has not
 * heard of it, which is the point of putting it beside the standard fields
 * rather than inside them.
 */
export type ReleaseNftMetadata = NftMetadata & {
    animation_url?: string;
    torrent?: Record<string, unknown>;
};

export type ReleaseDraft = {
    name: string;
    description?: string;
    infoHash: string;
    /** The torrent's own files, when they are known. A magnet has none. */
    files?: TorrentFileEntry[];
    /** Total bytes, when known. */
    length?: number;
    category?: string;
    /** An `ipfs://` cover, if one was pinned. */
    cover?: string | null;
    /** An `ipfs://` sample that plays without joining the swarm. */
    preview?: string | null;
    announceUrl: string;
    /** Where the release will live once it is registered. */
    siteUrl?: string;
};

/**
 * The document the token points at, forever.
 *
 * Ordinary ERC-721 metadata with one extra key. A marketplace that has never
 * heard of this tracker still shows the name, the cover and the description;
 * the `torrent` object beside them is what the index reads, and the same three
 * facts are mirrored into `attributes` so a marketplace that only renders
 * those still displays the info hash rather than nothing.
 *
 * What it deliberately does not contain: the minter's address, a timestamp, or
 * a tracker URL presented as part of the content. The address is on the chain
 * already, and the rest would be this deploy's opinion baked into a CID.
 */
export const buildReleaseMetadata = (
    draft: ReleaseDraft,
): ReleaseNftMetadata => {
    const files = (draft.files ?? []).map((file) => ({
        path: file.path,
        length: Math.max(0, Math.trunc(file.length)),
    }));

    const length =
        draft.length !== undefined && draft.length > 0
            ? Math.trunc(draft.length)
            : files.reduce((total, file) => total + file.length, 0);

    const name = draft.name.trim();
    const infoHash = draft.infoHash.trim().toLowerCase();
    const category = (draft.category ?? '').trim() || undefined;

    const metadata: ReleaseNftMetadata = {
        name,
        description: (draft.description ?? '').trim(),
        attributes: [
            { trait_type: 'Info hash', value: infoHash },
            { trait_type: 'Files', value: String(files.length || 1) },
            { trait_type: 'Size', value: String(length) },
        ],
    };

    if (category) {
        metadata.attributes?.push({ trait_type: 'Category', value: category });
    }

    if (draft.cover) {
        metadata.image = draft.cover;
    }

    if (draft.preview) {
        // Where every marketplace already looks for a token's playable media,
        // so a preview is audible outside this index too.
        metadata.animation_url = draft.preview;
    }

    if (draft.siteUrl) {
        metadata.external_url = draft.siteUrl;
    }

    metadata.torrent = {
        info_hash: infoHash,
        name,
        length,
        files,
        magnet: magnetFor(infoHash, name, draft.announceUrl),
        category: category ?? mediaKindOf(files),
        media: mediaKindOf(files),
        ...(draft.preview ? { preview: draft.preview } : {}),
    };

    return metadata;
};
