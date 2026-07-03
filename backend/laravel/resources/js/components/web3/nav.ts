import {
    analytics,
    bridge,
    convert,
    farm,
    launchpad,
    lending,
    liquidity,
    market,
    pixels,
    predictions,
    slots,
} from '@/routes';
import { index as daoIndex } from '@/routes/dao';
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

export const navGroups: Web3NavGroup[] = [
    {
        label: 'Trade',
        items: [
            { title: 'Bridge', href: bridge().url },
            { title: 'Convert', href: convert().url },
            { title: 'Lending', href: lending().url },
            { title: 'Liquidity', href: liquidity().url },
            { title: 'Farm', href: farm().url },
            { title: 'Launchpad', href: launchpad().url },
            { title: 'Tokens', href: tokensIndex().url },
            {
                title: 'Swap',
                href: 'https://swap.cyberia.church/',
                external: true,
            },
        ],
    },
    {
        label: 'Community',
        items: [
            { title: 'DAO', href: daoIndex().url },
            { title: 'NFT Market', href: market().url },
            { title: 'Pixels', href: pixels().url },
            { title: 'Predictions', href: predictions().url },
            { title: 'Slots', href: slots().url },
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
