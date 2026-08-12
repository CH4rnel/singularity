/**
 * Canonical question format for auto-resolvable prediction markets.
 *
 * A market's question is a plain string on-chain, and it is the only thing an
 * explorer, a third-party UI or a bettor six weeks from now ever sees. So it
 * has to read as a question to a human *and* carry, unambiguously, everything
 * the oracle needs to decide it without judgement: which asset, which
 * direction, which threshold, which feed.
 *
 *     Will SOL be above $80 on 2026-09-01? [px:SOL>80@coingecko]
 *
 * Everything before the tag is prose and is never parsed. The tag is the whole
 * contract. A question with no tag — or with one naming an asset this build
 * cannot price — is a human-oracle market, and nothing resolves it on its own.
 *
 * Two rules the UI has to state plainly, because they decide real money:
 *   - `>` is strict. A price landing exactly on the threshold resolves NO.
 *   - The reading used is the first one the oracle takes at or after the close
 *     time, not the price at the closing instant. Nothing here can promise a
 *     price at a moment it wasn't watching.
 *
 * PHP mirror: app/Services/Predictions/PredictionQuestion.php. The two must
 * build byte-identical strings — a market is created by the browser and read
 * by the server, so a disagreement of one character is a market that takes
 * bets and can never be resolved. Vectors are pinned on both sides:
 * tests/Frontend/PredictionQuestionTest.mjs and tests/Feature/Predictions.
 */

/** Mirrors PredictionMarket.MAX_QUESTION_LENGTH. */
export const MAX_QUESTION_LENGTH = 200;

export type PredictionAsset = {
    /** Symbol as it appears in the tag. */
    symbol: string;
    /** Human name for the picker. */
    name: string;
    /** Feed the oracle reads, named in the tag so the record says where. */
    source: string;
};

/**
 * The assets the oracle can actually price. This list is the honest limit of
 * automatic resolution: it holds exactly the coins the backend already quotes
 * for the wallet (CoinGecko, plus CYBER from the CYBER.sol DEX feed). Adding a
 * row here without teaching the resolver the same symbol creates markets that
 * look automatic and are not, so both sides pin this list in tests.
 */
export const PREDICTION_ASSETS: readonly PredictionAsset[] = [
    { symbol: 'CYBER', name: 'Cyber', source: 'dexscreener' },
    { symbol: 'BTC', name: 'Bitcoin', source: 'coingecko' },
    { symbol: 'ETH', name: 'Ethereum', source: 'coingecko' },
    { symbol: 'SOL', name: 'Solana', source: 'coingecko' },
    { symbol: 'BNB', name: 'BNB', source: 'coingecko' },
    { symbol: 'LTC', name: 'Litecoin', source: 'coingecko' },
    { symbol: 'XMR', name: 'Monero', source: 'coingecko' },
];

export type PriceSpec = {
    symbol: string;
    /** true = strictly above the threshold, false = strictly below. */
    above: boolean;
    /** Canonical decimal string — never a float, see canonicalAmount(). */
    threshold: string;
    source: string;
};

const TAG_PATTERN =
    /\[px:([A-Z][A-Z0-9]{0,9})([<>])(\d+(?:\.\d+)?)@([a-z][a-z0-9-]{0,19})\]$/;

export const assetBySymbol = (symbol: string): PredictionAsset | null =>
    PREDICTION_ASSETS.find((a) => a.symbol === symbol.toUpperCase()) ?? null;

/**
 * Normalise a user-typed amount to one canonical spelling, using string
 * operations only.
 *
 * Going through a float would be the obvious way and the wrong one: the tag is
 * compared and rebuilt on two runtimes, and `0.1 + 0.2` disagrees with itself
 * in both. "080.500" and "80.5" are the same threshold and must produce the
 * same bytes. Returns null for anything that isn't a positive decimal.
 */
export const canonicalAmount = (input: string): string | null => {
    const raw = input.trim();

    if (!/^\d*(?:\.\d*)?$/.test(raw) || raw === '' || raw === '.') {
        return null;
    }

    const [whole = '', fraction = ''] = raw.split('.');
    const trimmedWhole = whole.replace(/^0+/, '');
    const trimmedFraction = fraction.replace(/0+$/, '');

    if (trimmedFraction.length > 8) {
        return null;
    }

    const value =
        (trimmedWhole === '' ? '0' : trimmedWhole) +
        (trimmedFraction === '' ? '' : `.${trimmedFraction}`);

    // A threshold of zero is never a question anyone means to ask.
    return value === '0' ? null : value;
};

/** UTC calendar date of a unix timestamp, for the prose half of the question. */
export const utcDate = (unixSeconds: number): string =>
    new Date(unixSeconds * 1000).toISOString().slice(0, 10);

/**
 * Build the on-chain question for a price market, or null when the inputs
 * cannot make one (unknown asset, unusable threshold, over the length cap).
 */
export const buildPriceQuestion = (input: {
    symbol: string;
    above: boolean;
    threshold: string;
    closeAt: number;
}): string | null => {
    const asset = assetBySymbol(input.symbol);
    const threshold = canonicalAmount(input.threshold);

    if (!asset || threshold === null || !Number.isFinite(input.closeAt)) {
        return null;
    }

    const direction = input.above ? 'above' : 'below';
    const operator = input.above ? '>' : '<';
    const question =
        `Will ${asset.symbol} be ${direction} $${threshold} on ${utcDate(input.closeAt)}? ` +
        `[px:${asset.symbol}${operator}${threshold}@${asset.source}]`;

    return question.length > MAX_QUESTION_LENGTH ? null : question;
};

/**
 * Read the machine tag off a question, or null when there is none.
 *
 * Deliberately strict: an unknown asset, an unknown feed or a source that does
 * not match the one this build would have used for that asset all read as "no
 * spec". The resolver treats those as human markets rather than guessing,
 * because guessing here pays out real money to the wrong side.
 */
export const parsePriceQuestion = (question: string): PriceSpec | null => {
    const match = TAG_PATTERN.exec(question.trim());

    if (!match) {
        return null;
    }

    const [, symbol, operator, rawThreshold, source] = match;
    const asset = assetBySymbol(symbol);
    const threshold = canonicalAmount(rawThreshold);

    if (!asset || asset.source !== source || threshold === null) {
        return null;
    }

    return { symbol: asset.symbol, above: operator === '>', threshold, source };
};

/** The prose half, for a UI that wants to show the question without its tag. */
export const questionProse = (question: string): string =>
    question.replace(TAG_PATTERN, '').trim();

/** True when this market resolves itself. */
export const isAutoResolved = (question: string): boolean =>
    parsePriceQuestion(question) !== null;
