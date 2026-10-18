/**
 * Two checks on a recipient, from what the wallet already knows.
 *
 * Address poisoning works because wallets show addresses by their ends: an
 * attacker sends dust from an address that shares the first and last
 * characters of one you use, and waits for you to copy it from your history.
 * Nothing here calls a service — the comparison is against this wallet's own
 * addresses and its recent transfers.
 */

const body = (address: string): string =>
    address.trim().replace(/^0x/i, '').toLowerCase();

const same = (a: string, b: string): boolean => body(a) === body(b);

/**
 * A known address the candidate imitates: same first four and last four
 * characters, different in between. `null` when there is none.
 */
export const lookalikeOf = (
    candidate: string,
    known: readonly string[],
): string | null => {
    const mine = body(candidate);

    if (mine.length < 12) {
        return null;
    }

    return (
        known.find((address) => {
            const theirs = body(address);

            return (
                theirs !== mine &&
                theirs.slice(0, 4) === mine.slice(0, 4) &&
                theirs.slice(-4) === mine.slice(-4)
            );
        }) ?? null
    );
};

/** No transfer to this address in the history the wallet holds. */
export const isFirstTime = (
    candidate: string,
    sentTo: readonly string[],
): boolean => !sentTo.some((address) => same(address, candidate));
