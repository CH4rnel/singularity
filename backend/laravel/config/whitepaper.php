<?php

/*
|--------------------------------------------------------------------------
| The whitepaper, as data
|--------------------------------------------------------------------------
|
| /whitepaper used to be a .docx download. A document nobody can link into,
| quote a line of, or read on a phone is a document nobody reads, so the
| roadmap lives here as structured content and the page renders it.
|
| Two rules make this worth more than the file it replaced:
|
| 1. A phase that claims to be finished carries the address of the thing that
|    finished it, exactly as /cyber puts a contract under every claim. A
|    "Completed" with nothing to click is a promise, not a receipt.
| 2. `reviewed_at` is printed on the page. A roadmap with no date is read as
|    current forever, and this one will be stale within a quarter.
|
| The original document (June 2026) is still served from public/ and linked
| at the foot of the page — this is its text, not a summary of it, with the
| statuses brought up to what the ecosystem actually ships today.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Provenance
    |--------------------------------------------------------------------------
    |
    | `document` is the .docx the page was written from. It stays downloadable
    | because a roadmap that only exists as HTML on our own server is one a
    | reader cannot keep a copy of.
    |
    */

    'document' => '/Cyberia_Roadmap.docx',
    'document_date' => '2026-06-07',
    'reviewed_at' => '2026-09-13',

    'thesis' => 'Open source built the internet. Cyberia is building the chain that pays it back.',

    /*
    |--------------------------------------------------------------------------
    | The three claims the rest of the document rests on
    |--------------------------------------------------------------------------
    */

    'pillars' => [
        [
            'title' => 'The chain is live',
            'body' => 'Cyberia is an EVM-compatible L1 with its own explorer, RPC, bridge, DEX, DAO, lending and farming — running now, not scheduled.',
        ],
        [
            'title' => 'Open source first',
            'body' => 'The Singularity repository is the project: chain config, contracts, backend, frontends, services and scripts, public and inspectable.',
        ],
        [
            'title' => 'A builder economy',
            'body' => 'The long-term goal is to make open-source work economically visible — code, maintenance, documentation and ecosystem work rewarded on chain.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | The phases
    |--------------------------------------------------------------------------
    |
    | `status` is one of live | progress | planned | vision, and the page
    | derives its counts from this list rather than from a second figure that
    | could disagree with it.
    |
    | `links` are the receipts. External links are marked so the page can say
    | so rather than opening a new tab without warning.
    |
    */

    'phases' => [
        [
            'numeral' => 'I',
            'title' => 'Genesis: token, community, public building',
            'status' => 'live',
            'focus' => 'Genesis, $CYBER, community, building in the open',
            'outcome' => 'The identity and the thesis established',
            'summary' => 'Cyberia began as a public experiment around open-source software, community coordination and a builder-first ideology.',
            'done' => [
                '$CYBER launched on Solana',
                'A community formed around the temple identity',
                'Development happens publicly, every day',
                'The thesis established: open-source work should be paid',
                'Cyberia positioned as a software ecosystem rather than a token',
            ],
            'next' => [],
            'line' => 'Open source built the internet. Cyberia exists to help pay it back.',
            'links' => [
                ['title' => '$CYBER', 'href' => '/cyber'],
                ['title' => 'pump.fun', 'href' => 'https://pump.fun/coin/E67WWiQY4s9SZbCyFVTh2CEjorEYbhuVJQUZb3Mbpump', 'external' => true],
            ],
        ],
        [
            'numeral' => 'II',
            'title' => 'Open-source foundation: the Singularity repository',
            'status' => 'live',
            'focus' => 'The public repository and a contribution path into it',
            'outcome' => 'The system is inspectable',
            'summary' => 'A chain built for open source has to be open source itself. Everything that runs — consensus config, contracts, backend, frontends, services, scripts — is in one public repository.',
            'done' => [
                'The repository is public',
                'Chain configuration documented',
                'Contracts, backend, frontends, services and scripts all visible',
                'Contribution guidelines in place',
            ],
            'next' => [
                'Keep improving contributor documentation and setup guides',
                'Clearer issue labels and bounty pathways',
            ],
            'line' => 'If the chain is for open source, the project itself has to be open source.',
            'links' => [
                ['title' => 'Singularity on GitHub', 'href' => 'https://github.com/cyberia-temple/singularity/', 'external' => true],
                ['title' => 'Documentation', 'href' => 'https://docs.cyberia.church', 'external' => true],
            ],
        ],
        [
            'numeral' => 'III',
            'title' => 'Cyber Chain launch',
            'status' => 'live',
            'focus' => 'The L1 itself',
            'outcome' => 'A live EVM-compatible chain',
            'summary' => 'Cyberia crossed from a token narrative into live infrastructure by launching its own EVM-compatible L1, with the network configuration public.',
            'done' => [
                'The chain is live, sealing blocks about every second',
                'Chain ID 49406, native coin CYBER',
                'Public RPC at rpc.cyberia.church',
                'Block explorer at explorer.cyberia.church',
            ],
            'next' => [
                'A second node: a non-validating full/RPC follower, prepared and not yet deployed',
            ],
            'line' => 'Cyberia is not trying to launch a chain someday. The chain is live now.',
            'links' => [
                ['title' => 'Explorer', 'href' => 'https://explorer.cyberia.church/', 'external' => true],
                ['title' => 'Network details', 'href' => '/cyber'],
            ],
        ],
        [
            'numeral' => 'IV',
            'title' => 'Core network applications',
            'status' => 'live',
            'focus' => 'Explorer, bridge, DAO, lending, market, farming',
            'outcome' => 'A functioning native app stack',
            'summary' => 'The surfaces an on-chain economy needs, all reachable from cyberia.church rather than from a list of contract addresses.',
            'done' => [
                'DEX with routing across the whole pair graph, in two AMM versions',
                'Bridge, DAO, lending, NFT market, farming and staking live',
                'Launchpad: a fair launch whose liquidity is burned at birth',
                'A non-custodial multichain wallet, on the web and as desktop, mobile and extension builds',
            ],
            'next' => [
                'Keep publishing user guides for each surface',
            ],
            'line' => 'The temple is no longer theoretical. The doors are open.',
            'links' => [
                ['title' => 'Swap', 'href' => '/swap'],
                ['title' => 'Bridge', 'href' => '/bridge'],
                ['title' => 'DAO', 'href' => '/dao'],
                ['title' => 'Lending', 'href' => '/lending'],
                ['title' => 'Wallet', 'href' => '/wallet'],
            ],
        ],
        [
            'numeral' => 'V',
            'title' => 'Solana to Cyber Chain migration',
            'status' => 'live',
            'focus' => 'A path from $CYBER on Solana to the native coin',
            'outcome' => 'A fixed-rate conversion, open to anyone holding the token',
            'summary' => 'The Solana-issued token is the entry point; the native coin is the destination. The two are different assets and the page that converts them says so before it converts anything.',
            'done' => [
                'CYBER.sol bridges onto the chain as its own ERC-20',
                'A fixed-rate redeemer converts it to native CYBER at 1000 : 1 — no order book, no slippage',
                'The bridge carries the coin in both directions where the relayer holds inventory',
            ],
            'next' => [
                'Track migrated supply publicly',
                'Keep the migration guide short enough to follow on a phone',
            ],
            'line' => '$CYBER on Solana is the entry point. CYBER on Cyber Chain is the destination.',
            'links' => [
                ['title' => 'Convert', 'href' => '/convert'],
                ['title' => 'What the three CYBERs are', 'href' => '/cyber'],
            ],
        ],
        [
            'numeral' => 'VI',
            'title' => 'Cross-asset liquidity',
            'status' => 'progress',
            'focus' => 'Familiar assets, bridged in',
            'outcome' => 'An economy rather than isolated infrastructure',
            'summary' => 'A chain with one asset is a demo. The bridge carries value in from the networks people already hold it on, and every corridor is listed with the state it is actually in.',
            'done' => [
                'Corridors from Solana, BNB Chain, TON and Robinhood Chain',
                'Bitcoin, Litecoin and Monero corridors, the last one read by a wallet this project runs itself',
                'Stablecoins, ETH, SOL, BTC, LTC, gold and tokenised stocks tradable on the chain',
                'Cross-chain swaps routed for networks Cyberia has no exchange on',
            ],
            'next' => [
                'Deeper liquidity on the corridors that already carry volume',
                'Open the lanes still marked as coming soon',
            ],
            'line' => 'First the chain went live. Then the apps. Now liquidity comes to the temple.',
            'links' => [
                ['title' => 'Bridge', 'href' => '/bridge'],
                ['title' => 'Tokens', 'href' => '/tokens'],
            ],
        ],
        [
            'numeral' => 'VII',
            'title' => 'Native DeFi',
            'status' => 'progress',
            'focus' => 'Lending, farming, collateral, dashboards',
            'outcome' => 'A financial layer rather than a set of separate products',
            'summary' => 'Lending and farming exist; the work is turning them into one layer the rest of the economy can lean on.',
            'done' => [
                'Lending with on-chain liquidations',
                'Farming and staking, including solo pools',
                'Concentrated-liquidity pools alongside the constant-product ones',
            ],
            'next' => [
                'More collateral and more borrowable assets',
                'Better utilisation and risk dashboards',
                'Move key parameters under DAO visibility',
            ],
            'line' => 'DeFi here should support the open-source economy, not just exist as yield.',
            'links' => [
                ['title' => 'Lending', 'href' => '/lending'],
                ['title' => 'Farm', 'href' => '/farm'],
                ['title' => 'Liquidity', 'href' => '/liquidity'],
            ],
        ],
        [
            'numeral' => 'VIII',
            'title' => 'Real-world assets',
            'status' => 'progress',
            'focus' => 'Metals and tokenised equities',
            'outcome' => 'Real-world value, settled on chain',
            'summary' => 'Gold and silver trade on Cyberia; fifty-three tokenised shares and funds trade on Robinhood Chain and are listed in the wallet. The issuer quote and the price a trade actually pays are never blurred together.',
            'done' => [
                'Metals live and tradable',
                'Tokenised stocks listed, priced and swappable',
            ],
            'next' => [
                'Asset descriptions and verification pages',
                'Trading history and depth for each listing',
            ],
            'line' => 'Cyberia is not only settling tokens. It is beginning to settle real-world value.',
            'links' => [
                ['title' => 'Swap', 'href' => '/swap'],
                ['title' => 'Markets', 'href' => '/market'],
            ],
        ],
        [
            'numeral' => 'IX',
            'title' => 'DAO governance and treasury',
            'status' => 'progress',
            'focus' => 'Proposals, votes, treasuries',
            'outcome' => 'A community-directed allocation engine',
            'summary' => 'Governance as running software: on-chain organisations with proposals, votes, comments and treasuries — the layer that will eventually fund the work.',
            'done' => [
                'DAO live: organisations, proposals, voting and comments',
                'Voting power read from token snapshots, with EVM and Solana wallets both able to vote',
            ],
            'next' => [
                'A treasury dashboard and an archived proposal history',
                'DAO-funded contributor reward rounds',
                'Parameter votes for core application settings',
            ],
            'line' => 'A DAO should not vote on vibes. This one should become the funding layer for open-source work.',
            'links' => [
                ['title' => 'DAO', 'href' => '/dao'],
            ],
        ],
        [
            'numeral' => 'X',
            'title' => 'The open-source reward engine',
            'status' => 'planned',
            'focus' => 'Contribution, tracked and paid',
            'outcome' => 'GitHub-linked rewards claimable on chain',
            'summary' => 'The most important phase in the document: turning open-source contribution into claimable on-chain value. Nothing here ships until the anti-sybil half of it does.',
            'done' => [],
            'next' => [
                'GitHub account linking and repository verification',
                'Commit, pull request, issue and documentation tracking',
                'Contributor profiles and reward pools',
                'Anti-spam and anti-sybil protection',
                'Claimable contributor rewards on Cyber Chain',
            ],
            'line' => 'GitHub shows the work. Cyberia settles the reward.',
            'links' => [],
        ],
        [
            'numeral' => 'XI',
            'title' => 'A launch layer tied to work',
            'status' => 'progress',
            'focus' => 'Launches backed by code and contribution',
            'outcome' => 'Launch pages that show the work behind a project',
            'summary' => 'The launchpad exists and is deliberately plain: at least 10 CYBER paired against 100% of a new supply, with the LP tokens burned in the same call. The phase is about connecting a launch to the repository behind it.',
            'done' => [
                'Fair launches with liquidity burned at birth — no team allocation, no vesting, no rug lever',
                'Each launch carries its own site, pinned to IPFS, plus its socials',
            ],
            'next' => [
                'GitHub-linked project verification',
                'Launch pages showing code, commits, contributors and roadmap',
                'Contributor allocations',
                'DAO-reviewed launch categories',
            ],
            'line' => 'Launches here should be tied to work, code and public proof.',
            'links' => [
                ['title' => 'Launchpad', 'href' => '/launchpad'],
            ],
        ],
        [
            'numeral' => 'XII',
            'title' => 'The developer economy',
            'status' => 'progress',
            'focus' => 'Docs, SDKs, grants, bounties',
            'outcome' => 'Cyberia useful to a developer before they hold anything',
            'summary' => 'A chain earns developers by being useful to them first. Some of this is already standing: documentation, an inference API gated on holding rather than on a credit card, and an agent framework that runs against the chain.',
            'done' => [
                'Public documentation at docs.cyberia.church',
                'An OpenAI-compatible inference API, opened by holding the token or by paying per call',
                'LainOS: an autonomous agent framework with a Cyberia plugin',
            ],
            'next' => [
                'SDKs, contract templates and example applications',
                'Deployment guides',
                'Grants, hackathons and bug bounties',
                'An open-source project directory',
            ],
            'line' => 'Developers should not just publish code on Cyberia. They should build economies around it.',
            'links' => [
                ['title' => 'Documentation', 'href' => 'https://docs.cyberia.church', 'external' => true],
                ['title' => 'Talk to Lain', 'href' => '/lain'],
            ],
        ],
        [
            'numeral' => 'XIII',
            'title' => 'Identity and reputation',
            'status' => 'progress',
            'focus' => 'A contribution graph the rewards can trust',
            'outcome' => 'Wallet-linked builder reputation',
            'summary' => 'Rewards need reputation, or they pay noise. On-chain nicknames and achievements exist; the contribution half does not yet.',
            'done' => [
                'On-chain profiles: nicknames and achievements, written to a contract',
                'Experience, streaks, quests and a public leaderboard',
            ],
            'next' => [
                'GitHub-linked contribution history',
                'Maintainer and contributor badges',
                'Anti-sybil scoring',
                'Contribution-weighted governance experiments',
            ],
            'line' => 'Wallets show assets. GitHub shows work. Cyberia should connect both.',
            'links' => [
                ['title' => 'Profile', 'href' => '/profile'],
                ['title' => 'Leaderboard', 'href' => '/leaderboard'],
            ],
        ],
        [
            'numeral' => 'XIV',
            'title' => 'Marketplace expansion',
            'status' => 'progress',
            'focus' => 'A market for internet labour, not only internet objects',
            'outcome' => 'Digital assets, services and open-source primitives',
            'summary' => 'The NFT market is live. The long-term market expands into code, services, access, reputation and bounties.',
            'done' => [
                'NFT market live, with minting from the wallet',
                'A tracker where a release exists because somebody minted it',
            ],
            'next' => [
                'Creator and project tools',
                'Open-source licence NFTs',
                'Bounties, paid audits, API access and service listings',
            ],
            'line' => 'The market should become a marketplace for internet labour.',
            'links' => [
                ['title' => 'NFT market', 'href' => '/market'],
                ['title' => 'Tracker', 'href' => '/tracker'],
            ],
        ],
        [
            'numeral' => 'XV',
            'title' => 'Cyberia OS and self-upgrading infrastructure',
            'status' => 'progress',
            'focus' => 'A full open-source system around the chain',
            'outcome' => 'Infrastructure that maintains and upgrades itself',
            'summary' => 'An experiment rather than a product line: Linux artefacts, self-hosted services, automated deployment, and an agent that reads the repository and commits to it.',
            'done' => [
                'Self-hosted services: node, explorer, IPFS, Monero wallet, bots',
                'Automated deployment and host monitoring',
                'LainOS writes commits and its own upgrades against the live repository',
            ],
            'next' => [
                'Node operation tooling for outside operators',
                'Local developer environments',
                'Public system upgrade logs',
            ],
            'line' => 'An open-source system around the chain, not only on top of it.',
            'links' => [
                ['title' => 'Changelog', 'href' => '/changelog'],
            ],
        ],
        [
            'numeral' => 'XVI',
            'title' => 'Grants and an open-source treasury',
            'status' => 'planned',
            'focus' => 'Repeatable funding for maintainers',
            'outcome' => 'A funding machine rather than a one-off grant round',
            'summary' => 'The DAO and the reward engine converge here: protocol fees funding the people who maintain the software.',
            'done' => [],
            'next' => [
                'An open-source treasury',
                'Retroactive public-goods funding',
                'Maintainer support and repository sponsorships',
                'Protocol-fee-funded reward pools',
            ],
            'line' => 'Grants are not the end goal. A permanent open-source funding machine is.',
            'links' => [],
        ],
        [
            'numeral' => 'XVII',
            'title' => 'Transparency layer',
            'status' => 'progress',
            'focus' => 'Clean operating numbers',
            'outcome' => 'Every claim on this site checkable from a dashboard',
            'summary' => 'The brand can be a temple; the numbers have to be clean. Where a figure cannot be measured the surfaces here print a dash rather than a zero, because an unmeasured number and an empty one read identically otherwise.',
            'done' => [
                'Public analytics: pools, depth, volume and network activity',
                'Live prices and reserves read from the pool graph, never typed into a page',
                'A public changelog',
            ],
            'next' => [
                'Treasury and protocol-revenue dashboards',
                'Bridge volume, lending TVL and farming dashboards',
                'A public roadmap tracker — this page, kept honest',
            ],
            'line' => 'The brand can be a temple. The numbers need to be clean.',
            'links' => [
                ['title' => 'Analytics', 'href' => '/analytics'],
                ['title' => 'Changelog', 'href' => '/changelog'],
            ],
        ],
        [
            'numeral' => 'XVIII',
            'title' => 'The open-source settlement layer',
            'status' => 'vision',
            'focus' => 'The final form',
            'outcome' => 'A chain where open-source contribution is measured, funded and governed',
            'summary' => 'Projects launch here, contributors build public reputation, the DAO funds useful work, protocol fees support the rewards, and maintainers receive recurring funding.',
            'done' => [],
            'next' => [
                'Open-source projects launch on Cyberia',
                'Contributors build public reputation',
                'The DAO funds useful work',
                'Protocol fees support open-source rewards',
                'Maintainers receive recurring funding',
            ],
            'line' => 'The internet was built by open source. Cyberia is the chain where the builders finally get paid.',
            'links' => [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Where to check any of it
    |--------------------------------------------------------------------------
    */

    'references' => [
        ['title' => 'Cyberia', 'href' => 'https://cyberia.church/', 'external' => true],
        ['title' => 'Explorer', 'href' => 'https://explorer.cyberia.church/', 'external' => true],
        ['title' => 'Documentation', 'href' => 'https://docs.cyberia.church', 'external' => true],
        ['title' => 'Singularity on GitHub', 'href' => 'https://github.com/cyberia-temple/singularity/', 'external' => true],
        ['title' => 'Bridge', 'href' => '/bridge'],
        ['title' => 'DAO', 'href' => '/dao'],
        ['title' => 'Lending', 'href' => '/lending'],
        ['title' => 'NFT market', 'href' => '/market'],
    ],

];
