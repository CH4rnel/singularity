export type BridgeTokenSymbol = 'CYBER.sol' | 'USDC' | 'USDT';

export type BridgeTokenInfo = {
    symbol: BridgeTokenSymbol;
    /** Token contract address on Cyberia EVM (chainId 49406). */
    evmAddress: `0x${string}`;
    /** Token mint address on Solana. */
    solanaMint: string;
    /** Decimals used by the EVM ERC20. */
    evmDecimals: number;
    /** Decimals used by the Solana SPL token. */
    solanaDecimals: number;
    /**
     * Bridge mechanic.
     * - 'native': goes through CyberBridge contract (wrapped CYBER.sol — bridge contract is the wCYBER.sol owner).
     * - 'direct': plain ERC20.transfer to/from relayer hot wallet (real inventory; no mint authority).
     * - 'mint':   relayer EOA owns the wrapper contract and calls mint()/burnFrom() directly (USDC, USDT).
     */
    model: 'native' | 'direct' | 'mint';
    /** Whether the EVM-side token is on the SPL Token-2022 program (Token Extensions). */
    solanaTokenProgram: 'token' | 'token-2022';
};

export const BRIDGE_TOKENS: Record<BridgeTokenSymbol, BridgeTokenInfo> = {
    // CYBER.sol is a wrapped token — mint()/burn() on the EVM side live on the
    // WrappedCyberSol contract and are gated to the CyberBridge owner. The
    // relayer EOA cannot just ERC20.transfer because it holds no inventory.
    // So we go through the bridge contract (releaseCyberSol / redeemCyberSol).
    'CYBER.sol': {
        symbol: 'CYBER.sol',
        evmAddress: '0x7DcDa19Cf984ca708E5fA228AC148e7d82D508BA',
        solanaMint: 'E67WWiQY4s9SZbCyFVTh2CEjorEYbhuVJQUZb3Mbpump',
        evmDecimals: 18,
        solanaDecimals: 6,
        model: 'native',
        solanaTokenProgram: 'token-2022',
    },
    // USDC/USDT on Cyberia are owner-mintable wrappers (Ownable + mint/burnFrom).
    // The relayer EOA is the owner, so we mint/burn directly without intermediation.
    USDC: {
        symbol: 'USDC',
        evmAddress: '0xdc25597B19799010047F17e9591EFE08EFd40077',
        solanaMint: 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v',
        evmDecimals: 6,
        solanaDecimals: 6,
        model: 'mint',
        solanaTokenProgram: 'token',
    },
    USDT: {
        symbol: 'USDT',
        evmAddress: '0x94845aF24a3E431593A2b941b2b31836dE45185D',
        solanaMint: 'Es9vMFrzaCERmJfrF4H2FYD4KCoNkY11McCe8BenwNYB',
        evmDecimals: 6,
        solanaDecimals: 6,
        model: 'mint',
        solanaTokenProgram: 'token',
    },
};

export const SUPPORTED_TOKEN_SYMBOLS: BridgeTokenSymbol[] = [
    'CYBER.sol',
    'USDC',
    'USDT',
];

export const tokenBySymbol = (symbol: string): BridgeTokenInfo | null => {
    if (symbol === 'CYBER.sol') return BRIDGE_TOKENS['CYBER.sol'];

    const upper = symbol.toUpperCase() as BridgeTokenSymbol;

    return BRIDGE_TOKENS[upper] ?? null;
};
