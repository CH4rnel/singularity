import { PublicKey } from '@solana/web3.js';
import { getAddress, isAddress } from 'ethers';

export type BridgeChain =
    | 'solana'
    | 'cyberia'
    | 'ton'
    | 'bnb'
    | 'yenten'
    // Config-driven chains added in config/bridge.php arrive via server props.
    | (string & {});
export type BridgeAddressType = 'solana' | 'evm' | 'ton' | 'yenten';
export type SourceWalletType = 'solana' | 'evm' | 'manual';

export type BridgeDirection =
    | 'sol_to_evm'
    | 'evm_to_sol'
    | 'ton_to_evm'
    | 'evm_to_ton'
    | 'bnb_to_evm'
    | 'evm_to_bnb'
    | 'yenten_to_evm'
    | 'evm_to_yenten'
    // Config-driven routes added in config/bridge.php arrive via server props.
    | (string & {});

export type BridgeRoute = {
    direction: BridgeDirection;
    source: BridgeChain;
    destination: BridgeChain;
    sourceLabel: string;
    destinationLabel: string;
    sourceWallet: SourceWalletType;
    destinationAddressType: BridgeAddressType;
    autoProcess: boolean;
};

export type ValidationResult = {
    valid: boolean;
    normalized?: string;
    error?: string;
    warning?: string;
};

const B58_RE = /^[1-9A-HJ-NP-Za-km-z]{32,44}$/;
const HEX_ADDR_RE = /^0x[0-9a-fA-F]{40}$/;
const TON_RAW_RE = /^-?\d+:[0-9a-fA-F]{64}$/;
const TON_FRIENDLY_RE = /^[A-Za-z0-9_-]{48}$/;

export const BRIDGE_ROUTES: Record<string, BridgeRoute> = {
    sol_to_evm: {
        direction: 'sol_to_evm',
        source: 'solana',
        destination: 'cyberia',
        sourceLabel: 'Solana',
        destinationLabel: 'Cyberia EVM',
        sourceWallet: 'solana',
        destinationAddressType: 'evm',
        autoProcess: true,
    },
    evm_to_sol: {
        direction: 'evm_to_sol',
        source: 'cyberia',
        destination: 'solana',
        sourceLabel: 'Cyberia EVM',
        destinationLabel: 'Solana',
        sourceWallet: 'evm',
        destinationAddressType: 'solana',
        autoProcess: true,
    },
    ton_to_evm: {
        direction: 'ton_to_evm',
        source: 'ton',
        destination: 'cyberia',
        sourceLabel: 'TON',
        destinationLabel: 'Cyberia EVM',
        sourceWallet: 'manual',
        destinationAddressType: 'evm',
        autoProcess: true,
    },
    evm_to_ton: {
        direction: 'evm_to_ton',
        source: 'cyberia',
        destination: 'ton',
        sourceLabel: 'Cyberia EVM',
        destinationLabel: 'TON',
        sourceWallet: 'evm',
        destinationAddressType: 'ton',
        autoProcess: true,
    },
    bnb_to_evm: {
        direction: 'bnb_to_evm',
        source: 'bnb',
        destination: 'cyberia',
        sourceLabel: 'BNB Chain',
        destinationLabel: 'Cyberia EVM',
        sourceWallet: 'evm',
        destinationAddressType: 'evm',
        autoProcess: true,
    },
    evm_to_bnb: {
        direction: 'evm_to_bnb',
        source: 'cyberia',
        destination: 'bnb',
        sourceLabel: 'Cyberia EVM',
        destinationLabel: 'BNB Chain',
        sourceWallet: 'evm',
        destinationAddressType: 'evm',
        autoProcess: true,
    },
    yenten_to_evm: {
        direction: 'yenten_to_evm',
        source: 'yenten',
        destination: 'cyberia',
        sourceLabel: 'Yenten',
        destinationLabel: 'Cyberia EVM',
        sourceWallet: 'manual',
        destinationAddressType: 'evm',
        autoProcess: true,
    },
    evm_to_yenten: {
        direction: 'evm_to_yenten',
        source: 'cyberia',
        destination: 'yenten',
        sourceLabel: 'Cyberia EVM',
        destinationLabel: 'Yenten',
        sourceWallet: 'evm',
        destinationAddressType: 'yenten',
        autoProcess: true,
    },
};

/**
 * Server-provided route table (config/bridge.php via Inertia props) — set by
 * lib/bridgeConfig.initBridgeConfig(). When present it is authoritative, so
 * adding a chain in the backend config needs no frontend change; the static
 * BRIDGE_ROUTES above is only the pre-init/SSR fallback.
 */
let routeOverrides: Record<string, BridgeRoute> | null = null;

export const setBridgeRouteOverrides = (
    routes: BridgeRoute[] | null,
): void => {
    routeOverrides = routes
        ? Object.fromEntries(routes.map((route) => [route.direction, route]))
        : null;
};

export const bridgeRoutesList = (): BridgeRoute[] =>
    routeOverrides
        ? Object.values(routeOverrides)
        : Object.values(BRIDGE_ROUTES);

export const bridgeRoute = (direction: BridgeDirection): BridgeRoute =>
    routeOverrides?.[direction] ??
    BRIDGE_ROUTES[direction] ??
    BRIDGE_ROUTES.sol_to_evm;

export const isManualBridgeRoute = (direction: BridgeDirection): boolean =>
    bridgeRoute(direction).sourceWallet === 'manual';

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

export const isTonAddress = (s: string): ValidationResult => {
    const trimmed = s.trim();

    if (HEX_ADDR_RE.test(trimmed) || isAddress(trimmed)) {
        return {
            valid: false,
            error: 'This looks like an EVM address. Paste a TON address.',
        };
    }

    if (!TON_RAW_RE.test(trimmed) && !TON_FRIENDLY_RE.test(trimmed)) {
        return {
            valid: false,
            error: 'Not a valid TON address',
        };
    }

    return { valid: true, normalized: trimmed };
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

export const isYentenAddress = (s: string): ValidationResult => {
    const trimmed = s.trim();

    if (!/^Y[1-9A-HJ-NP-Za-km-z]{25,34}$/.test(trimmed)) {
        return {
            valid: false,
            error: 'Not a valid Yenten address (expected a legacy Y... address)',
        };
    }

    // The Laravel validator performs the Base58Check checksum verification.
    return { valid: true, normalized: trimmed };
};

export const validateDestination = (
    direction: BridgeDirection,
    address: string,
): ValidationResult => {
    const trimmed = address.trim();
    const route = bridgeRoute(direction);

    if (!trimmed) {
        return { valid: false, error: 'Destination address is required' };
    }

    if (route.destinationAddressType === 'solana') {
        if (HEX_ADDR_RE.test(trimmed) || isAddress(trimmed)) {
            return {
                valid: false,
                error: 'This looks like an EVM address. Paste a Solana address (the destination chain).',
            };
        }

        return isSolanaAddress(trimmed);
    }

    if (route.destinationAddressType === 'ton') {
        return isTonAddress(trimmed);
    }

    if (route.destinationAddressType === 'yenten') {
        return isYentenAddress(trimmed);
    }

    if (B58_RE.test(trimmed) && !HEX_ADDR_RE.test(trimmed)) {
        return {
            valid: false,
            error: 'This looks like a Solana address. Paste an EVM (0x…) address (the destination chain).',
        };
    }

    return isEvmAddress(trimmed);
};
