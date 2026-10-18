/**
 * What a token *is*, by chain and contract — never by ticker.
 *
 * A ticker on a permissionless chain is a claim. Cyberia's own USDC is a
 * bridge-minted representation of USD Coin, and a stranger's "USDC" airdrop
 * can carry the same ticker and name; the wallet used to draw Circle's logo on
 * both, because logos were looked up by symbol. Provenance is keyed the only
 * way that cannot be forged: the chain id and the contract address.
 *
 * Kinds:
 *   - `bridged` — minted on this chain by Cyberia's bridge against an asset
 *     held elsewhere (the public token directory, `config/tokens.php`, calls
 *     these "bridged, mint/burn representations");
 *   - `wrapped` — a one-for-one wrapper of the chain's own coin;
 *   - `issued`  — native to this chain (launchpad, partner, community tokens);
 *   - `canonical` — the asset's own issuer's contract on that chain.
 *
 * Anything absent from this registry is unverified, and is shown as such.
 * `tests/Frontend/WalletProvenanceTest.mjs` keeps the Cyberia half in step with
 * `config/tokens.php`.
 */
export type ProvenanceKind = 'bridged' | 'wrapped' | 'issued' | 'canonical';

export type Provenance = {
    kind: ProvenanceKind;
    /** The symbol this contract really answers to — the key for its logo. */
    symbol: string;
};

const CYBERIA = 49406;
const ROBINHOOD = 4663;

const registry: Record<number, Record<string, Provenance>> = {
    [CYBERIA]: {
        '0x78272aad03e4b9d7a9134e874ba6d419b534f6c9': {
            kind: 'wrapped',
            symbol: 'WCYBER',
        },
        '0x7dcda19cf984ca708e5fa228ac148e7d82d508ba': {
            kind: 'bridged',
            symbol: 'CYBER.sol',
        },
        '0x992fca0a89dd95afb17751f6cc233adb9b089df5': {
            kind: 'issued',
            symbol: 'ASH',
        },
        '0xdc25597b19799010047f17e9591efe08efd40077': {
            kind: 'bridged',
            symbol: 'USDC',
        },
        '0x94845af24a3e431593a2b941b2b31836de45185d': {
            kind: 'bridged',
            symbol: 'USDT',
        },
        '0x3ce7d8e486e16bad2fb1487fe1da4dc33237d923': {
            kind: 'bridged',
            symbol: 'RUB',
        },
        '0x9332081f308bc978fe259237850fa253131b46fa': {
            kind: 'bridged',
            symbol: 'BTC',
        },
        '0x001afd19c9d890b0cf0fcd6d654f9bfe4f264f14': {
            kind: 'bridged',
            symbol: 'LTC',
        },
        '0x53450b1d205f1e41d10b653fbbdea74160dafff4': {
            kind: 'bridged',
            symbol: 'SOL',
        },
        '0xfda2f6eeb11f1acc7ccab559133e8f07d9f81986': {
            kind: 'bridged',
            symbol: 'ETH',
        },
        '0xf7655664d5d4b0681fdd4529438a1b667bcdc7e5': {
            kind: 'bridged',
            symbol: 'BNB',
        },
        '0xe2e8d51c18d6e0fddbb9ff4bf63235d688dd00ae': {
            kind: 'bridged',
            symbol: 'XMR',
        },
        '0x60617237bc60f73c0393c7a6d7352e16df20472a': {
            kind: 'bridged',
            symbol: 'TRX',
        },
        '0x38297140d60b48f746ad83d851b852fd23ef9871': {
            kind: 'bridged',
            symbol: 'GOLD',
        },
        '0xad9dfef9d671afcf29dbdd7df360e7ca8d5ac40b': {
            kind: 'bridged',
            symbol: 'SILVER',
        },
        '0x621021f18b6404123f98b1395c418868418acf36': {
            kind: 'issued',
            symbol: 'HATCHER',
        },
        '0x19e92d8475522ff6c8f3660372b9dc6674d85cc8': {
            kind: 'issued',
            symbol: 'ORBV',
        },
        '0x4945419cceef0dc70b054700de2750a056b03ee3': {
            kind: 'issued',
            symbol: 'KRSQ',
        },
        '0x3a5820be90c3fb9c5f3fb47a4859544193b0f8c6': {
            kind: 'issued',
            symbol: 'YTN',
        },
        '0x05cd1afd5b2df3cca6ceab80cbc21168ec981e8b': {
            kind: 'issued',
            symbol: 'LAIN',
        },
        '0xd8c1f812add03ccde8d3c7f86fead181980cd7ec': {
            kind: 'issued',
            symbol: 'MINE',
        },
        '0x3d32fe83ad0c1157fddca0a3280764c495cdad6d': {
            kind: 'issued',
            symbol: 'TG',
        },
        '0xeb91ec10462a249b9922d6d62fb2be73bd084ade': {
            kind: 'issued',
            symbol: 'GOAL',
        },
    },
    [ROBINHOOD]: {
        // The L2 WETH Robinhood's own gateway deploys, and the chain's dollar.
        '0x0bd7d308f8e1639fab988df18a8011f41eacad73': {
            kind: 'canonical',
            symbol: 'WETH',
        },
        '0x5fc5360d0400a0fd4f2af552add042d716f1d168': {
            kind: 'canonical',
            symbol: 'USDG',
        },
        // CYBER as the bridge's relayer mints it on Robinhood Chain.
        '0x753979e6585cca139fbb1918966d563a25eeb3b2': {
            kind: 'bridged',
            symbol: 'CYBER',
        },
    },
};

export const provenanceOf = (
    chainId: number | undefined,
    address: string | null | undefined,
): Provenance | undefined =>
    chainId === undefined || !address
        ? undefined
        : registry[chainId]?.[address.toLowerCase()];

/** Every address the registry vouches for on one chain — for tests and pickers. */
export const registeredAddresses = (chainId: number): string[] =>
    Object.keys(registry[chainId] ?? {});
