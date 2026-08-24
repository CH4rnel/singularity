/**
 * What the browser is allowed to conclude from a capacity answer.
 *
 * The interface is not a security boundary — the server reserves capacity
 * before anything is signed, and refuses there. But the interface is where a
 * user finds out, and it has one hard rule of its own: it must never walk
 * somebody into a wallet prompt on a number it does not have.
 *
 * `available: null` used to mean both "the relayer mints here, there is no
 * ceiling" and "we could not read the balance". Under the second one the
 * wizard behaved exactly as it did under the first. These states keep them
 * apart, and `loading` — the state before the first answer — is a third thing
 * that is neither.
 */
export type CapacityState =
    | 'loading'
    | 'unlimited'
    | 'available'
    | 'unmeasured'
    | 'unavailable';

export type DestinationCapacity = {
    state: CapacityState;
    /** Human-readable ceiling, or null when there is no finite number. */
    available: string | null;
    /** The same ceiling as an integer string in `decimals` units. */
    availableRaw: string | null;
    decimals: number | null;
    reason: string | null;
};

export const LOADING_CAPACITY: DestinationCapacity = {
    state: 'loading',
    available: null,
    availableRaw: null,
    decimals: null,
    reason: null,
};

/** A read that never returned. Fails closed, exactly like the server's. */
export const unreadableCapacity = (reason: string): DestinationCapacity => ({
    state: 'unavailable',
    available: null,
    availableRaw: null,
    decimals: null,
    reason,
});

const CAPACITY_STATES: CapacityState[] = [
    'unlimited',
    'available',
    'unmeasured',
    'unavailable',
];

/**
 * Read the server's answer without trusting its shape. Anything unrecognised
 * is `unavailable`, because a payload we cannot parse is a capacity we do not
 * know — the same reasoning as a failed fetch.
 */
export const parseCapacity = (payload: unknown): DestinationCapacity => {
    if (typeof payload !== 'object' || payload === null) {
        return unreadableCapacity('malformed capacity response');
    }

    const body = payload as Record<string, unknown>;
    const state = body.state;

    if (
        typeof state !== 'string' ||
        !CAPACITY_STATES.includes(state as CapacityState)
    ) {
        return unreadableCapacity('unknown capacity state');
    }

    if (state !== 'available') {
        return {
            state: state as CapacityState,
            available: null,
            availableRaw: null,
            decimals: null,
            reason: typeof body.reason === 'string' ? body.reason : null,
        };
    }

    const availableRaw = body.available_raw;
    const decimals = body.decimals;

    // An "available" answer with no number in it is not an available answer.
    if (
        typeof availableRaw !== 'string' ||
        !/^\d+$/.test(availableRaw) ||
        typeof decimals !== 'number' ||
        !Number.isInteger(decimals) ||
        decimals < 0
    ) {
        return unreadableCapacity('capacity answer carried no usable number');
    }

    return {
        state: 'available',
        available: typeof body.available === 'string' ? body.available : null,
        availableRaw,
        decimals,
        reason: null,
    };
};

/**
 * A decimal amount as an exact integer in `decimals` units.
 *
 * Deliberately not `parseFloat` and not `Number`: 0.492836888 SOL is
 * 492836888 lamports, and a double cannot be trusted to say so at the edge —
 * which is the only place this comparison matters. Excess precision truncates
 * downward, matching the server's `TokenAmount::toRaw`.
 *
 * Returns null for anything that is not a plain positive decimal.
 */
export const toRawUnits = (amount: string, decimals: number): bigint | null => {
    const trimmed = amount.trim();

    if (!/^\d*\.?\d*$/.test(trimmed) || trimmed === '' || trimmed === '.') {
        return null;
    }

    const [whole, fraction = ''] = trimmed.split('.');
    const padded = (fraction + '0'.repeat(decimals)).slice(0, decimals);

    try {
        return BigInt(whole || '0') * 10n ** BigInt(decimals) + BigInt(padded || '0');
    } catch {
        return null;
    }
};

export type CapacityVerdict =
    | 'ok' // this amount can be delivered
    | 'idle' // no amount entered yet
    | 'loading' // still asking — do not open a wallet on this
    | 'unavailable' // the read failed — do not open a wallet on this
    | 'exceeded'; // a real number, and this is more than it

/**
 * The one question the wizard asks: may this amount go to a signature?
 *
 * `loading` and `unavailable` are separate answers on purpose. Both block, but
 * they are different sentences to a person: one is "a moment" and the other is
 * "not right now, and not because of you".
 */
export const capacityVerdict = (
    capacity: DestinationCapacity,
    amount: string,
): CapacityVerdict => {
    if (capacity.state === 'loading') {
        return 'loading';
    }

    if (capacity.state === 'unavailable') {
        return 'unavailable';
    }

    if (capacity.state === 'unlimited' || capacity.state === 'unmeasured') {
        return 'ok';
    }

    if (capacity.availableRaw === null || capacity.decimals === null) {
        return 'unavailable';
    }

    const raw = toRawUnits(amount, capacity.decimals);

    if (raw === null || raw <= 0n) {
        return 'idle';
    }

    // Equality passes: exactly the balance is deliverable.
    return raw <= BigInt(capacity.availableRaw) ? 'ok' : 'exceeded';
};

/** Whether the wizard may proceed towards a wallet prompt. */
export const capacityAllowsSigning = (
    capacity: DestinationCapacity,
    amount: string,
): boolean => capacityVerdict(capacity, amount) === 'ok';
