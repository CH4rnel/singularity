import { BRIDGE_TOKENS } from '@/lib/bridgeTokens';
import { KNOWN_TOKENS } from '@/lib/cyberiaTokens';
import { FARM_CHAINS, ROBINHOOD_CHAIN_ID } from '@/lib/farmChains';
import { LIQUIDITY_CHAINS } from '@/lib/liquidityChains';
import { bridge, farm, lending, liquidity, staking, swap } from '@/routes';

export type PartnerActionType =
    | 'bridge'
    | 'trade'
    | 'liquidity'
    | 'stake'
    | 'farm'
    | 'lend_borrow';

export type PartnerContract = {
    network: 'Cyberia' | 'Robinhood Chain' | 'Solana';
    standard: 'ERC-20' | 'SPL Token-2022';
    address: string;
    explorerUrl: string;
};

export type PartnerAction = {
    type: PartnerActionType;
    label: string;
    description: string;
    href: string;
    network: string;
};

export type PartnerCampaign = {
    slug: string;
    name: string;
    symbol: string;
    logo: string;
    description: string;
    website: string;
    networks: string[];
    contracts: PartnerContract[];
    actions: PartnerAction[];
};

const cyberiaTokenAddress = (symbol: string): string => {
    const token = KNOWN_TOKENS.find(
        (candidate) => candidate.symbol.toUpperCase() === symbol.toUpperCase(),
    );

    if (!token) {
        throw new Error(`Missing Cyberia token configuration for ${symbol}`);
    }

    return token.address;
};

const robinhoodDex = LIQUIDITY_CHAINS.find(
    (chain) => chain.chainId === ROBINHOOD_CHAIN_ID,
);
const robinhoodFarm = FARM_CHAINS.find(
    (chain) => chain.chainId === ROBINHOOD_CHAIN_ID,
);

if (!robinhoodDex || !robinhoodFarm) {
    throw new Error('Robinhood Chain DEX/farm configuration is missing');
}

const robinhoodTokenAddress = (symbol: string): string => {
    const token = robinhoodDex.tokens.find(
        (candidate) => candidate.symbol.toUpperCase() === symbol.toUpperCase(),
    );

    if (!token) {
        throw new Error(
            `Missing Robinhood Chain token configuration for ${symbol}`,
        );
    }

    return token.address;
};

const cyberiaExplorer = (address: string): string =>
    `https://explorer.cyberia.church/token/${address}`;
const robinhoodExplorer = (address: string): string =>
    `https://robinhoodchain.blockscout.com/token/${address}`;
const solanaExplorer = (mint: string): string =>
    `https://solscan.io/token/${mint}`;

const cyberiaContract = (
    symbol: string,
    address = cyberiaTokenAddress(symbol),
): PartnerContract => ({
    network: 'Cyberia',
    standard: 'ERC-20',
    address,
    explorerUrl: cyberiaExplorer(address),
});

const solanaContract = (address: string): PartnerContract => ({
    network: 'Solana',
    standard: 'SPL Token-2022',
    address,
    explorerUrl: solanaExplorer(address),
});

const commonCyberiaActions = (symbol: string): PartnerAction[] => [
    {
        type: 'trade',
        label: `Trade ${symbol}`,
        description: 'Open the live Ritual swap interface on Cyberia.',
        href: swap().url,
        network: 'Cyberia',
    },
    {
        type: 'liquidity',
        label: 'Add liquidity',
        description: 'Create or add to a Ritual liquidity position.',
        href: liquidity().url,
        network: 'Cyberia',
    },
    {
        type: 'stake',
        label: `Stake ${symbol}`,
        description:
            'Use the live single-token pool and earn variable ASH rewards.',
        href: staking().url,
        network: 'Cyberia',
    },
    {
        type: 'lend_borrow',
        label: 'Lend or borrow',
        description: 'Open the listed Cyberia lending market.',
        href: lending().url,
        network: 'Cyberia',
    },
];

const ashCyberia = cyberiaTokenAddress('ASH');
const ashRobinhood = robinhoodTokenAddress('ASH');
const hatcher = BRIDGE_TOKENS.HATCHER;
const orbv = BRIDGE_TOKENS.ORBV;

export const ROBINHOOD_GROWTH = {
    chain: robinhoodDex.evmChain,
    dex: {
        router: robinhoodDex.router,
        factory: robinhoodDex.factory,
        wrappedNative: robinhoodDex.wrappedNative,
        tokens: ['ETH', ...robinhoodDex.tokens.map((token) => token.symbol)],
        explorer: robinhoodDex.explorer,
    },
    farm: {
        masterchef: robinhoodFarm.masterchef,
        reward: 'ASH',
        pools: ['ETH / CYBER LP', 'ETH / ASH LP'],
        explorer: robinhoodFarm.explorer,
    },
} as const;

export const PARTNER_CAMPAIGNS = {
    ash: {
        slug: 'ash',
        name: 'Ash',
        symbol: 'ASH',
        logo: '/token-icons/ash.png',
        description:
            'ASH is the emission token of Ritual. It rewards liquidity providers through live farms on Cyberia and a funded, bridged farm on Robinhood Chain.',
        website: 'https://cyberia.church/token/ASH',
        networks: ['Cyberia', 'Robinhood Chain'],
        contracts: [
            cyberiaContract('ASH', ashCyberia),
            {
                network: 'Robinhood Chain',
                standard: 'ERC-20',
                address: ashRobinhood,
                explorerUrl: robinhoodExplorer(ashRobinhood),
            },
        ],
        actions: [
            {
                type: 'trade',
                label: 'Trade ASH',
                description:
                    'Swap ASH through the live Ritual pools on Cyberia or Robinhood Chain.',
                href: swap().url,
                network: 'Cyberia / Robinhood Chain',
            },
            {
                type: 'liquidity',
                label: 'Add ASH liquidity',
                description:
                    'Provide liquidity to an ASH pair on the selected network.',
                href: liquidity().url,
                network: 'Cyberia / Robinhood Chain',
            },
            {
                type: 'stake',
                label: 'Stake ASH',
                description: 'Use the live ASH single-token pool on Cyberia.',
                href: staking().url,
                network: 'Cyberia',
            },
            {
                type: 'farm',
                label: 'Stake an ASH LP',
                description:
                    'Deposit an eligible LP token in a live ASH-reward farm.',
                href: farm().url,
                network: 'Cyberia / Robinhood Chain',
            },
            {
                type: 'lend_borrow',
                label: 'Lend or borrow ASH',
                description: 'Open the listed ASH lending market on Cyberia.',
                href: lending().url,
                network: 'Cyberia',
            },
        ],
    },
    hatcher: {
        slug: 'hatcher',
        name: 'Hatcher',
        symbol: 'HATCHER',
        logo: '/token-icons/hatcher.jpg',
        description:
            'Hatcher is a managed AI-agent hosting platform. Its Solana Token-2022 asset is bridged to Cyberia for trading, liquidity, staking and lending.',
        website: 'https://hatcher.host/',
        networks: ['Solana', 'Cyberia'],
        contracts: [
            cyberiaContract('HATCHER', hatcher.evmAddress),
            solanaContract(hatcher.solanaMint),
        ],
        actions: [
            {
                type: 'bridge',
                label: 'Bridge HATCHER',
                description: 'Move HATCHER between Solana and Cyberia.',
                href: bridge().url,
                network: 'Solana ↔ Cyberia',
            },
            ...commonCyberiaActions('HATCHER'),
            {
                type: 'farm',
                label: 'Farm CYBER / HATCHER LP',
                description:
                    'Stake the live CYBER / HATCHER LP token for variable ASH rewards.',
                href: farm().url,
                network: 'Cyberia',
            },
        ],
    },
    orbserv: {
        slug: 'orbserv',
        name: 'Orbserv',
        symbol: 'ORBV',
        logo: '/token-icons/orbserv.jpg',
        description:
            'Orbserv is a financial layer for the agent economy. Canonical ORBV lives on Solana and its bridged Cyberia representation is available across Ritual and lending.',
        website: 'https://orbserv.co',
        networks: ['Solana', 'Cyberia'],
        contracts: [
            cyberiaContract('ORBV', orbv.evmAddress),
            solanaContract(orbv.solanaMint),
        ],
        actions: [
            {
                type: 'bridge',
                label: 'Bridge ORBV',
                description: 'Move ORBV between Solana and Cyberia.',
                href: bridge().url,
                network: 'Solana ↔ Cyberia',
            },
            ...commonCyberiaActions('ORBV'),
            {
                type: 'farm',
                label: 'Farm ORBV / CYBER LP',
                description:
                    'Stake the live ORBV / CYBER LP token for variable ASH rewards.',
                href: farm().url,
                network: 'Cyberia',
            },
        ],
    },
} satisfies Record<string, PartnerCampaign>;

export type PartnerSlug = keyof typeof PARTNER_CAMPAIGNS;
