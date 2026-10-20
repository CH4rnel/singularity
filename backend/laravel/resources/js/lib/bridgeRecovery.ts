import { status } from '@/actions/App/Http/Controllers/Api/BridgeController';
import { submitBridge } from '@/lib/wallet/bridge';
import type { BridgeSubmission } from '@/lib/wallet/bridge';

export const BRIDGE_RECOVERY_KEY = 'cyberia:bridge:deposits:v1';

type RecoveryStorage = Pick<Storage, 'getItem' | 'setItem'>;

export type BridgeRecoveryEntry = {
    submission: BridgeSubmission;
    nonceReady: boolean;
    requestId: number | null;
    status: string | null;
    destinationTxHash: string | null;
    message: string | null;
    savedAt: string;
};

const storageFor = (storage?: RecoveryStorage): RecoveryStorage =>
    storage ?? localStorage;

export const bridgeRecoveryId = (entry: BridgeRecoveryEntry): string =>
    entry.submission.sourceTxHash.startsWith('0x')
        ? entry.submission.sourceTxHash.toLowerCase()
        : entry.submission.sourceTxHash;

/** Only public transfer details enter the journal; wallet material never does. */
const cleanSubmission = (value: BridgeSubmission): BridgeSubmission => ({
    direction: value.direction,
    token: value.token,
    sourceTxHash: value.sourceTxHash,
    sourceNonce: value.sourceNonce,
    sender: value.sender,
    recipient: value.recipient,
    amount: value.amount,
    reservation: value.reservation,
    convertToNative: value.convertToNative ?? false,
    // A recipient authorization is not wallet material: it can only ever pay
    // the address this wallet already chose.
    intentSignature: value.intentSignature,
});

export const loadBridgeRecovery = (
    storage?: RecoveryStorage,
): BridgeRecoveryEntry[] => {
    const raw = storageFor(storage).getItem(BRIDGE_RECOVERY_KEY);
    if (!raw) return [];
    const parsed: unknown = JSON.parse(raw);
    if (!Array.isArray(parsed)) throw new Error('Invalid bridge recovery journal');

    return parsed.map((value): BridgeRecoveryEntry => {
        const submission = value?.submission;
        if (
            !submission ||
            !['direction', 'token', 'sourceTxHash', 'sender', 'recipient', 'amount'].every(
                (key) => typeof submission[key] === 'string' && submission[key].length > 0,
            ) ||
            !Number.isSafeInteger(submission.sourceNonce) ||
            submission.sourceNonce < 0 ||
            !/^\d+(?:\.\d{1,18})?$/.test(submission.amount) ||
            (submission.reservation !== undefined && typeof submission.reservation !== 'string') ||
            (submission.convertToNative !== undefined && typeof submission.convertToNative !== 'boolean') ||
            (submission.intentSignature !== undefined && typeof submission.intentSignature !== 'string') ||
            typeof value.nonceReady !== 'boolean' ||
            (value.requestId !== null && (!Number.isSafeInteger(value.requestId) || value.requestId <= 0)) ||
            (value.status !== null && typeof value.status !== 'string') ||
            (value.destinationTxHash !== null && typeof value.destinationTxHash !== 'string') ||
            (value.message !== null && typeof value.message !== 'string') ||
            typeof value.savedAt !== 'string' || !Number.isFinite(Date.parse(value.savedAt))
        ) throw new Error('Invalid bridge recovery entry');

        return {
            submission: cleanSubmission(submission),
            nonceReady: value.nonceReady,
            requestId: value.requestId,
            status: value.status,
            destinationTxHash: value.destinationTxHash,
            message: value.message,
            savedAt: value.savedAt,
        };
    });
};

/** Check storage before any source wallet opens. An unreadable journal stays intact. */
export const assertBridgeRecoveryStorage = (storage?: RecoveryStorage): void => {
    const target = storageFor(storage);
    loadBridgeRecovery(target);
    target.setItem(BRIDGE_RECOVERY_KEY, target.getItem(BRIDGE_RECOVERY_KEY) ?? '[]');
};

const persist = (entry: BridgeRecoveryEntry, storage?: RecoveryStorage): BridgeRecoveryEntry => {
    const target = storageFor(storage);
    const entries = loadBridgeRecovery(target);
    const index = entries.findIndex((saved) => bridgeRecoveryId(saved) === bridgeRecoveryId(entry));
    if (index < 0) entries.unshift(entry);
    else entries[index] = entry;
    target.setItem(BRIDGE_RECOVERY_KEY, JSON.stringify(entries));
    return entry;
};

/** Called immediately when the source hash exists, before waiting or submitting. */
export const rememberBridgeDeposit = (
    submission: BridgeSubmission,
    nonceReady = true,
    storage?: RecoveryStorage,
): BridgeRecoveryEntry => {
    const existing = loadBridgeRecovery(storage).find((entry) =>
        bridgeRecoveryId(entry) === bridgeRecoveryId({ submission } as BridgeRecoveryEntry));
    // An entry the loader would reject must never be written: one bad row
    // makes the whole journal unreadable for every later deposit.
    const candidate = { submission: cleanSubmission(submission), nonceReady, requestId: null,
        status: null, destinationTxHash: null, message: null, savedAt: new Date().toISOString() };
    loadBridgeRecovery({ getItem: () => JSON.stringify([candidate]), setItem: () => undefined });
    return persist({
        submission: cleanSubmission(submission),
        nonceReady,
        requestId: existing?.requestId ?? null,
        status: existing?.status ?? null,
        destinationTxHash: existing?.destinationTxHash ?? null,
        message: existing?.message ?? null,
        savedAt: existing?.savedAt ?? new Date().toISOString(),
    }, storage);
};

/** The server answered the registration made elsewhere; keep its id. */
export const markBridgeDepositRegistered = (
    entry: BridgeRecoveryEntry,
    outcome: { id: number; status: string | null; destinationTxHash: string | null; message: string | null },
    storage?: RecoveryStorage,
): BridgeRecoveryEntry => persist({ ...entry, requestId: outcome.id, status: outcome.status,
    destinationTxHash: outcome.destinationTxHash, message: outcome.message }, storage);

/** This path can only register a deposit or read its status; it has no signer. */
export const continueBridgeDeposit = async (
    entry: BridgeRecoveryEntry,
    storage?: RecoveryStorage,
): Promise<BridgeRecoveryEntry> => {
    if (!entry.nonceReady) throw new Error('The source receipt must be read before registration');
    if (entry.requestId === null) {
        const outcome = await submitBridge(entry.submission);
        if (!outcome.ok || outcome.id === null) throw new Error(outcome.message ?? 'Could not register the deposit. Retry without sending another transfer.');
        return persist({ ...entry, requestId: outcome.id, status: outcome.status,
            destinationTxHash: outcome.destinationTxHash, message: outcome.message }, storage);
    }

    const response = await fetch(status(entry.requestId).url, {
        credentials: 'same-origin', headers: { Accept: 'application/json' },
    });
    const body = await response.json().catch(() => null);
    if (!response.ok || body?.id !== entry.requestId || typeof body?.status !== 'string') {
        throw new Error('Could not read the transfer status. Retry without sending another transfer.');
    }
    return persist({ ...entry, status: body.status,
        destinationTxHash: typeof body.destination_tx_hash === 'string' ? body.destination_tx_hash : null,
        message: typeof body.error_message === 'string' ? body.error_message : null }, storage);
};
