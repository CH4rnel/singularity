import { PublicKey } from '@solana/web3.js';
import { getAddress, isAddress } from 'ethers';

export type BridgeDirection = 'sol_to_evm' | 'evm_to_sol';

export type ValidationResult = {
    valid: boolean;
    normalized?: string;
    error?: string;
    warning?: string;
};

const B58_RE = /^[1-9A-HJ-NP-Za-km-z]{32,44}$/;
const HEX_ADDR_RE = /^0x[0-9a-fA-F]{40}$/;

export const isEvmAddress = (s: string): ValidationResult => {
    const trimmed = s.trim();

    if (!HEX_ADDR_RE.test(trimmed)) {
        return {
            valid: false,
            error: 'Not a valid EVM address (expected 0x + 40 hex chars)',
        };
    }

    try {
        const normalized = getAddress(trimmed);
        const hasMixedCase =
            /[a-f]/.test(trimmed.slice(2)) && /[A-F]/.test(trimmed.slice(2));
        const checksumMismatch = hasMixedCase && trimmed !== normalized;

        return {
            valid: true,
            normalized,
            warning: checksumMismatch
                ? 'Address checksum mismatch — double-check before sending'
                : undefined,
        };
    } catch {
        return { valid: false, error: 'Not a valid EVM address' };
    }
};

export const isSolanaAddress = (s: string): ValidationResult => {
    const trimmed = s.trim();

    if (!B58_RE.test(trimmed)) {
        return {
            valid: false,
            error: 'Not a valid Solana address (expected base58, 32–44 chars)',
        };
    }

    try {
        const key = new PublicKey(trimmed);

        if (key.toBase58() !== trimmed) {
            return { valid: false, error: 'Not a valid Solana address' };
        }

        return { valid: true, normalized: trimmed };
    } catch {
        return { valid: false, error: 'Not a valid Solana address' };
    }
};

export const validateDestination = (
    direction: BridgeDirection,
    address: string,
): ValidationResult => {
    const trimmed = address.trim();

    if (!trimmed) {
        return { valid: false, error: 'Destination address is required' };
    }

    if (direction === 'evm_to_sol') {
        if (HEX_ADDR_RE.test(trimmed) || isAddress(trimmed)) {
            return {
                valid: false,
                error: 'This looks like an EVM address. Paste a Solana address (the destination chain).',
            };
        }

        return isSolanaAddress(trimmed);
    }

    if (B58_RE.test(trimmed) && !HEX_ADDR_RE.test(trimmed)) {
        return {
            valid: false,
            error: 'This looks like a Solana address. Paste an EVM (0x…) address (the destination chain).',
        };
    }

    return isEvmAddress(trimmed);
};
