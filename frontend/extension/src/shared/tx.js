/**
 * What a page is actually asking for, in a sentence a person can refuse.
 *
 * A signature request is a blob of hex; approving one because it looked like
 * every other blob of hex is how wallets get emptied. So the calldata is read
 * here — enough of it to name the call, its contract and, for the two calls
 * that matter most, the amount and who benefits.
 *
 * Nothing in this file trusts the page: an unknown selector is reported as
 * unknown rather than guessed at, and an approval with no ceiling is called
 * what it is instead of being rendered as a large number.
 */
import { addressGroups, formatUnits } from './format.js';

/** ERC-20 and router calls common enough to be worth naming. */
export const SELECTORS = {
    '0xa9059cbb': 'transfer',
    '0x23b872dd': 'transferFrom',
    '0x095ea7b3': 'approve',
    '0x38ed1739': 'swapExactTokensForTokens',
    '0x7ff36ab5': 'swapExactETHForTokens',
    '0x18cbafe5': 'swapExactTokensForETH',
    '0xe8e33700': 'addLiquidity',
    '0xf305d719': 'addLiquidityETH',
    '0xbaa2abde': 'removeLiquidity',
    '0xd0e30db0': 'deposit',
    '0x2e1a7d4d': 'withdraw',
    '0xa22cb465': 'setApprovalForAll',
};

/** Anything at or above this has no practical ceiling; uint256 max included. */
const UNLIMITED = 2n ** 255n;

const hex = (value) => (typeof value === 'string' ? value.toLowerCase() : '');

export const selectorOf = (data) => {
    const body = hex(data);

    return body.length >= 10 ? body.slice(0, 10) : '';
};

/** The 32-byte words after the selector, as `0x…` strings. */
export const wordsOf = (data) => {
    const body = hex(data).slice(10);
    const words = [];

    for (let at = 0; at + 64 <= body.length; at += 64) {
        words.push(`0x${body.slice(at, at + 64)}`);
    }

    return words;
};

/** The last 20 bytes of a word, as a checksum-less address. */
export const addressAt = (word) =>
    typeof word === 'string' && word.length === 66 ? `0x${word.slice(26)}` : null;

export const amountAt = (word) => {
    try {
        return BigInt(word);
    } catch {
        return null;
    }
};

/**
 * A signing request as the popup renders it: a title that names what kind of
 * request it is, one sentence of consequence, a headline, a table of facts and
 * at most one warning — more than one and none of them get read.
 *
 * The headline is the consequence. An approval used to open with "0 CYBER",
 * the biggest text on the screen saying nothing would happen, above a spender
 * cut to its two ends. `token` is what the background could read about the
 * contract (symbol, decimals); without it amounts stay in base units rather
 * than being dressed up in units the token may not use.
 */
export const describeTransaction = (tx = {}, chain, token = null) => {
    const data = hex(tx.data);
    const value = tx.value ? BigInt(tx.value) : 0n;
    const selector = selectorOf(data);
    const name = SELECTORS[selector] ?? null;
    const words = wordsOf(data);
    const symbol = chain?.symbol ?? '';
    const tokenName = token?.symbol || 'TOKEN';
    const inTokenUnits = (amount) =>
        token && Number.isInteger(token.decimals)
            ? `${formatUnits(amount, token.decimals, 6)} ${tokenName}`
            : `${amount.toString()} base units`;

    const rows = [];
    let warning = null;
    let title = value > 0n ? 'SEND' : 'CONTRACT CALL';
    let consequence = value > 0n ? `You send ${formatUnits(value, chain?.decimals ?? 18)} ${symbol}.` : null;
    let headline = `${formatUnits(value, chain?.decimals ?? 18)} ${symbol}`.trim();
    let subject = value > 0n ? 'YOU SEND' : 'CONTRACT CALL';

    if (name) {
        rows.push({ key: 'FUNCTION', value: name });
    } else if (data.length > 2) {
        rows.push({ key: 'FUNCTION', value: `UNKNOWN · ${selector || 'NO SELECTOR'}` });
    }

    rows.push({
        key: data.length > 2 ? (name === 'approve' || name === 'transfer' ? 'TOKEN' : 'CONTRACT') : 'TO',
        value: tx.to ? addressGroups(tx.to) : 'CONTRACT CREATION',
    });

    if (name === 'approve' || name === 'transfer') {
        const target = addressAt(words[0]);
        const amount = amountAt(words[1]);
        const unlimited =
            amount !== null &&
            (amount >= UNLIMITED ||
                (token && Number.isInteger(token.decimals) && amount >= 10n ** BigInt(token.decimals + 15)));

        if (target) {
            rows.push({ key: name === 'approve' ? 'SPENDER' : 'RECIPIENT', value: addressGroups(target) });
        }

        if (amount !== null) {
            rows.push({ key: 'AMOUNT', value: unlimited && name === 'approve' ? 'UNLIMITED' : inTokenUnits(amount) });
        }

        if (name === 'approve') {
            title = 'SPENDING PERMISSION';
            subject = 'YOU PERMIT';
            headline = unlimited ? `UNLIMITED ${tokenName}` : amount !== null ? inTokenUnits(amount) : tokenName;
            consequence = unlimited
                ? `The spender can move all your ${tokenName} — now and later — until you revoke it.`
                : `The spender can move up to ${headline} from this account.`;

            if (unlimited) {
                warning =
                    'This approval has no limit. The contract could move this token again later without asking you again.';
            }
        } else {
            title = 'SEND TOKEN';
            subject = 'YOU SEND';
            headline = amount !== null ? inTokenUnits(amount) : tokenName;
            consequence = target ? `You send ${headline} to the recipient below.` : null;
        }
    }

    if (name === 'setApprovalForAll' && words[1]) {
        const operator = addressAt(words[0]);
        const granted = amountAt(words[1]) === 1n;

        if (operator) {
            rows.push({ key: 'OPERATOR', value: addressGroups(operator) });
        }

        title = granted ? 'COLLECTION-WIDE PERMISSION' : 'REVOKE COLLECTION PERMISSION';
        subject = 'YOU PERMIT';
        headline = granted ? 'EVERY ITEM' : 'REVOKE';
        consequence = granted
            ? 'The operator can move every item of this collection you own, now and later, until you revoke it.'
            : 'The operator loses its permission over this collection.';

        if (granted) {
            warning = 'This grants control of every token in that collection until you revoke it.';
        }
    }

    if (name && name.startsWith('swap')) {
        title = 'SWAP';
        subject = 'YOU SEND';
        headline = value > 0n ? headline : 'TOKEN SWAP';
    }

    if (!name && data.length > 2 && value === 0n) {
        headline = 'NO VALUE';
        consequence = 'This calls a contract function the wallet cannot read. Check the data before you sign.';
    }

    return {
        title,
        consequence,
        subject,
        headline,
        rows,
        warning,
        isContract: data.length > 2,
        // The token contract the background should name, when it can.
        tokenContract: name === 'approve' || name === 'transfer' ? tx.to ?? null : null,
    };
};

/** `personal_sign` payloads are hex more often than not; show the text. */
export const describeMessage = (payload) => {
    const body = typeof payload === 'string' ? payload : '';

    if (!/^0x[0-9a-fA-F]*$/.test(body)) {
        return body;
    }

    const bytes = body.slice(2).match(/.{1,2}/g) ?? [];

    try {
        return new TextDecoder('utf-8', { fatal: true }).decode(
            Uint8Array.from(bytes, (pair) => Number.parseInt(pair, 16)),
        );
    } catch {
        // Not text: a hash, or a payload from a site that signs structured
        // data by hand. Shown as hex, because pretending otherwise hides it.
        return body;
    }
};

/**
 * Text a person can trust to say what it says.
 *
 * Format characters — zero-width joiners, right-to-left overrides — are
 * invisible but signed, and are how a message is made to read differently from
 * what it contains. They are shown as `\u{…}` escapes.
 */
export const visibleText = (text) =>
    String(text ?? '').replace(/\p{Cf}/gu, (character) => `\\u{${character.codePointAt(0).toString(16)}}`);

const SIWE = /^(\S+) wants you to sign in with your Ethereum account:\n(0x[0-9a-fA-F]{40})/;

/**
 * A `personal_sign` request, described: a sign-in is named as one and checked
 * against the site asking for it; a 32-byte payload that isn't text is a blind
 * signature and said to be.
 */
export const describeSignature = (payload, origin) => {
    const text = describeMessage(payload);
    const host = (() => {
        try {
            return new URL(origin).host;
        } catch {
            return String(origin ?? '');
        }
    })();
    const siwe = SIWE.exec(text);

    if (siwe) {
        const domain = siwe[1];
        const matches = domain.toLowerCase() === host.toLowerCase();

        return {
            title: 'SIGN-IN REQUEST',
            consequence: `Sign in to ${domain}.`,
            body: visibleText(text),
            warning: matches ? null : `${host} is asking you to sign in to a different site: ${domain}.`,
        };
    }

    // Judged on the payload, not the decoding: 32 bytes is a hash even when
    // its bytes happen to be valid UTF-8.
    if (/^0x[0-9a-fA-F]{64}$/.test(String(payload ?? ''))) {
        return {
            title: 'BLIND SIGNATURE',
            consequence: 'You can’t read what this signs.',
            body: String(payload),
            warning: `Sign only if you trust ${host} completely: a hash like this can stand for any transaction or permission.`,
        };
    }

    return {
        title: 'MESSAGE',
        consequence: 'A signature isn’t a transaction, but it can authorise one elsewhere.',
        body: visibleText(text),
        warning: null,
    };
};

const UINT160_MAX = 2n ** 160n - 1n;

const toBig = (value) => {
    try {
        return BigInt(value);
    } catch {
        return null;
    }
};

/** A unix time as a date and how far away it is: "2100-01-01 · in 26,738 days". */
export const expiryOf = (seconds, now = Date.now()) => {
    const at = Number(seconds);

    if (!Number.isFinite(at) || at <= 0) {
        return 'NONE';
    }

    const date = new Date(at * 1000).toISOString().slice(0, 10);
    const days = Math.round((at * 1000 - now) / 86_400_000);

    return days >= 0 ? `${date} · in ${days.toLocaleString('en-US')} days` : `${date} · expired`;
};

/**
 * A typed-data request, described.
 *
 * Permits (EIP-2612) and Permit2 grants are approvals signed off-chain: no fee
 * now, but a spender can move the tokens later. They used to be shown as the
 * raw `message` JSON with the domain left out — the token they cover and the
 * chain they work on were not on the screen at all.
 */
export const describeTypedData = (typed, activeChainId, now = Date.now()) => {
    const domain = typed?.domain ?? {};
    const message = typed?.message ?? {};
    const primary = String(typed?.primaryType ?? '');
    const domainChain = domain.chainId === undefined ? null : Number(domain.chainId);
    const mismatch = domainChain !== null && Number.isFinite(domainChain) && domainChain !== activeChainId;
    const network = domainChain === null ? 'NOT STATED' : String(domainChain);
    const chainWarning = mismatch
        ? `This signature names chain ${domainChain}, but the wallet is on ${activeChainId}.`
        : null;

    const permit = (token, spender, amount, unlimitedAt, expires) => {
        const unlimited = amount !== null && amount >= unlimitedAt;

        return {
            title: primary === 'Permit' ? 'OFF-CHAIN SPENDING PERMISSION' : 'OFF-CHAIN SPENDING PERMISSION (PERMIT2)',
            consequence: `Signing lets the spender move ${unlimited ? 'all' : 'some'} of this token until ${expiryOf(expires, now).split(' · ')[0]}. No fee now — it works like an approval.`,
            rows: [
                { key: 'TOKEN', value: addressGroups(token) || 'NOT STATED' },
                { key: 'SPENDER', value: addressGroups(spender) || 'NOT STATED' },
                { key: 'AMOUNT', value: unlimited ? 'UNLIMITED' : amount === null ? 'NOT STATED' : `${amount.toString()} base units` },
                { key: 'EXPIRES', value: expiryOf(expires, now) },
                { key: 'NETWORK', value: network },
                ...(primary === 'Permit' ? [] : [{ key: 'PERMIT2 CONTRACT', value: addressGroups(domain.verifyingContract) }]),
            ],
            warning:
                chainWarning ??
                (unlimited
                    ? 'This permission has no limit. Anyone holding the signature can submit it — no further prompt from this wallet.'
                    : null),
            mismatch,
        };
    };

    if (primary === 'Permit' && message.spender) {
        return permit(domain.verifyingContract, message.spender, toBig(message.value), UNLIMITED, message.deadline);
    }

    if ((primary === 'PermitSingle' || primary === 'PermitTransferFrom') && message.spender) {
        const details = message.details ?? message.permitted ?? {};

        return permit(
            details.token,
            message.spender,
            toBig(details.amount),
            UINT160_MAX,
            details.expiration ?? message.deadline ?? message.sigDeadline,
        );
    }

    return {
        title: 'SIGNATURE REQUEST',
        consequence: 'The site asks you to sign structured data. Read what it says.',
        rows: [
            { key: 'PRIMARY TYPE', value: primary || '—' },
            { key: 'DOMAIN', value: visibleText(domain.name ?? '—') },
            { key: 'NETWORK', value: network },
            ...(domain.verifyingContract ? [{ key: 'CONTRACT', value: addressGroups(domain.verifyingContract) }] : []),
        ],
        warning: chainWarning,
        mismatch,
    };
};
