import { ipfsHttpUrl } from '@/lib/wallet/ipfs';
import type { WalletNft } from '@/lib/wallet/nft';
import { extensionOf, isPlayable, isVideo } from '@/lib/wallet/tracker';
import type { TrackerRelease } from '@/lib/wallet/tracker';

/**
 * The wallet's media player — the part that is not a `<video>` element.
 *
 * What a wallet can honestly play is decided by where the bytes are, and there
 * are exactly three places: a file pinned to IPFS (any browser can fetch it),
 * an https link a token points at, and a file in a swarm this machine is
 * already downloading (only the desktop shell, which has the sockets and can
 * hand the page a stream). This file turns each of those into the same small
 * thing — a track with a title, a URL and a kind — so the player itself never
 * knows which one it is playing.
 *
 * The other job here is being honest about formats. A browser plays a narrow
 * list, and it fails silently on the rest: `<video src="film.mkv">` shows a
 * black rectangle and no error anybody would recognise. So a track carries
 * what this browser is likely to do with it, and the player says "your browser
 * cannot decode this" instead of showing a black rectangle.
 */

export type TrackKind = 'video' | 'audio';

export type PlayerTrack = {
    /** Stable within one playlist; the player keys and compares on it. */
    id: string;
    title: string;
    /** Where the row came from, e.g. the release's name. */
    subtitle?: string;
    url: string;
    kind: TrackKind;
    /** Bytes, when they are known. */
    length?: number;
    /** How likely this browser is to decode it. */
    support: 'browser' | 'external' | 'unknown';
    /** Set when the URL is only obtainable at play time (a swarm). */
    resolve?: () => Promise<string>;
};

/**
 * Containers a browser will decode, and containers it will not.
 *
 * Not a guess: this is the intersection every current engine supports, and the
 * refusals are the containers that never had a decoder in a browser — Matroska
 * and AVI most of all, which is most of what a torrent of a film actually is.
 * Anything unlisted is `unknown`, and the player finds out by trying.
 */
const BROWSER_FORMATS = [
    'mp4',
    'm4v',
    'webm',
    'ogv',
    'mp3',
    'wav',
    'ogg',
    'oga',
    'opus',
    'm4a',
    'aac',
    'flac',
];

const EXTERNAL_FORMATS = [
    'mkv',
    'avi',
    'wmv',
    'flv',
    'ts',
    'mpg',
    'mpeg',
    'ape',
    'wma',
    'aiff',
    'alac',
];

export const formatSupport = (path: string): PlayerTrack['support'] => {
    const extension = extensionOf(path);

    if (BROWSER_FORMATS.includes(extension)) {
        return 'browser';
    }

    return EXTERNAL_FORMATS.includes(extension) ? 'external' : 'unknown';
};

/** A content type for the extension, for the shell's stream and `canPlayType`. */
export const mimeFor = (path: string): string =>
    ({
        mp4: 'video/mp4',
        m4v: 'video/mp4',
        webm: 'video/webm',
        ogv: 'video/ogg',
        mkv: 'video/x-matroska',
        avi: 'video/x-msvideo',
        mov: 'video/quicktime',
        mp3: 'audio/mpeg',
        m4a: 'audio/mp4',
        aac: 'audio/aac',
        flac: 'audio/flac',
        wav: 'audio/wav',
        ogg: 'audio/ogg',
        oga: 'audio/ogg',
        opus: 'audio/ogg',
    })[extensionOf(path)] ?? 'application/octet-stream';

/** The last path segment, which is the only part of a path worth a title. */
export const trackTitle = (path: string): string => {
    const name = path.split('/').pop() ?? path;
    const dot = name.lastIndexOf('.');

    return (dot > 0 ? name.slice(0, dot) : name).trim() || path;
};

/**
 * Seconds as a clock. `null` while the media has not said how long it is —
 * which is normal for a stream and is drawn as `--:--` rather than `0:00`,
 * because a zero-length track and an unknown one look nothing alike to
 * somebody deciding whether to wait.
 */
export const formatTime = (seconds: number | null): string => {
    if (seconds === null || !Number.isFinite(seconds) || seconds < 0) {
        return '--:--';
    }

    const whole = Math.floor(seconds);
    const parts = [
        Math.floor(whole / 3600),
        Math.floor((whole % 3600) / 60),
        whole % 60,
    ];

    return parts[0] > 0
        ? `${parts[0]}:${String(parts[1]).padStart(2, '0')}:${String(parts[2]).padStart(2, '0')}`
        : `${parts[1]}:${String(parts[2]).padStart(2, '0')}`;
};

/**
 * Where the next track is, given how the playlist is set to run.
 *
 * `null` means the playlist ended — the player stops rather than wrapping,
 * because a queue that silently restarts is how an album plays all night.
 */
export const advance = (
    index: number,
    length: number,
    mode: 'off' | 'all' | 'one',
): number | null => {
    if (length === 0) {
        return null;
    }

    if (mode === 'one') {
        return index;
    }

    const next = index + 1;

    if (next < length) {
        return next;
    }

    return mode === 'all' ? 0 : null;
};

/**
 * A release as a playlist, for anybody at all.
 *
 * Only what the minter pinned: `preview_url` is a file on IPFS, so it plays in
 * any browser with no swarm, no client and no download. The release's own
 * files are not listed here — they are in a torrent, and a row that cannot
 * play is worse than a row that is absent.
 */
export const tracksFromRelease = (release: TrackerRelease): PlayerTrack[] => {
    const preview = ipfsHttpUrl(release.preview_url);

    if (preview === null) {
        return [];
    }

    return [
        {
            id: `preview:${release.info_hash}`,
            title: trackTitle(release.preview_url ?? release.name),
            subtitle: release.name,
            url: preview,
            kind: isVideo(release.preview_url ?? '') ? 'video' : 'audio',
            support: formatSupport(release.preview_url ?? ''),
        },
    ];
};

/**
 * A torrent this client is holding, as a playlist.
 *
 * The files come from the engine and not from the release's metadata, and the
 * difference matters: the index a stream is asked for has to be an index into
 * the torrent the client actually has. A published file list is a description
 * of that torrent and is usually identical — but "usually" is how a player
 * ends up streaming track four while displaying track three.
 */
export const tracksFromTorrent = (
    torrent: {
        infoHash: string;
        name: string;
        files: readonly { path: string; name: string; length: number }[];
    },
    stream: (fileIndex: number) => Promise<string>,
): PlayerTrack[] =>
    torrent.files.flatMap((file, index) => {
        const path = file.path || file.name;

        // Everything else in a release is a cover, a nfo or a subtitle track;
        // a playlist that listed them would be a file manager.
        if (!isPlayable(path)) {
            return [];
        }

        return [
            {
                id: `${torrent.infoHash}:${index}`,
                title: trackTitle(path),
                subtitle: torrent.name,
                // Resolved when it is played, not when the list is drawn:
                // asking for a stream tells the client that file is urgent,
                // and doing that for forty files at once downloads none.
                url: '',
                resolve: () => stream(index),
                kind: isVideo(path) ? 'video' : 'audio',
                length: file.length,
                support: formatSupport(path),
            },
        ];
    });

/**
 * A token as a playlist, when it points at something playable.
 *
 * Most NFTs are pictures and produce nothing here, which is the correct
 * answer: the player opens from a token only when there is something to hear.
 */
export const tracksFromNft = (nft: WalletNft): PlayerTrack[] => {
    const candidates = [nft.externalUrl, nft.uri, nft.imageUrl];
    const tracks: PlayerTrack[] = [];

    for (const candidate of candidates) {
        const url = ipfsHttpUrl(candidate);

        if (url === null) {
            continue;
        }

        const support = formatSupport(candidate ?? '');

        if (support === 'unknown') {
            continue;
        }

        tracks.push({
            id: `${nft.contract}:${nft.tokenId}`,
            title: nft.name,
            subtitle: nft.collection,
            url,
            kind: isVideo(candidate ?? '') ? 'video' : 'audio',
            support,
        });

        break;
    }

    return tracks;
};
