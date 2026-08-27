import { defineConfig } from 'vitepress';

const userGuide = [
    { text: 'Overview', link: '/user-guide/' },
    { text: 'Getting started', link: '/user-guide/getting-started' },
    { text: 'Cyberia Wallet', link: '/user-guide/wallet' },
    { text: 'Bridge', link: '/user-guide/bridge' },
    { text: 'DEX and liquidity', link: '/user-guide/dex' },
    { text: 'Tokens and contracts', link: '/user-guide/tokens' },
    { text: 'Account and profile', link: '/user-guide/account-and-profile' },
    { text: 'FAQ and troubleshooting', link: '/user-guide/faq' },
];

const developerGuide = [
    { text: 'Developer overview', link: '/developers/' },
    { text: 'Architecture', link: '/developers/architecture' },
    { text: 'Local development', link: '/developers/local-development' },
    { text: 'Run a Cyberia node', link: '/developers/running-a-node' },
    { text: 'Network reference', link: '/developers/network-reference' },
    { text: 'Inference API', link: '/ai-api' },
    { text: 'LainOS and Wired', link: '/lainos-wired' },
];

const operationsGuide = [
    { text: 'Operations overview', link: '/operations/' },
    { text: 'Operator console', link: '/console' },
    { text: 'Service monitoring', link: '/monitoring' },
    { text: 'Product analytics', link: '/product-analytics' },
    { text: 'Release process', link: '/RELEASES' },
];

export default defineConfig({
    lang: 'en-US',
    title: 'Cyberia Docs',
    titleTemplate: ':title · Cyberia Docs',
    description:
        'User, developer, and operator documentation for the Cyberia network and Singularity monorepo.',
    sitemap: {
        hostname: 'https://docs.cyberia.church',
    },
    cleanUrls: true,
    lastUpdated: true,
    rewrites: {
        'user-guide/README.md': 'user-guide/index.md',
    },
    // Campaign drafts and dated research artifacts belong in the repository,
    // but are not part of the maintained product manual or its search index.
    srcExclude: ['growth/**', 'strategy/**'],
    head: [
        ['meta', { name: 'theme-color', content: '#9d6cff' }],
        ['meta', { name: 'color-scheme', content: 'dark light' }],
    ],
    themeConfig: {
        siteTitle: 'CYBERIA / DOCS',
        nav: [
            { text: 'User guide', link: '/user-guide/' },
            { text: 'Developers', link: '/developers/' },
            { text: 'Operations', link: '/operations/' },
            { text: 'AI API', link: '/ai-api' },
            {
                text: 'Live apps',
                items: [
                    { text: 'Cyberia', link: 'https://cyberia.church' },
                    { text: 'Wallet', link: 'https://cyberia.church/wallet' },
                    { text: 'Bridge', link: 'https://bridge.cyberia.church' },
                    { text: 'Ritual DEX', link: 'https://swap.cyberia.church' },
                    { text: 'Explorer', link: 'https://explorer.cyberia.church' },
                ],
            },
        ],
        sidebar: {
            '/user-guide/': [
                { text: 'Use Cyberia', items: userGuide },
            ],
            '/developers/': [
                { text: 'Build on Cyberia', items: developerGuide },
            ],
            '/operations/': [
                { text: 'Run Cyberia', items: operationsGuide },
            ],
            '/': [
                { text: 'Use Cyberia', collapsed: false, items: userGuide },
                { text: 'Build', collapsed: true, items: developerGuide },
                { text: 'Operate', collapsed: true, items: operationsGuide },
                {
                    text: 'Project',
                    collapsed: true,
                    items: [
                        { text: 'Documentation guide', link: '/contributing' },
                        { text: 'Good first issues', link: '/GOOD_FIRST_ISSUES' },
                    ],
                },
            ],
        },
        search: {
            provider: 'local',
        },
        outline: {
            level: [2, 3],
            label: 'On this page',
        },
        socialLinks: [
            {
                icon: 'github',
                link: 'https://github.com/cyberia-temple/singularity',
            },
        ],
        editLink: {
            pattern:
                'https://github.com/cyberia-temple/singularity/edit/master/docs/:path',
            text: 'Edit this page on GitHub',
        },
        lastUpdated: {
            text: 'Last updated',
            formatOptions: {
                dateStyle: 'medium',
            },
        },
        docFooter: {
            prev: 'Previous page',
            next: 'Next page',
        },
        footer: {
            message: 'Open-source documentation for the Cyberia ecosystem.',
            copyright: 'Singularity is licensed under GPL-3.0.',
        },
    },
});
