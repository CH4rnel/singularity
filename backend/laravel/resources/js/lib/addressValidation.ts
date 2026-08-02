import { PublicKey } from '@solana/web3.js';
import { getAddress, isAddress } from 'ethers';
import { moneroAddressKind } from '@/lib/monero';

export type BridgeChain =
    | 'solana'
    | 'cyberia'
    | 'ton'
    | 'bnb'
    | 'base'
    | 'robinhood'
    | 'yenten'
    | 'bitcoin'
    | 'litecoin'
    | 'monero'
    // Config-driven chains added in config/bridge.php arrive via server props.
    | (string & {});
export type BridgeAddressType =
    | 'solana'
    | 'evm'
    | 'ton'
    | 'yenten'
    | 'bitcoin'
    | 'litecoin'
    | 'monero';
export type SourceWalletType = 'solana' | 'evm' | 'ton' | 'manual';

export type BridgeDirection =
    | 'sol_to_evm'
    | 'evm_to_sol'
    | 'ton_to_evm'
    | 'evm_to_ton'
    | 'bnb_to_evm'
    | 'evm_to_bnb'
    | 'base_to_evm'
    | 'evm_to_base'
    | 'robinhood_to_evm'
    | 'evm_to_robinhood'
    | 'yenten_to_evm'
    | 'evm_to_yenten'
    | 'btc_to_evm'
    | 'evm_to_btc'
    | 'ltc_to_evm'
    | 'evm_to_ltc'
    | 'xmr_to_evm'
    | 'evm_to_xmr'
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
    operational?: boolean;
    unavailableReason?: string | null;
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
/** Monero shape only — the checksum decides validity (lib/monero.ts). */
const MONERO_SHAPE_RE =
    /^[48][123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz]{94}([123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz]{11})?$/;

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
        sourceWallet: 'ton',
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
    base_to_evm: {
        direction: 'base_to_evm',
        source: 'base',
        destination: 'cyberia',
        sourceLabel: 'Base',
        destinationLabel: 'Cyberia EVM',
        sourceWallet: 'evm',
        destinationAddressType: 'evm',
        autoProcess: true,
    },
    evm_to_base: {
        direction: 'evm_to_base',
        source: 'cyberia',
        destination: 'base',
        sourceLabel: 'Cyberia EVM',
        destinationLabel: 'Base',
        sourceWallet: 'evm',
        destinationAddressType: 'evm',
        autoProcess: true,
    },
    robinhood_to_evm: {
        direction: 'robinhood_to_evm',
        source: 'robinhood',
        destination: 'cyberia',
        sourceLabel: 'Robinhood Chain',
        destinationLabel: 'Cyberia EVM',
        sourceWallet: 'evm',
        destinationAddressType: 'evm',
        autoProcess: true,
    },
    evm_to_robinhood: {
        direction: 'evm_to_robinhood',
        source: 'cyberia',
        destination: 'robinhood',
        sourceLabel: 'Cyberia EVM',
        destinationLabel: 'Robinhood Chain',
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
    btc_to_evm: {
        direction: 'btc_to_evm',
        source: 'bitcoin',
        destination: 'cyberia',
        sourceLabel: 'Bitcoin',
        destinationLabel: 'Cyberia EVM',
        sourceWallet: 'manual',
        destinationAddressType: 'evm',
        autoProcess: false,
    },
    evm_to_btc: {
        direction: 'evm_to_btc',
        source: 'cyberia',
        destination: 'bitcoin',
        sourceLabel: 'Cyberia EVM',
        destinationLabel: 'Bitcoin',
        sourceWallet: 'evm',
        destinationAddressType: 'bitcoin',
        autoProcess: false,
    },
    ltc_to_evm: {
        direction: 'ltc_to_evm',
        source: 'litecoin',
        destination: 'cyberia',
        sourceLabel: 'Litecoin',
        destinationLabel: 'Cyberia EVM',
        sourceWallet: 'manual',
        destinationAddressType: 'evm',
        autoProcess: false,
    },
    evm_to_ltc: {
        direction: 'evm_to_ltc',
        source: 'cyberia',
        destination: 'litecoin',
        sourceLabel: 'Cyberia EVM',
        destinationLabel: 'Litecoin',
        sourceWallet: 'evm',
        destinationAddressType: 'litecoin',
        autoProcess: false,
    },
    xmr_to_evm: {
        direction: 'xmr_to_evm',
        source: 'monero',
        destination: 'cyberia',
        sourceLabel: 'Monero',
        destinationLabel: 'Cyberia EVM',
        sourceWallet: 'manual',
        destinationAddressType: 'evm',
        autoProcess: false,
    },
    evm_to_xmr: {
        direction: 'evm_to_xmr',
        source: 'cyberia',
        destination: 'monero',
        sourceLabel: 'Cyberia EVM',
        destinationLabel: 'Monero',
        sourceWallet: 'evm',
        destinationAddressType: 'monero',
        autoProcess: false,
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

const BASE58_ALPHABET =
    '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

const base58Decode = (input: string): Uint8Array | null => {
    let num = 0n;

    for (const char of input) {
        const index = BASE58_ALPHABET.indexOf(char);

        if (index === -1) {
            return null;
        }

        num = num * 58n + BigInt(index);
    }

    const bytes: number[] = [];

    while (num > 0n) {
        bytes.unshift(Number(num % 256n));
        num /= 256n;
    }

    for (const char of input) {
        if (char !== '1') {
            break;
        }

        bytes.unshift(0);
    }

    return new Uint8Array(bytes);
};

const hasBase58Version = (address: string, versions: number[]): boolean => {
    if (!/^[1-9A-HJ-NP-Za-km-z]{26,35}$/.test(address)) {
        return false;
    }

    const decoded = base58Decode(address);

    return decoded !== null && decoded.length === 25 && versions.includes(decoded[0]);
};

const bech32Like = (address: string, hrp: string): boolean => {
    const lower = address.toLowerCase();

    if (address !== lower && address !== address.toUpperCase()) {
        return false;
    }

    return new RegExp(`^${hrp}1[ac-hj-np-z02-9]{11,71}$`).test(lower);
};

export const isBitcoinAddress = (s: string): ValidationResult => {
    const trimmed = s.trim();

    if (bech32Like(trimmed, 'bc') || hasBase58Version(trimmed, [0x00, 0x05])) {
        return { valid: true, normalized: trimmed };
    }

    return { valid: false, error: 'Not a valid Bitcoin address' };
};

export const isLitecoinAddress = (s: string): ValidationResult => {
    const trimmed = s.trim();

    if (
        bech32Like(trimmed, 'ltc') ||
        hasBase58Version(trimmed, [0x30, 0x32, 0x05])
    ) {
        return { valid: true, normalized: trimmed };
    }

    return { valid: false, error: 'Not a valid Litecoin address' };
};

export const isMoneroAddress = (s: string): ValidationResult => {
    const trimmed = s.trim();

    if (HEX_ADDR_RE.test(trimmed)) {
        return {
            valid: false,
            error: 'This is an EVM address. Paste a native Monero address (4… or 8…).',
        };
    }

    const kind = moneroAddressKind(trimmed);

    if (kind) {
        return { valid: true, normalized: trimmed };
    }

    // Right shape, failed checksum: say so, because "invalid address" reads
    // like a format problem while this is almost always one wrong character.
    if (MONERO_SHAPE_RE.test(trimmed)) {
        return {
            valid: false,
            error: 'Monero address checksum is invalid — check for a typo',
        };
    }

    return { valid: false, error: 'Not a valid Monero address' };
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

    if (route.destinationAddressType === 'bitcoin') {
        return isBitcoinAddress(trimmed);
    }

    if (route.destinationAddressType === 'litecoin') {
        return isLitecoinAddress(trimmed);
    }

    if (route.destinationAddressType === 'monero') {
        return isMoneroAddress(trimmed);
    }

    if (B58_RE.test(trimmed) && !HEX_ADDR_RE.test(trimmed)) {
        return {
            valid: false,
            error: 'This looks like a Solana address. Paste an EVM (0x…) address (the destination chain).',
        };
    }

    return isEvmAddress(trimmed);
};
