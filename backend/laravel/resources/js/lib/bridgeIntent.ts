/**
 * The depositor's own statement of where a bridge deposit is paid out
 * (review finding B03). A transfer to the relayer is public the moment it
 * reaches a mempool, so proving that it happened proves nothing about who may
 * name its recipient; the sending wallet has to say so itself.
 *
 * Both functions here are byte-for-byte mirrors of `App\Support\BridgeIntent`
 * and are pinned to it by tests/Frontend/BridgeIntentTest.mjs and
 * tests/Feature/Api/BridgeRecipientProofTest.php against the same vector.
 */

export type BridgeIntentRequirement =
    | 'deposit_address'
    | 'onchain'
    | 'memo'
    | 'signature';

/** EVM addresses compare case-insensitively; everything else is exact. */
export const normalizeBridgeAddress = (address: string): string => {
    const trimmed = address.trim();

    return /^0x[0-9a-fA-F]{40}$/.test(trimmed)
        ? trimmed.toLowerCase()
        : trimmed;
};

/**
 * How a corridor binds its recipient. A signature is asked for only where
 * neither the deposit address nor the transaction itself already does.
 */
export const bridgeIntentRequirement = (params: {
    direction: string;
    sourceType: string | null | undefined;
    tokenModel: string | null | undefined;
}): BridgeIntentRequirement => {
    if (
        ['yenten', 'monero', 'bitcoin', 'litecoin'].includes(
            params.sourceType ?? '',
        )
    ) {
        return 'deposit_address';
    }

    if (
        params.direction === 'evm_to_sol' &&
        (params.tokenModel ?? 'native') === 'native'
    ) {
        return 'onchain';
    }

    return params.sourceType === 'ton' ? 'memo' : 'signature';
};

/**
 * What the sending wallet signs. The reference is the capacity reservation
 * the transfer is claimed under (signed before anything is sent), or the
 * source transaction hash when a sent deposit is claimed afterwards.
 */
export const bridgeIntentMessage = (params: {
    direction: string;
    token: string;
    sender: string;
    recipient: string;
    reference: string;
}): string =>
    [
        'Cyberia bridge: payout recipient',
        '',
        'I sent this deposit and I choose where it is paid out.',
        'This is not a login and it moves no funds.',
        '',
        `Direction: ${params.direction}`,
        `Token: ${params.token}`,
        `Sender: ${normalizeBridgeAddress(params.sender)}`,
        `Recipient: ${normalizeBridgeAddress(params.recipient)}`,
        `Reference: ${params.reference.trim()}`,
    ].join('\n');

/** The comment a TON deposit carries instead of a signature. */
export const bridgeIntentMemo = (recipient: string): string =>
    `cyberia-bridge:${normalizeBridgeAddress(recipient)}`;
