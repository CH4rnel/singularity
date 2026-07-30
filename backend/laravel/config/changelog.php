<?php

/*
 * Versioning: v<MAJOR>.<MINOR>.<PATCH>
 *
 * - MAJOR stays 0 while the app is in alpha (no real users); 1.0.0 is the public launch.
 * - MINOR +1 for any release that adds or changes user-facing functionality.
 * - PATCH +1 for a fix-only release or a hotfix on top of the current minor.
 * - Silent deploys (typos, config, refactors) do not get a version; they land in the next release.
 * - The first entry in `releases` is the current version; keep it in sync with APP_VERSION.
 */
return [
    'current_version' => env('APP_VERSION', 'v0.7.0'),

    'releases' => [
        [
            'version' => 'v0.7.0',
            'date' => '2026-07-30',
            'title' => 'Profiles, feed & progression',
            'sections' => [
                [
                    'label' => 'Added',
                    'items' => [
                        'Public profiles now support round avatars, personal posts and a shared community feed.',
                        'XP, levels, daily streaks, quests and the leaderboard turn verified Cyberia activity into visible progression.',
                        'GitHub and Telegram accounts can be linked to a profile alongside X without creating a second Cyberia account.',
                    ],
                ],
                [
                    'label' => 'Changed',
                    'items' => [
                        'On-chain nicknames are now the canonical public identity and profile URL, replacing numeric profile addresses where a nickname exists.',
                        'Profile, retention and progression analytics give operators a clearer view of acquisition and ongoing activity.',
                    ],
                ],
                [
                    'label' => 'Fixed',
                    'items' => [
                        'The Talk to Lain transcript now scrolls inside its own bounded chat panel instead of growing and moving the whole page.',
                    ],
                ],
            ],
        ],
        [
            'version' => 'v0.6.0',
            'date' => '2026-07-25',
            'title' => 'Robinhood Chain & multichain DEX',
            'sections' => [
                [
                    'label' => 'Added',
                    'items' => [
                        'Ritual DEX on Robinhood Chain: swap and provide liquidity in ETH, CYBER and ASH, with an ETH/CYBER market.',
                        'Farming, liquidity and swaps are now multichain — the page follows your wallet\'s network, and Robinhood markets, balances and pools stay entirely separate from Cyberia\'s.',
                        'Bridge native CYBER between Cyberia and Robinhood Chain.',
                        'Single-token staking page (/staking) for CYBER, Hatcher and Orbserv solo pools.',
                    ],
                ],
                [
                    'label' => 'Changed',
                    'items' => [
                        'ASH farm rewards are unified across chains from one emission source on Cyberia (capped at 436/day); Robinhood farms are paid in bridged ASH, so the token supply never fragments.',
                        'The Telegram bot announces solo-pool stakes and links each on-chain event to its own chain\'s explorer.',
                    ],
                ],
            ],
        ],
        [
            'version' => 'v0.5.0',
            'date' => '2026-07-19',
            'title' => 'Multi-wallet, multichain',
            'sections' => [
                [
                    'label' => 'Added',
                    'items' => [
                        'One wallet picker for MetaMask, Phantom, Rabby, Trust Wallet, Coinbase Wallet, OKX Wallet, Solflare, and Backpack, with exact-provider discovery when several browser wallets are installed.',
                        'Optional WalletConnect support for QR codes and mobile deep links, opening Cyberia to wallets such as MetaMask Mobile, Trust, Rainbow, Ledger Live, OKX, and SafePal.',
                        'A network selector covering Cyberia, Robinhood Chain, Ethereum, BNB Smart Chain, Polygon, Base, Arbitrum One, and OP Mainnet. Cyberia is live; Robinhood Chain and Base integrations are in progress; the remaining networks are marked as coming soon.',
                    ],
                ],
                [
                    'label' => 'Changed',
                    'items' => [
                        'Wallet login and on-chain actions now consistently use the provider selected by the user across Cyberia EVM and Solana flows.',
                        'Multi-network wallets are merged into a single choice with explicit EVM or Solana routing instead of appearing as duplicate entries.',
                    ],
                ],
            ],
        ],
        [
            'version' => 'v0.4.0',
            'date' => '2026-07-19',
            'title' => 'Talk to Lain',
            'sections' => [
                [
                    'label' => 'Added',
                    'items' => [
                        'Talk to Lain (/lain): a personal per-user chat with the resident Cyberia agent, built on LainOS. Tool-less by design, conversations persist per account.',
                    ],
                ],
            ],
        ],
        [
            'version' => 'v0.3.0',
            'date' => '2026-07-16',
            'title' => 'Current app surface',
            'sections' => [
                [
                    'label' => 'Added',
                    'items' => [
                        'LainOS automation surfaces and the Wired on-chain game workspace.',
                        'Cyberia second-node deployment configuration for follower/RPC operations.',
                        'In-app changelog entry points in the Laravel headers.',
                    ],
                ],
                [
                    'label' => 'Changed',
                    'items' => [
                        'Expanded the public Laravel bridge, swap, lending, farming, CRM, NFT, and PixelBattle surfaces.',
                        'Updated repository orientation docs to match the current monorepo layout.',
                    ],
                ],
                [
                    'label' => 'Fixed',
                    'items' => [
                        'Bridge settlement now uses verified on-chain deposit amounts.',
                        'External EVM ERC20 balance reads source the correct chain balances.',
                    ],
                ],
            ],
        ],
        [
            'version' => 'v0.2.0',
            'date' => '2026-06-11',
            'title' => 'Public release hygiene',
            'sections' => [
                [
                    'label' => 'Added',
                    'items' => [
                        'Public Laravel analytics surface at /analytics.',
                        'Initial GitHub Actions CI for Laravel and EVM contracts.',
                        'Ritual DEX environment template without secret values.',
                    ],
                ],
                [
                    'label' => 'Changed',
                    'items' => [
                        'README explains the Singularity monorepo layout and Cyberia network components.',
                        'Local .env files are ignored and the tracked Ritual DEX .env was removed from git.',
                    ],
                ],
            ],
        ],
        [
            'version' => 'v0.1.0',
            'date' => '2026-05-29',
            'title' => 'Launchpad and bridge expansion',
            'sections' => [
                [
                    'label' => 'Added',
                    'items' => [
                        'Launchpad token records and generated site storage.',
                        'Token directory and Cyberia profile deposit-address flows.',
                        'Expanded bridge corridors for Solana, TON, BNB, Base, Bitcoin-family chains, and Monero.',
                    ],
                ],
                [
                    'label' => 'Changed',
                    'items' => [
                        'Bridge and DEX screens were refreshed around live liquidity and route availability.',
                    ],
                ],
            ],
        ],
    ],
];
