/**
 * Turning a failure into something countable.
 *
 * A raw error message is the worst thing this system could store. It carries
 * addresses, amounts and node URLs — the wallet's `failure.value` is shown to
 * the user precisely because it is specific — and it does not aggregate: the
 * same out-of-gas failure reads six ways across six RPC providers, so a
 * dashboard grouping by message would report six problems where there is one.
 *
 * So nothing here ever returns the message. It returns one of a fixed set of
 * codes, and an error it cannot place becomes `unknown` rather than leaking
 * its text through a "just this once" escape hatch.
 */
import type { AnalyticsErrorCode } from '@/lib/analytics/taxonomy';

/**
 * Patterns in the order they must be tried.
 *
 * Order matters more than the patterns do: "insufficient funds for gas * price
 * + value" is an out-of-gas failure and contains the word "funds", so the gas
 * rule has to come first or every empty-tank wallet is filed as a user who
 * could not afford the amount.
 */
const RULES: [RegExp, AnalyticsErrorCode][] = [
    // The user changed their mind. Never a defect, and the single most common
    // "failure" in any wallet — folding it into the others would make every
    // success rate look like a broken product.
    [/user (rejected|denied)|action_rejected|4001|cancell?ed by user/i, 'user_rejected'],

    [/insufficient funds for gas|out of gas|gas required exceeds|intrinsic gas/i, 'insufficient_gas'],
    [/insufficient (funds|balance)|exceeds balance|not enough/i, 'insufficient_funds'],

    [/allowance|approve|erc20: transfer amount exceeds allowance/i, 'allowance'],
    [/insufficient_output_amount|slippage|price impact too high|min(imum)? ?out/i, 'slippage'],
    [/no route|no pool|no liquidity|pair does not exist|insufficient_liquidity/i, 'no_route'],
    [/expired|deadline/i, 'quote_expired'],
    [/nonce|replacement transaction underpriced|already known/i, 'nonce'],

    [/watch-only|cannot sign|is locked/i, 'watch_only'],
    [/not supported|unsupported|no adapter/i, 'unsupported'],

    [/timeout|timed out|took too long/i, 'timeout'],
    [/failed to fetch|network ?error|econnrefused|fetch failed|load failed|networkerror/i, 'rpc_unreachable'],
    [/execution reverted|revert|call_exception|transaction failed/i, 'reverted'],
    [/^request failed: [45]\d\d$|http (4|5)\d\d/i, 'server_refused'],
];

/**
 * The code for one thrown thing.
 *
 * Reads an EIP-1193 `code` first when there is one, because a numeric code is
 * a fact and a message is a translation — some wallets localise "user
 * rejected" and the regex above would then miss it.
 */
export const errorCode = (error: unknown): AnalyticsErrorCode => {
    if (error === null || error === undefined) {
        return 'unknown';
    }

    const code = (error as { code?: unknown }).code;

    if (code === 4001 || code === 'ACTION_REJECTED') {
        return 'user_rejected';
    }

    if (code === 'INSUFFICIENT_FUNDS') {
        return 'insufficient_funds';
    }

    if (code === 'CALL_EXCEPTION') {
        return 'reverted';
    }

    if (code === 'NETWORK_ERROR' || code === 'SERVER_ERROR') {
        return 'rpc_unreachable';
    }

    if (code === 'TIMEOUT') {
        return 'timeout';
    }

    const message =
        error instanceof Error
            ? error.message
            : typeof error === 'string'
              ? error
              : '';

    if (message === '') {
        return 'unknown';
    }

    for (const [pattern, mapped] of RULES) {
        if (pattern.test(message)) {
            return mapped;
        }
    }

    return 'unknown';
};
