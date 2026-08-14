import type { WalletChainId } from '@/lib/wallet/chains';

/**
 * Sponsored fees, from the wallet's point of view.
 *
 * A fee is payable only in the coin the chain runs on, so an address holding
 * USDC and no CYBER cannot move its USDC — the state the send screen already
 * names as its own. On Cyberia there is something to be done about that: the
 * CyberiaGasStation contract hands such an address enough CYBER to pay for
 * itself, and the user then signs in the ordinary way.
 *
 * Nothing here signs, and nothing here is custody. The wallet asks for a
 * transfer *to its own address* and gets one; every key stays where it was.
 * That is also why the request carries no signature: a drip can only ever
 * arrive at the address named in it, so proving possession of that address
 * would cost a real person a tap and cost an attacker nothing. What limits the
 * station is what the address owns and what the contract will spend in a day.
 *
 * Cyberia only, and permanently so — sponsoring BNB or Base would mean buying
 * ETH for strangers.
 */

/** The one chain whose fees this wallet can have paid for it. */
export const SPONSORED_CHAIN: WalletChainId = 'cyberia';

/** Why the station said no. Message keys are derived from these. */
export type SponsorReason =
    | 'ok'
    | 'disabled'
    | 'paused'
    | 'empty'
    | 'hasGas'
    | 'coolingDown'
    | 'dailyCap'
    | 'holdsNothing'
    | 'quota'
    | 'unreadable';

export type SponsorEligibility = {
    ok: boolean;
    reason: SponsorReason;
    /** Seconds until this address may ask again. */
    cooldownRemaining: number;
    /** What it was granted on — 'tokens', 'nft', 'account'. */
    grounds: string | null;
};

export type SponsorStatus = {
    enabled: boolean;
    chain: string;
    station?: string | null;
    /** Wei handed over per claim, as a decimal string. */
    drip?: string | null;
    ceiling?: string | null;
    /** Seconds between claims for one address. */
    cooldown?: number | null;
    tank?: string | null;
    paused?: boolean | null;
    served?: number | null;
    address?: SponsorEligibility;
};

export type SponsorOutcome =
    | { ok: true; txHash: string; amount: bigint }
    | { ok: false; reason: SponsorReason };

/**
 * Whether it is worth offering at all.
 *
 * The question is not "is this wallet poor" but "can it pay the fee this
 * screen just quoted" — a balance that covers the fee and not the amount is
 * someone short of money, not short of gas, and the station has nothing to
 * say about that.
 */
export const canAskForGas = (
    chain: WalletChainId,
    fee: bigint | null,
    gasBalance: bigint | null,
): boolean =>
    chain === SPONSORED_CHAIN &&
    fee !== null &&
    gasBalance !== null &&
    gasBalance < fee;

/**
 * Whether one drip would actually cover what is missing.
 *
 * Offering help that leaves the user exactly as stuck is worse than saying
 * nothing, so a fee larger than the whole drip is not offered.
 */
export const dripCovers = (
    drip: string | null | undefined,
    fee: bigint | null,
    gasBalance: bigint | null,
): boolean => {
    if (!drip || fee === null) {
        return false;
    }

    try {
        return BigInt(drip) + (gasBalance ?? 0n) >= fee;
    } catch {
        return false;
    }
};

/** Message key for a refusal: 'hasGas' → 'sponsorHasGas'. */
export const sponsorReasonKey = (reason: SponsorReason): string =>
    `sponsor${reason.charAt(0).toUpperCase()}${reason.slice(1)}`;

const csrfToken = (): string => {
    if (typeof document === 'undefined') {
        return '';
    }

    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
};

/**
 * What the station is, and where one address stands with it.
 *
 * Asked only when a screen has a reason to ask — a wallet with gas never makes
 * this request, and the answer is never cached across addresses.
 */
export const gasSponsorStatus = async (
    address?: string,
): Promise<SponsorStatus> => {
    const query = address ? `?address=${encodeURIComponent(address)}` : '';

    try {
        const response = await fetch(`/api/wallet/gas${query}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            return { enabled: false, chain: SPONSORED_CHAIN };
        }

        return (await response.json()) as SponsorStatus;
    } catch {
        // An unreachable server is not a station that refused: the screen says
        // sponsorship is unavailable and the ordinary "not enough gas" note
        // stands on its own.
        return { enabled: false, chain: SPONSORED_CHAIN };
    }
};

/** Ask for the fee to be paid. Resolves once the coin has actually landed. */
export const requestGas = async (address: string): Promise<SponsorOutcome> => {
    let response: Response;

    try {
        response = await fetch('/api/wallet/gas/claim', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({ address }),
        });
    } catch {
        return { ok: false, reason: 'unreadable' };
    }

    const body = (await response.json().catch(() => ({}))) as {
        status?: string;
        txHash?: string;
        amount?: string;
        reason?: SponsorReason;
    };

    if (!response.ok || body.status !== 'sent') {
        return { ok: false, reason: body.reason ?? 'unreadable' };
    }

    return {
        ok: true,
        txHash: body.txHash ?? '',
        amount: BigInt(body.amount ?? '0'),
    };
};
