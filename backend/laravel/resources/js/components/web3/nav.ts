import {
    analytics,
    bridge,
    convert,
    farm,
    feed,
    launchpad,
    leaderboard,
    lending,
    liquidity,
    market,
    pixels,
    predictions,
    staking,
    swap,
    wallet,
} from '@/routes';
import { index as daoIndex } from '@/routes/dao';
import { index as lainIndex } from '@/routes/lain';
import { index as tokensIndex } from '@/routes/tokens';

export type Web3NavItem = {
    title: string;
    href: string;
    external?: boolean;
};

export type Web3NavGroup = {
    label: string;
    items: Web3NavItem[];
};

/**
 * The wallet, alone and ahead of the groups.
 *
 * It is not a category and it does not belong inside one: everything under
 * Trade needs an account and a connected extension, and the wallet is the one
 * thing here that needs neither. Buried one hover deep it was a page nobody
 * found, which is exactly what happened.
 */
export const walletItem: Web3NavItem = { title: 'Wallet', href: wallet().url };

export const navGroups: Web3NavGroup[] = [
    {
        label: 'Trade',
        items: [
            { title: 'Swap', href: swap().url },
            { title: 'Bridge', href: bridge().url },
            { title: 'Convert', href: convert().url },
            { title: 'Lending', href: lending().url },
            { title: 'Liquidity', href: liquidity().url },
            { title: 'Farm', href: farm().url },
            { title: 'Staking', href: staking().url },
            { title: 'Launchpad', href: launchpad().url },
            { title: 'Tokens', href: tokensIndex().url },
            {
                title: 'Ritual DEX',
                href: 'https://swap.cyberia.church/',
                external: true,
            },
        ],
    },
    {
        label: 'Community',
        items: [
            { title: 'Feed', href: feed().url },
            { title: 'DAO', href: daoIndex().url },
            { title: 'Leaderboard', href: leaderboard().url },
            { title: 'NFT Market', href: market().url },
            { title: 'Pixels', href: pixels().url },
            { title: 'Predictions', href: predictions().url },
            { title: 'Talk to Lain', href: lainIndex().url },
        ],
    },
    {
        label: 'Explore',
        items: [
            { title: 'Analytics', href: analytics().url },
            {
                title: 'Explorer',
                href: 'https://explorer.cyberia.church/',
                external: true,
            },
            {
                title: 'cyberia.church',
                href: 'https://cyberia.church/',
                external: true,
            },
        ],
    },
];
