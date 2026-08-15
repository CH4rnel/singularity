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
import { formatUnits, shortAddress } from './format.js';

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
 * A signing request as the popup renders it: a headline amount, a table of
 * facts, and at most one warning — more than one and none of them get read.
 */
export const describeTransaction = (tx = {}, chain) => {
    const data = hex(tx.data);
    const value = tx.value ? BigInt(tx.value) : 0n;
    const selector = selectorOf(data);
    const name = SELECTORS[selector] ?? null;
    const words = wordsOf(data);
    const symbol = chain?.symbol ?? '';

    const rows = [];
    let warning = null;
    let headline = `${formatUnits(value, chain?.decimals ?? 18)} ${symbol}`.trim();
    let subject = value > 0n ? 'YOU SEND' : 'CONTRACT CALL';

    if (name) {
        rows.push({ key: 'FUNCTION', value: name });
    } else if (data.length > 2) {
        rows.push({ key: 'FUNCTION', value: `UNKNOWN · ${selector || 'NO SELECTOR'}` });
    }

    rows.push({
        key: data.length > 2 ? 'CONTRACT' : 'TO',
        value: shortAddress(tx.to ?? '', 8, 6) || 'CONTRACT CREATION',
    });

    if (name === 'approve' || name === 'transfer') {
        const target = addressAt(words[0]);
        const amount = amountAt(words[1]);

        if (target) {
            rows.push({ key: name === 'approve' ? 'SPENDER' : 'RECIPIENT', value: shortAddress(target, 8, 6) });
        }

        if (amount !== null) {
            // Token decimals are not knowable from calldata alone, so the raw
            // amount is shown as raw rather than dressed up as a token amount
            // in units it may not use.
            const unlimited = amount >= UNLIMITED;
            rows.push({
                key: 'AMOUNT',
                value: unlimited ? 'UNLIMITED' : `${amount.toString()} base units`,
            });

            if (name === 'approve' && unlimited) {
                warning =
                    'This approval has no limit. The contract could move this token again later without asking you again.';
            }
        }
    }

    if (name === 'setApprovalForAll' && words[1] && amountAt(words[1]) === 1n) {
        warning = 'This grants control of every token in that collection until you revoke it.';
    }

    if (name && name.startsWith('swap')) {
        subject = 'YOU SEND';
        headline = value > 0n ? headline : 'TOKEN SWAP';
    }

    if (!name && data.length > 2 && value === 0n) {
        headline = 'NO VALUE';
    }

    return {
        subject,
        headline,
        rows,
        warning,
        isContract: data.length > 2,
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
