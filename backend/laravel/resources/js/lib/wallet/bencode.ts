/**
 * Reading a `.torrent` in the browser.
 *
 * A release published from a browser starts as a file somebody already has —
 * they made the torrent in their own client, and they are about to mint it. To
 * do that honestly the page has to know three things the filename cannot tell
 * it: the info hash, the file list and the total size. All three live inside
 * the file, bencoded, so this decodes it.
 *
 * The info hash is the delicate part. It is SHA-1 over the *original bytes* of
 * the info dictionary, not over a re-encoding of it — two encoders that
 * disagree about key order produce two different hashes from one file, and a
 * hash that is one bit off names a swarm nobody is in. So the decoder records
 * where each value started and ended, and the hash is taken over that slice.
 *
 * Nothing here writes a torrent. Creating one means hashing every piece of
 * every file, which is a real client's job — in this project, the desktop
 * shell's.
 */

export type BencodeValue =
    | number
    | Uint8Array
    | BencodeValue[]
    | { [key: string]: BencodeValue };

type Cursor = { at: number };

const decoder = new TextDecoder('utf-8', { fatal: false });

/** Bytes as text, for the fields that are names rather than data. */
export const bytesToText = (value: BencodeValue | undefined): string =>
    value instanceof Uint8Array ? decoder.decode(value) : '';

const readValue = (data: Uint8Array, cursor: Cursor): BencodeValue => {
    const marker = data[cursor.at];

    if (marker === undefined) {
        throw new Error('The torrent file ends in the middle of a value');
    }

    // 'i' — an integer, terminated by 'e'.
    if (marker === 0x69) {
        const end = data.indexOf(0x65, cursor.at);

        if (end === -1) {
            throw new Error('Unterminated integer');
        }

        const text = decoder.decode(data.subarray(cursor.at + 1, end));

        if (!/^(0|-?[1-9][0-9]*)$/.test(text)) {
            throw new Error(`Malformed integer "${text}"`);
        }

        cursor.at = end + 1;

        return Number(text);
    }

    // 'l' — a list.
    if (marker === 0x6c) {
        cursor.at += 1;
        const items: BencodeValue[] = [];

        while (data[cursor.at] !== 0x65) {
            if (cursor.at >= data.length) {
                throw new Error('Unterminated list');
            }

            items.push(readValue(data, cursor));
        }

        cursor.at += 1;

        return items;
    }

    // 'd' — a dictionary.
    if (marker === 0x64) {
        cursor.at += 1;
        const entries: { [key: string]: BencodeValue } = {};

        while (data[cursor.at] !== 0x65) {
            if (cursor.at >= data.length) {
                throw new Error('Unterminated dictionary');
            }

            const key = readValue(data, cursor);

            if (!(key instanceof Uint8Array)) {
                throw new Error('A dictionary key that is not a string');
            }

            entries[decoder.decode(key)] = readValue(data, cursor);
        }

        cursor.at += 1;

        return entries;
    }

    // Anything else must be a length-prefixed byte string.
    const colon = data.indexOf(0x3a, cursor.at);

    if (colon === -1) {
        throw new Error('A string with no length');
    }

    const digits = decoder.decode(data.subarray(cursor.at, colon));

    if (!/^(0|[1-9][0-9]*)$/.test(digits)) {
        throw new Error(`Malformed string length "${digits}"`);
    }

    const length = Number(digits);

    if (colon + 1 + length > data.length) {
        throw new Error('A string longer than the file it is in');
    }

    cursor.at = colon + 1 + length;

    return data.subarray(colon + 1, cursor.at);
};

export const decodeBencode = (data: Uint8Array): BencodeValue =>
    readValue(data, { at: 0 });

/**
 * The bytes of one key inside a top-level dictionary, verbatim.
 *
 * This is the whole reason the decoder tracks offsets: `sliceValue(file,
 * 'info')` is what gets hashed, and re-encoding would be a guess about what
 * the client that wrote this file happened to do.
 */
export const sliceValue = (
    data: Uint8Array,
    key: string,
): Uint8Array | null => {
    if (data[0] !== 0x64) {
        return null;
    }

    const cursor: Cursor = { at: 1 };

    while (data[cursor.at] !== 0x65) {
        if (cursor.at >= data.length) {
            throw new Error('Unterminated dictionary');
        }

        const name = readValue(data, cursor);
        const start = cursor.at;
        readValue(data, cursor);

        if (name instanceof Uint8Array && decoder.decode(name) === key) {
            return data.subarray(start, cursor.at);
        }
    }

    return null;
};

export type TorrentFileEntry = { path: string; length: number };

export type ParsedTorrent = {
    /** 40 lowercase hex characters — the swarm's name everywhere. */
    infoHash: string;
    name: string;
    /** Total bytes across every file. */
    length: number;
    files: TorrentFileEntry[];
    /** Trackers the file already carries, in the order it lists them. */
    trackers: string[];
    /** True when the file describes a v2-only torrent, which this cannot use. */
    v2Only: boolean;
};

const hex = (bytes: ArrayBuffer): string =>
    Array.from(new Uint8Array(bytes))
        .map((byte) => byte.toString(16).padStart(2, '0'))
        .join('');

/**
 * One `.torrent` file, as the fields a release needs.
 *
 * SHA-1 is used here for exactly one reason: it is the definition of a
 * BitTorrent v1 info hash. It is not being trusted with anything — the hash is
 * an identifier that every client in the swarm computes the same way, and a
 * stronger one would simply name a torrent nobody has.
 */
export const parseTorrent = async (
    data: Uint8Array,
): Promise<ParsedTorrent> => {
    const document = decodeBencode(data);

    if (
        typeof document !== 'object' ||
        document === null ||
        Array.isArray(document) ||
        document instanceof Uint8Array
    ) {
        throw new Error('That file is not a torrent');
    }

    const info = document.info;
    const raw = sliceValue(data, 'info');

    if (
        raw === null ||
        typeof info !== 'object' ||
        info === null ||
        Array.isArray(info) ||
        info instanceof Uint8Array
    ) {
        throw new Error('That torrent has no info dictionary');
    }

    // A v2-only torrent has `meta version 2` and no `pieces`, and its swarm is
    // addressed by a SHA-256 hash this tracker does not index. Saying so is
    // better than minting a v1 hash that names nothing.
    const v2Only =
        info.pieces === undefined && (info['meta version'] ?? 0) === 2;

    const digest = await crypto.subtle.digest(
        'SHA-1',
        raw.slice().buffer as ArrayBuffer,
    );

    const name = bytesToText(info.name) || 'release';
    const files: TorrentFileEntry[] = [];

    if (Array.isArray(info.files)) {
        for (const entry of info.files) {
            if (
                typeof entry !== 'object' ||
                entry === null ||
                Array.isArray(entry) ||
                entry instanceof Uint8Array
            ) {
                continue;
            }

            const segments = Array.isArray(entry.path)
                ? entry.path
                      .map((part) => bytesToText(part))
                      .filter((part) => part !== '')
                : [];

            if (segments.length === 0) {
                continue;
            }

            files.push({
                path: segments.join('/'),
                length: typeof entry.length === 'number' ? entry.length : 0,
            });
        }
    } else if (typeof info.length === 'number') {
        // A single-file torrent has no `files` list; its name is the file.
        files.push({ path: name, length: info.length });
    }

    const trackers: string[] = [];
    const announce = bytesToText(document.announce);

    if (announce !== '') {
        trackers.push(announce);
    }

    if (Array.isArray(document['announce-list'])) {
        for (const tier of document['announce-list']) {
            if (!Array.isArray(tier)) {
                continue;
            }

            for (const url of tier) {
                const value = bytesToText(url);

                if (value !== '' && !trackers.includes(value)) {
                    trackers.push(value);
                }
            }
        }
    }

    return {
        infoHash: hex(digest),
        name,
        length: files.reduce((total, file) => total + file.length, 0),
        files,
        trackers,
        v2Only,
    };
};

const BASE32 = 'abcdefghijklmnopqrstuvwxyz234567';

/**
 * A base32 info hash as hex.
 *
 * Magnets carry either form and clients accept both, so a page that only read
 * the hex one would refuse perfectly good links for a reason nobody could see.
 */
export const base32ToHex = (value: string): string | null => {
    const clean = value.toLowerCase();

    if (!/^[a-z2-7]{32}$/.test(clean)) {
        return null;
    }

    let bits = '';

    for (const character of clean) {
        bits += BASE32.indexOf(character).toString(2).padStart(5, '0');
    }

    return (bits.slice(0, 160).match(/.{8}/g) ?? [])
        .map((byte) => parseInt(byte, 2).toString(16).padStart(2, '0'))
        .join('');
};

export type ParsedMagnet = {
    infoHash: string;
    /** The display name the link carries, which is often all there is. */
    name: string;
    trackers: string[];
};

/**
 * A magnet link, as much as one can say.
 *
 * A magnet is an identity and a hint: it names the swarm and usually a display
 * name, and it knows nothing about the file list or the real size. That is why
 * publishing from a magnet leaves those fields empty rather than inventing
 * them — the swarm will fill them in for whoever downloads it, and a size this
 * page guessed would be on chain forever.
 */
export const parseMagnet = (input: string): ParsedMagnet | null => {
    const value = input.trim();

    if (!/^magnet:\?/i.test(value)) {
        return null;
    }

    const parameters = new URLSearchParams(value.slice(value.indexOf('?') + 1));
    const trackers = parameters.getAll('tr').filter((url) => url !== '');

    for (const xt of parameters.getAll('xt')) {
        const match = /^urn:btih:([0-9a-z]{32,40})$/i.exec(xt.trim());

        if (!match) {
            continue;
        }

        const raw = match[1];
        const infoHash =
            raw.length === 40 ? raw.toLowerCase() : base32ToHex(raw);

        if (infoHash !== null && /^[0-9a-f]{40}$/.test(infoHash)) {
            return {
                infoHash,
                name: (parameters.get('dn') ?? '').trim(),
                trackers,
            };
        }
    }

    return null;
};
