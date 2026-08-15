import { SigningKey, getBytes, hexlify, sha256, verifyMessage } from 'ethers';

/**
 * End-to-end encryption for wallet-to-wallet messages, addressed by EVM address.
 *
 * The wallet already holds the only thing a private conversation needs — a key
 * nobody else has — so the messaging layer is built on it rather than beside
 * it. There is no account, no password and no server-held key anywhere in
 * here: Cyberia relays ciphertext it cannot read, and that is the whole of its
 * role.
 *
 * Three decisions carry the security of this file, and each is deliberate.
 *
 * **The messaging key is not the spending key.** An address cannot be turned
 * back into the public key it was hashed from, so encrypting to someone always
 * needs a published key — and once a key has to be published anyway, there is
 * no reason for it to be the one that signs transactions. Every account
 * derives a separate secp256k1 messaging key from its EVM private key by a
 * one-way hash, so it is recovered by any backup that already restores the
 * account (a seed, a second phrase, or a bare imported key) and needs no backup
 * of its own — while a compromise of it reveals conversations and moves no
 * money. A watch-only account has no key and therefore no chat, which is the
 * same answer it gives to sending.
 *
 * **The directory is not trusted.** A relay that hands out public keys can
 * hand out its own and read everything. So a published key is never accepted
 * on the server's word: it travels with an EIP-191 signature over a statement
 * naming both the address and the key, and the browser verifies that the
 * signature recovers to the address it is about (`verifyChatKey`). The worst a
 * hostile relay can do is refuse to answer or serve a stale key — it cannot
 * substitute one, and `chatFingerprint` lets two people compare out of band.
 *
 * **The conversation key is symmetric and static.** ECDH over the two
 * messaging keys gives both sides the same secret, so the sender can still read
 * what they sent — a wallet restored on a second device recovers its own half
 * of the conversation without the relay holding a plaintext copy. The cost is
 * stated plainly rather than hidden: there is no forward secrecy. Whoever
 * learns an account's key can decrypt that account's whole history, so this is
 * private mail between wallets, not a ratchet, and the UI says so.
 *
 * What is *not* protected: the relay sees who sends to whom and when. Content
 * is sealed, metadata is not, and no amount of care in this file changes that.
 */

/** Domain separator. Everything derived here is bound to this string. */
const DOMAIN = 'cyberia.wallet.chat.v1';

/** Order of the secp256k1 group — a private key is a scalar below it. */
const CURVE_ORDER =
    0xfffffffffffffffffffffffffffffffebaaedce6af48a03bbfd25e8cd0364141n;

/** Plaintext bytes accepted in one message, before padding. */
export const MAX_MESSAGE_BYTES = 4000;

/**
 * Ciphertext is padded up to a multiple of this many bytes.
 *
 * The relay cannot read a message but can measure it, and the length of a
 * reply is itself information. Padding does not make that free — a long
 * message is still visibly long — but it stops the exact byte count of every
 * short one from leaking.
 */
const PAD_BLOCK = 256;

/**
 * UTF-8 bytes in a buffer WebCrypto will accept.
 *
 * `TextEncoder` may hand back a view over a shared buffer, which the platform
 * types rightly refuse to pass to a crypto routine; copying into a plain
 * ArrayBuffer is cheaper than the alternatives and keeps the call sites honest
 * about what they are handing to the engine.
 */
const utf8 = (value: string): Uint8Array<ArrayBuffer> => {
    const encoded = new TextEncoder().encode(value);
    const bytes = new Uint8Array(new ArrayBuffer(encoded.length));

    bytes.set(encoded);

    return bytes;
};

const toBase64 = (bytes: Uint8Array): string =>
    btoa(String.fromCharCode(...bytes));

const fromBase64 = (value: string): Uint8Array<ArrayBuffer> =>
    Uint8Array.from(atob(value), (char) => char.charCodeAt(0));

/* --------------------------------------------------------------- identity --- */

/**
 * The messaging private key behind an EVM private key.
 *
 * A hash rather than a stored secret: the account already backs up everything
 * needed to recompute this, and a messaging key the user has to write down
 * separately is a messaging key they lose. The result is folded back through
 * the hash on the astronomically unlikely chance it lands outside the group,
 * because a scalar at or above the order is not a key at all.
 */
export const chatPrivateKey = (evmPrivateKey: string): string => {
    const key = evmPrivateKey.startsWith('0x')
        ? evmPrivateKey
        : `0x${evmPrivateKey}`;

    if (!/^0x[0-9a-fA-F]{64}$/.test(key)) {
        throw new Error('An EVM private key is 64 hex characters');
    }

    let candidate = sha256(
        new Uint8Array([...utf8(`${DOMAIN}:key`), ...getBytes(key)]),
    );

    while (BigInt(candidate) === 0n || BigInt(candidate) >= CURVE_ORDER) {
        candidate = sha256(getBytes(candidate));
    }

    return candidate;
};

/** The 33-byte compressed public key others encrypt to. */
export const chatPublicKey = (chatPrivate: string): string =>
    new SigningKey(chatPrivate).compressedPublicKey;

export const isChatPublicKey = (value: string): boolean =>
    /^0x0[23][0-9a-fA-F]{64}$/.test(value);

/**
 * A short human-comparable digest of a published key.
 *
 * Verification out of band is the only defence left if someone can compromise
 * both the relay and the address's key at once, so the number has to be
 * something two people can actually read to each other.
 */
export const chatFingerprint = (publicKey: string): string => {
    const digest = sha256(getBytes(publicKey)).slice(2, 26);

    return (digest.match(/.{4}/g) ?? []).join(' ').toUpperCase();
};

/* -------------------------------------------------------------- directory --- */

/** A key as it is published, and as anyone can check it. */
export type ChatKeyRecord = {
    address: string;
    publicKey: string;
    issuedAt: string;
    signature: string;
};

/**
 * The statement an address signs to publish its messaging key.
 *
 * It names the key *and* the address, so a signature cannot be presented as
 * another wallet's; and its wording matches no other message this site asks
 * for, so a signature made here can never be replayed at the login or holders'
 * endpoints. Nothing about it authorises a transfer, and it says so where the
 * user will read it.
 */
export const chatKeyStatement = (
    address: string,
    publicKey: string,
    issuedAt: string,
): string =>
    [
        'Cyberia wallet — publish my chat key.',
        'This signature publishes a public key that others use to encrypt messages to this address. It moves no funds and approves no transaction.',
        `Address: ${address.toLowerCase()}`,
        `Chat key: ${publicKey.toLowerCase()}`,
        `Issued: ${issuedAt}`,
    ].join('\n');

/**
 * Is this record really this address's key?
 *
 * Called on every key the relay serves, including ones it has served before.
 * A directory that cannot be checked is a directory that can lie.
 */
export const verifyChatKey = (record: ChatKeyRecord): boolean => {
    if (!isChatPublicKey(record.publicKey)) {
        return false;
    }

    try {
        const signer = verifyMessage(
            chatKeyStatement(record.address, record.publicKey, record.issuedAt),
            record.signature,
        );

        return signer.toLowerCase() === record.address.toLowerCase();
    } catch {
        return false;
    }
};

/* ----------------------------------------------------------- conversation --- */

/**
 * The name of a conversation, identical from both ends.
 *
 * Sorted, so the two participants derive one key rather than two, and it goes
 * into the key derivation as salt: the same pair of keys used under a
 * different pair of addresses would produce different bytes.
 */
export const conversationId = (a: string, b: string): string =>
    [a.toLowerCase(), b.toLowerCase()].sort().join(':');

/**
 * The AES key two addresses share.
 *
 * ECDH gives a point; only its x coordinate is secret material worth keeping,
 * and HKDF is what turns that into a uniform key. The key is non-extractable —
 * it exists inside WebCrypto for as long as the tab holds a reference and
 * cannot be read back out into JavaScript.
 */
export const conversationKey = async (
    chatPrivate: string,
    peerPublicKey: string,
    self: string,
    peer: string,
): Promise<CryptoKey> => {
    if (!isChatPublicKey(peerPublicKey)) {
        throw new Error('That is not a chat public key');
    }

    const point = getBytes(
        new SigningKey(chatPrivate).computeSharedSecret(peerPublicKey),
    );

    // The x coordinate alone. y is determined by it up to a sign and adds no
    // entropy, which is why every ECIES construction feeds only x to the KDF.
    const shared = new Uint8Array(new ArrayBuffer(32));
    shared.set(point.slice(1, 33));

    const material = await crypto.subtle.importKey(
        'raw',
        shared,
        'HKDF',
        false,
        ['deriveKey'],
    );

    return crypto.subtle.deriveKey(
        {
            name: 'HKDF',
            hash: 'SHA-256',
            salt: utf8(conversationId(self, peer)),
            info: utf8(DOMAIN),
        },
        material,
        { name: 'AES-GCM', length: 256 },
        false,
        ['encrypt', 'decrypt'],
    );
};

/* ------------------------------------------------------------- envelopes --- */

/** What the relay stores, and the only shape it ever sees. */
export type ChatEnvelope = {
    /** 12 random bytes, base64. Never reused under one conversation key. */
    iv: string;
    /** AES-GCM ciphertext with its tag, base64. */
    body: string;
};

/**
 * The parts of a message the relay is trusted to carry but not to alter.
 *
 * All four are authenticated as additional data, so the ciphertext only opens
 * when they are exactly what the sender wrote. A relay that changes who a
 * message came from, backdates it, or replays it into a different conversation
 * produces a message that fails to decrypt instead of a lie that reads
 * plausibly.
 */
export type ChatMeta = {
    id: string;
    from: string;
    to: string;
    sentAt: string;
};

const additionalData = (meta: ChatMeta): Uint8Array<ArrayBuffer> =>
    utf8(
        [
            DOMAIN,
            meta.id,
            meta.from.toLowerCase(),
            meta.to.toLowerCase(),
            meta.sentAt,
        ].join('|'),
    );

/** Length-prefixed, then padded with zeroes to the next block. */
const pad = (text: string): Uint8Array<ArrayBuffer> => {
    const bytes = utf8(text);

    if (bytes.length > MAX_MESSAGE_BYTES) {
        throw new Error(`A message is at most ${MAX_MESSAGE_BYTES} bytes`);
    }

    const size = Math.ceil((bytes.length + 2) / PAD_BLOCK) * PAD_BLOCK;
    const padded = new Uint8Array(size);

    padded[0] = (bytes.length >> 8) & 0xff;
    padded[1] = bytes.length & 0xff;
    padded.set(bytes, 2);

    return padded;
};

const unpad = (padded: Uint8Array): string => {
    const length = (padded[0] << 8) | padded[1];

    if (length + 2 > padded.length) {
        throw new Error('Message is malformed');
    }

    return new TextDecoder().decode(padded.slice(2, 2 + length));
};

export const sealMessage = async (
    key: CryptoKey,
    meta: ChatMeta,
    text: string,
): Promise<ChatEnvelope> => {
    const iv = crypto.getRandomValues(new Uint8Array(12));
    const body = await crypto.subtle.encrypt(
        { name: 'AES-GCM', iv, additionalData: additionalData(meta) },
        key,
        pad(text),
    );

    return { iv: toBase64(iv), body: toBase64(new Uint8Array(body)) };
};

/**
 * Open a message, or throw.
 *
 * A throw here is not an error to smooth over: it means the ciphertext, the
 * key or the metadata is not what it claims, and the UI marks that message
 * unreadable rather than guessing at its contents.
 */
export const openMessage = async (
    key: CryptoKey,
    meta: ChatMeta,
    envelope: ChatEnvelope,
): Promise<string> => {
    const plaintext = await crypto.subtle.decrypt(
        {
            name: 'AES-GCM',
            iv: fromBase64(envelope.iv),
            additionalData: additionalData(meta),
        },
        key,
        fromBase64(envelope.body),
    );

    return unpad(new Uint8Array(plaintext));
};

/** A message id: random, client-side, and part of what the tag covers. */
export const chatMessageId = (): string =>
    hexlify(crypto.getRandomValues(new Uint8Array(16))).slice(2);
