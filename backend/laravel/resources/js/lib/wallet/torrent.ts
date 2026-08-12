/**
 * BitTorrent, from the wallet's point of view.
 *
 * A browser tab cannot do this and never will: the mainline DHT is UDP and
 * peers are reached over TCP or uTP, and a page has neither. What a page *can*
 * do — WebRTC peers behind WSS trackers — is a different swarm that most
 * magnets have no members in, so offering it here would be a downloader that
 * shows zero peers for almost everything anyone pastes into it.
 *
 * So the engine lives in the desktop shell, where there is a Node process and
 * real sockets, and this file is only the contract with it: what the bridge
 * exposes, and the small pure helpers the screen needs. Everywhere else
 * `torrentBridge()` is null and the screen says so plainly.
 */

/** One file inside a torrent. */
export type TorrentFile = {
    name: string;
    /** Bytes, as the torrent's own metadata declares them. */
    length: number;
    /** Fraction of this file that is on disk, 0–1. */
    progress: number;
};

export type TorrentStatus =
    | 'metadata'
    | 'downloading'
    | 'seeding'
    | 'paused'
    | 'error';

/** One torrent as the engine reports it. */
export type TorrentSummary = {
    /** The torrent's identity — 40 hex characters, the same everywhere. */
    infoHash: string;
    name: string;
    status: TorrentStatus;
    /** 0–1. Stays 0 while the engine is still fetching the metadata. */
    progress: number;
    /** Total size in bytes, or 0 before the metadata arrives. */
    length: number;
    downloaded: number;
    uploaded: number;
    /** Bytes per second, right now. */
    downloadSpeed: number;
    uploadSpeed: number;
    peers: number;
    /** Seconds left at the current rate, or null when it cannot be known. */
    eta: number | null;
    files: TorrentFile[];
    /** Why this torrent stopped, when it did. */
    error: string | null;
};

/** What the desktop shell knows that the page does not. */
export type TorrentEngineInfo = {
    /** Where files land. Chosen by the shell — a page never names a path. */
    downloadDir: string;
    /** Largest file the shell will hand back for pinning. */
    maxReadBytes: number;
    /** Torrents allowed at once. */
    maxTorrents: number;
    /** Whether the user has agreed to run a peer-to-peer client at all. */
    consented: boolean;
};

/** A file handed back from disk, for pinning it to IPFS. */
export type TorrentFileBytes = {
    name: string;
    /** The file's bytes. Base64 because it crosses an IPC boundary. */
    base64: string;
    bytes: number;
};

export type TorrentBridge = {
    /** Bumped when the shape below changes, so an old shell is detectable. */
    version: number;
    info: () => Promise<TorrentEngineInfo>;
    add: (source: string) => Promise<TorrentSummary>;
    list: () => Promise<TorrentSummary[]>;
    pause: (infoHash: string) => Promise<void>;
    resume: (infoHash: string) => Promise<void>;
    /** Remove from the client, and optionally delete what it wrote. */
    remove: (infoHash: string, deleteFiles: boolean) => Promise<void>;
    read: (infoHash: string, fileIndex: number) => Promise<TorrentFileBytes>;
    /** Show the download folder in the system's file manager. */
    reveal: (infoHash?: string) => Promise<void>;
    /** Progress, pushed rather than polled. Returns an unsubscribe. */
    subscribe: (listener: (torrents: TorrentSummary[]) => void) => () => void;
};

/**
 * The engine, or null where there is none.
 *
 * Feature-detected rather than inferred from the shell name: an older desktop
 * build has no engine, and telling its user that this screen works because
 * they are on desktop would be worse than telling them nothing.
 */
export const torrentBridge = (): TorrentBridge | null => {
    if (typeof window === 'undefined') {
        return null;
    }

    const bridge = window.cyberiaNative?.torrent;

    return bridge && typeof bridge.add === 'function' ? bridge : null;
};

const HEX_HASH = /^[0-9a-f]{40}$/i;
const BASE32_HASH = /^[a-z2-7]{32}$/i;

/**
 * What the user typed, as something the engine can take — or null.
 *
 * Three things are accepted: a magnet link, a bare info hash (which is a
 * magnet with the ceremony removed), and an https link to a `.torrent` file.
 * Everything else is refused here, in the page, so a mistyped line never
 * reaches the engine and never opens a socket.
 */
export const normalizeTorrentSource = (input: string): string | null => {
    const value = input.trim();

    if (value === '') {
        return null;
    }

    if (HEX_HASH.test(value) || BASE32_HASH.test(value)) {
        return `magnet:?xt=urn:btih:${value.toLowerCase()}`;
    }

    if (value.toLowerCase().startsWith('magnet:?')) {
        return /xt=urn:btih:[0-9a-z]{32,40}/i.test(value) ? value : null;
    }

    if (/^https:\/\/\S+$/i.test(value)) {
        return value;
    }

    return null;
};

/** Bytes per second as something a human reads. */
export const formatSpeed = (bytesPerSecond: number): string => {
    if (bytesPerSecond < 1024) {
        return `${Math.round(bytesPerSecond)} B/s`;
    }

    const units = ['KB/s', 'MB/s', 'GB/s'];
    let value = bytesPerSecond / 1024;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit += 1;
    }

    return `${value.toFixed(value >= 100 ? 0 : 1)} ${units[unit]}`;
};

/**
 * Seconds left, in words, or null when there is no honest answer.
 *
 * A stalled torrent has no ETA — not "∞", which reads like a number and is
 * really the absence of one.
 */
export const formatEta = (seconds: number | null): string | null => {
    if (seconds === null || !Number.isFinite(seconds) || seconds <= 0) {
        return null;
    }

    if (seconds < 60) {
        return `${Math.round(seconds)}s`;
    }

    if (seconds < 3600) {
        return `${Math.round(seconds / 60)}m`;
    }

    if (seconds < 86_400) {
        return `${Math.floor(seconds / 3600)}h ${Math.round((seconds % 3600) / 60)}m`;
    }

    return `${Math.round(seconds / 86_400)}d`;
};

/** Base64 from the bridge as a Blob the pin endpoint can take. */
export const bytesToBlob = (file: TorrentFileBytes, type = 'application/octet-stream'): Blob => {
    const binary = atob(file.base64);
    const bytes = new Uint8Array(binary.length);

    for (let i = 0; i < binary.length; i += 1) {
        bytes[i] = binary.charCodeAt(i);
    }

    return new Blob([bytes], { type });
};
