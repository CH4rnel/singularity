/**
 * Pinning, from the wallet's point of view.
 *
 * A CID is an address made of the bytes themselves: the same file pinned by
 * two different people on two different nodes has the same name. That is why
 * every function here returns the CID first and a gateway link second — the
 * CID is what an NFT points at and what survives this company, while a gateway
 * is one of many hosts that happen to serve it today.
 *
 * The bytes go through Laravel because the Kubo API can run any node command
 * and is therefore bound to localhost, never handed to a browser. Nothing
 * about a key, an account or a signature is involved: this posts bytes and
 * gets back their name.
 */

/** The one thing pinning produces. */
export type Pinned = {
    /** Content address. The bytes' real name, and what goes on chain. */
    cid: string;
    /** `ipfs://…`, for clients that resolve it natively. */
    uri: string;
    /** The same content through a public gateway, for those that do not. */
    gatewayUrl: string;
    bytes: number;
    name: string;
};

/**
 * Gateway used to *read* an `ipfs://` someone else wrote.
 *
 * Deliberately the same public one the server hands back, so a CID minted here
 * and a CID minted elsewhere render through the same host. It is a fallback
 * for a browser that cannot resolve `ipfs://` itself, not a claim about where
 * the content lives.
 */
const READ_GATEWAY = 'https://ipfs.io';

const csrfToken = (): string => {
    if (typeof document === 'undefined') {
        return '';
    }

    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
};

type PinResponse = {
    cid: string;
    ipfs_uri: string;
    gateway_url: string;
    bytes: number;
    name: string;
};

/**
 * One pin request, with the server's own words on failure.
 *
 * "The node is unreachable" and "this is larger than we pin" are different
 * problems with different answers, and a screen that flattens both into
 * "upload failed" leaves the user retrying something that cannot work.
 */
const pin = async (url: string, body: FormData | string): Promise<Pinned> => {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-XSRF-TOKEN': csrfToken(),
            ...(typeof body === 'string'
                ? { 'Content-Type': 'application/json' }
                : {}),
        },
        body,
    });

    const data = (await response.json().catch(() => ({}))) as Partial<
        PinResponse & { message: string; errors: Record<string, string[]> }
    >;

    if (!response.ok || !data.cid) {
        const first = Object.values(data.errors ?? {})[0]?.[0];

        throw new Error(
            first ??
                data.message ??
                'Nothing was pinned — the node is unreachable.',
        );
    }

    return {
        cid: data.cid,
        uri: data.ipfs_uri ?? `ipfs://${data.cid}/`,
        gatewayUrl: data.gateway_url ?? ipfsHttpUrl(`ipfs://${data.cid}`) ?? '',
        bytes: data.bytes ?? 0,
        name: data.name ?? 'file',
    };
};

/** Pin a file the user chose. Images, audio, an archive — all bytes. */
export const pinFile = async (
    file: File | Blob,
    name?: string,
): Promise<Pinned> => {
    const form = new FormData();

    form.append(
        'file',
        file,
        name ?? (file instanceof File ? file.name : 'file'),
    );

    return pin('/api/wallet/ipfs/file', form);
};

/**
 * Pin a JSON document — ERC-721 metadata, in practice.
 *
 * The document is built in the browser, so nothing the server does can put a
 * field into someone's token metadata that they did not write.
 */
export const pinJson = async (
    value: unknown,
    name = 'metadata.json',
): Promise<Pinned> =>
    pinFile(
        new Blob([JSON.stringify(value)], { type: 'application/json' }),
        name,
    );

/**
 * Pin a web page.
 *
 * Wrapped as `index.html` inside a directory, which is the whole difference
 * between a page and a download: a gateway serves the bare CID as a site.
 */
export const pinPage = async (html: string): Promise<Pinned> =>
    pin('/api/wallet/ipfs/page', JSON.stringify({ html }));

/**
 * An `ipfs://` address as something a browser can fetch, or null if it is not
 * one. A plain https URL is handed back unchanged — token metadata in the wild
 * points at both, and a wallet that only rendered one would show blanks.
 */
export const ipfsHttpUrl = (
    uri: string | null | undefined,
    gateway = READ_GATEWAY,
): string | null => {
    const value = (uri ?? '').trim();

    if (value === '') {
        return null;
    }

    if (value.startsWith('ipfs://')) {
        return `${gateway.replace(/\/+$/, '')}/ipfs/${value.slice(7).replace(/^\/+/, '')}`;
    }

    return /^https?:\/\//.test(value) ? value : null;
};

/**
 * Bytes as something a human reads.
 *
 * Shared rather than per-screen: a file about to be pinned, a torrent's size
 * and a release on the tracker are all the same number, and two formatters
 * eventually disagree about the same file.
 */
export const formatBytes = (bytes: number): string => {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    const units = ['KB', 'MB', 'GB', 'TB'];
    let value = bytes / 1024;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit += 1;
    }

    return `${value.toFixed(value >= 100 || unit === 0 ? 0 : 1)} ${units[unit]}`;
};
