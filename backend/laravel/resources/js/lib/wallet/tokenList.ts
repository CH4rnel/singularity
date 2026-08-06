import { getAddress, isAddress } from 'ethers';
import type { WalletChainId } from '@/lib/wallet/chains';
import { sameToken } from '@/lib/wallet/erc20';

/**
 * Tokens the user added by contract address.
 *
 * Only the pair (chain, contract) is stored. Symbol, decimals and balance are
 * re-read from the contract on every unlock, so a token cannot go stale in
 * storage and a wrong decimals value cannot be persisted into a wrong-looking
 * balance. Nothing here is secret — a contract address is public — but it is
 * still a record of what someone holds, so it is cleared with the vault.
 */

const STORAGE_KEY = 'cyberia.wallet.tokens.v1';

export type ManualToken = { chain: WalletChainId; address: string };

export const readManualTokens = (): ManualToken[] => {
    if (typeof window === 'undefined') {
        return [];
    }

    try {
        const raw = window.localStorage.getItem(STORAGE_KEY);
        const parsed: unknown = raw ? JSON.parse(raw) : [];

        if (!Array.isArray(parsed)) {
            return [];
        }

        return (parsed as ManualToken[]).filter(
            (entry) =>
                typeof entry?.chain === 'string' && isAddress(entry?.address),
        );
    } catch {
        return [];
    }
};

export const writeManualTokens = (tokens: readonly ManualToken[]): void => {
    if (typeof window !== 'undefined') {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(tokens));
    }
};

/** The list with one token added, or unchanged when it is already on it. */
export const withToken = (
    tokens: readonly ManualToken[],
    chain: WalletChainId,
    address: string,
): ManualToken[] =>
    tokens.some(
        (entry) => entry.chain === chain && sameToken(entry.address, address),
    )
        ? [...tokens]
        : [...tokens, { chain, address: getAddress(address) }];

export const withoutToken = (
    tokens: readonly ManualToken[],
    chain: WalletChainId,
    address: string,
): ManualToken[] =>
    tokens.filter(
        (entry) => entry.chain !== chain || !sameToken(entry.address, address),
    );
