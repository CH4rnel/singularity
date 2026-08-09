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
    'current_version' => env('APP_VERSION', 'v0.9.1'),

    'releases' => [
        [
            'version' => 'v0.9.1',
            'date' => '2026-08-09',
            'title' => 'Sealed mail between wallets',
            'sections' => [
                [
                    'label' => 'Added',
                    'items' => [
                        'Messages in the wallet, addressed by wallet address. There is no account to create, no name to claim and nobody to ask: if you know an address, you can write to whoever holds it.',
                        'Nothing you write leaves your device readable. Cyberia carries your messages the way a post office carries sealed envelopes — it passes them along and cannot open them. What it can see is which addresses are talking and when, and the wallet tells you that on the screen instead of burying it in a policy.',
                        'Nothing new to write down: the ability to read your messages comes back with the recovery phrase you already keep, and it is never the ability to spend your money.',
                        'Every wallet you talk to is remembered. If what protects an address ever changes, the wallet stops and says so rather than quietly carrying on — that is what an attempt to listen in looks like from your side.',
                        'The relay is a queue, not an archive: messages are handed over and then dropped after 30 days, and clearing your wallet from a device clears its conversations with it.',
                    ],
                ],
            ],
        ],
        [
            'version' => 'v0.9.0',
            'date' => '2026-08-08',
            'title' => 'One seed phrase, every chain',
            'sections' => [
                [
                    'label' => 'Added',
                    'items' => [
                        'A wallet of your own at /wallet, built from nothing in this release: one recovery phrase and one password give you accounts on Cyberia, Robinhood Chain, BNB Smart Chain, Base, Solana, Bitcoin, Litecoin and Monero. The keys are created on your device and stay there — Cyberia never holds them, never sees them and cannot freeze what it does not have.',
                        'Everything about your money in one place: a portfolio valued in dollars, balances and transaction history per network, receive screens with QR codes, and sending with a choice of fee.',
                        'Your tokens show up on their own. On supported networks the wallet finds what you hold and prices it; where that is not possible, a token can be added by its address.',
                        'Bitcoin and Litecoin are full accounts you can send from, not just addresses that receive.',
                        'Any network you use can be added yourself — the wallet is not limited to the ones it ships with. Networks you add are marked as such, since their connection is one you chose and we cannot vouch for it.',
                        'Every payment is spelled out in one plain sentence and signed by holding, because a payment on these networks cannot be recalled.',
                        'The Cyberia desktop and mobile apps now open straight into the wallet and work the moment they are installed — no Cyberia account required.',
                        'A launchpad token can now be launched on several networks at once, each with its own supply and liquidity, and an interrupted launch can be resumed without paying twice.',
                        'Monero joins as a wallet in its own right rather than a bridged token, with its address usable as your bridge payout destination in one click. It receives today; balances are not yet shown, and the wallet tells you so.',
                    ],
                ],
                [
                    'label' => 'Changed',
                    'items' => [
                        'Values in the wallet are the ones we can actually stand behind: a price we cannot read is shown as unavailable rather than as zero, and a total that is missing something says it is partial.',
                        'Lain can launch a token on the launchpad herself, but only after presenting the full plan — what it costs, what it opens at, and that it cannot be undone — and having that exact plan confirmed.',
                    ],
                ],
            ],
        ],
        [
            'version' => 'v0.8.0',
            'date' => '2026-07-30',
            'title' => 'Cyberia, installed',
            'sections' => [
                [
                    'label' => 'Added',
                    'items' => [
                        'Cyberia can now be installed as an app from supported mobile and desktop browsers, with a standalone window and home-screen shortcuts.',
                        'A branded offline fallback replaces the browser error page when an installed Cyberia app temporarily loses its connection.',
                    ],
                ],
            ],
        ],
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
