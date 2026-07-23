<?php

/*
|--------------------------------------------------------------------------
| Cyberia token directory
|--------------------------------------------------------------------------
|
| Human-readable metadata for the ERC-20 tokens that live on the Cyberia
| chain, powering the public token pages (/tokens and /token/{address|symbol})
| that the analytics page links to. Addresses mirror the Ritual DEX token list
| (frontend/ritual/public/ritual-tokens.json) and the Hardhat token registry
| (crypto/hardhat/deployments/cyberia-tokens.json); the descriptions are the
| "what is it / why is it here" copy those on-chain sources don't carry.
|
| Keys are the lowercased token address. Each entry:
|   symbol   - ticker as shown on the DEX
|   name     - full ERC-20 name
|   decimals - token decimals
|   logo     - path under `logo_base` (the Ritual DEX serves these at its root)
|   category - one of `categories` below; drives grouping on /tokens
|   tagline  - one line, shown under the title
|   what     - what the token is
|   why      - why it exists on Cyberia / what it's for
|   links    - optional extra call-to-action links ([label, url]); the trade and
|              explorer links are added automatically for every token.
|
*/

return [

    // Logos are served from the Ritual DEX (its public/ dir is the token-list
    // source of truth), so a single base keeps this file in sync with it.
    'logo_base' => env('CYBERIA_TOKEN_LOGO_BASE', env('CYBERIA_SWAP_URL', 'https://swap.cyberia.church')),

    // Base URL of the Ritual DEX, used to build "Trade" deep links.
    'swap_url' => env('CYBERIA_SWAP_URL', 'https://swap.cyberia.church'),

    // Ordered category labels. The token pages render groups in this order.
    'categories' => [
        'native' => 'Native & DEX',
        'partner' => 'Partner tokens',
        'launchpad' => 'Cyberia launchpad',
        'stablecoin' => 'Stablecoins',
        'crypto' => 'Bridged crypto',
        'commodity' => 'Commodities',
        'community' => 'Community',
    ],

    'list' => [

        // ---- Native & DEX -------------------------------------------------

        '0x78272aad03e4b9d7a9134e874ba6d419b534f6c9' => [
            'symbol' => 'WCYBER',
            'name' => 'Wrapped CYBER',
            'decimals' => 18,
            'logo' => '/CYBER.png',
            'category' => 'native',
            'tagline' => 'The tradable form of Cyberia\'s native gas coin.',
            'what' => 'WCYBER is the ERC-20 wrapper around CYBER, the coin used to pay gas on the Cyberia chain — the same relationship WETH has to ETH. Each WCYBER is redeemable 1:1 for native CYBER at any time.',
            'why' => 'AMMs and most contracts can\'t hold the raw native coin, so CYBER is wrapped into WCYBER to be traded, pooled and farmed on the Ritual DEX. It is the base asset most liquidity pairs are quoted against.',
            'links' => [
                ['label' => 'Wrap / unwrap', 'url' => 'https://swap.cyberia.church/wrap'],
            ],
        ],

        '0x7dcda19cf984ca708e5fa228ac148e7d82d508ba' => [
            'symbol' => 'CYBER.sol',
            'name' => 'CYBER.sol',
            'decimals' => 18,
            'logo' => '/CYBER.png',
            'category' => 'native',
            'tagline' => 'The Solana-issued CYBER, bridged onto Cyberia.',
            'what' => 'CYBER.sol is the EVM representation of the CYBER token that lives on Solana (SPL mint E67WWiQY4s9SZbCyFVTh2CEjorEYbhuVJQUZb3Mbpump). Supply on Cyberia is minted and burned by the bridge relayer, staying backed 1:1 by the Solana-side reserve.',
            'why' => 'It lets holders of Solana CYBER move it onto the Cyberia chain to trade and provide liquidity — and back again — tying the Solana market to the native Cyberia economy.',
            'links' => [
                ['label' => 'Convert CYBER ⇄ CYBER.sol', 'url' => '/convert'],
            ],
        ],

        '0x992fca0a89dd95afb17751f6cc233adb9b089df5' => [
            'symbol' => 'ASH',
            'name' => 'Ash',
            'decimals' => 18,
            'logo' => '/logo_circle.png',
            'category' => 'native',
            'tagline' => 'The reward token of the Ritual DEX.',
            'what' => 'Ash is the emission token of Ritual, Cyberia\'s DEX. Only a 1-ASH premint exists at genesis; all further supply is minted by the farm (MasterChef) contract and handed to liquidity providers.',
            'why' => '"Every swap burns an offering — out of the fire rises Ash." Ash rewards the people who supply liquidity and keep the markets deep. Stake your LP tokens in the farms to earn it.',
            'links' => [
                ['label' => 'Farm ASH', 'url' => 'https://swap.cyberia.church/farm'],
            ],
        ],

        // ---- Stablecoins --------------------------------------------------

        '0xdc25597b19799010047f17e9591efe08efd40077' => [
            'symbol' => 'USDC',
            'name' => 'USD Coin',
            'decimals' => 6,
            'logo' => '/usdc.svg',
            'category' => 'stablecoin',
            'tagline' => 'US-dollar stablecoin and the DEX\'s pricing anchor.',
            'what' => 'USDC on Cyberia is a bridged, mint/burn representation of USD Coin, targeting a value of $1 with 6 decimals to match the original.',
            'why' => 'Together with USDT it is one of the two $1 anchors the analytics price walker starts from — every other token\'s USD price is derived by tracing pool exchange rates back to USDC/USDT. It is the main unit of account for trading on Cyberia.',
        ],

        '0x94845af24a3e431593a2b941b2b31836de45185d' => [
            'symbol' => 'USDT',
            'name' => 'Tether USD',
            'decimals' => 6,
            'logo' => '/usdt.svg',
            'category' => 'stablecoin',
            'tagline' => 'Tether USD stablecoin — the second dollar anchor.',
            'what' => 'USDT is a bridged, mint/burn representation of Tether USD, pegged to $1 with 6 decimals.',
            'why' => 'The other USD anchor alongside USDC. It provides a familiar dollar-pegged asset for pricing, quoting and settling trades on the chain.',
        ],

        '0x3ce7d8e486e16bad2fb1487fe1da4dc33237d923' => [
            'symbol' => 'RUB',
            'name' => 'Russian Ruble',
            'decimals' => 2,
            'logo' => '/rub.png',
            'category' => 'stablecoin',
            'tagline' => 'Russian ruble stablecoin.',
            'what' => 'RUB is a bridged, mint/burn ERC-20 tracking the Russian ruble, with 2-decimal (kopeck) precision.',
            'why' => 'It gives the Cyberia community a ruble-denominated unit for trading and pricing, and anchors the rouble-market tokens (the T‑Capital funds) to a familiar fiat value.',
        ],

        // ---- Bridged crypto ----------------------------------------------

        '0x9332081f308bc978fe259237850fa253131b46fa' => [
            'symbol' => 'BTC',
            'name' => 'Bitcoin',
            'decimals' => 8,
            'logo' => '/btc.svg',
            'category' => 'crypto',
            'tagline' => 'Bitcoin, represented on Cyberia.',
            'what' => 'BTC is a bridged, mint/burn representation of Bitcoin with 8 decimals (satoshi precision). Supply is driven by the bridge and backed 1:1 by BTC held in reserve.',
            'why' => 'It lets you hold and trade Bitcoin price exposure natively on Cyberia — pool it, farm it or swap in and out — without leaving the chain.',
        ],

        '0x001afd19c9d890b0cf0fcd6d654f9bfe4f264f14' => [
            'symbol' => 'LTC',
            'name' => 'Litecoin',
            'decimals' => 8,
            'logo' => '/ltc.svg',
            'category' => 'crypto',
            'tagline' => 'Litecoin on Cyberia.',
            'what' => 'LTC is a bridged Litecoin representation, 8 decimals, mint/burned by the bridge and backed 1:1 by reserve LTC.',
            'why' => 'It brings Litecoin liquidity onto Cyberia for trading and yield.',
        ],

        '0x53450b1d205f1e41d10b653fbbdea74160dafff4' => [
            'symbol' => 'SOL',
            'name' => 'Solana',
            'decimals' => 9,
            'logo' => '/sol.svg',
            'category' => 'crypto',
            'tagline' => 'Solana, bridged to Cyberia.',
            'what' => 'SOL is a bridged representation of Solana\'s SOL, using 9 decimals to match the native lamport precision.',
            'why' => 'Cyberia\'s bridge is Solana-connected, so SOL is a first-class bridged asset: move it over to trade, provide liquidity, or pair it with CYBER.sol.',
        ],

        '0xfda2f6eeb11f1acc7ccab559133e8f07d9f81986' => [
            'symbol' => 'ETH',
            'name' => 'Ethereum',
            'decimals' => 18,
            'logo' => '/ethereum-eth-logo-colored.svg',
            'category' => 'crypto',
            'tagline' => 'Ether, bridged onto Cyberia.',
            'what' => 'ETH is a bridged representation of Ethereum\'s ether, 18 decimals (wei), backed 1:1 by ETH in reserve and driven by the bridge relayer.',
            'why' => 'It lets you trade ETH exposure and pair the leading smart-contract asset against CYBER and the stablecoins on Cyberia.',
        ],

        '0xe2e8d51c18d6e0fddbb9ff4bf63235d688dd00ae' => [
            'symbol' => 'XMR',
            'name' => 'Monero',
            'decimals' => 12,
            'logo' => '/monero.svg',
            'category' => 'crypto',
            'tagline' => 'Monero on Cyberia.',
            'what' => 'XMR is a bridged Monero representation with 12 decimals, mint/burned by the bridge.',
            'why' => 'It adds privacy-coin exposure to the Cyberia markets.',
        ],

        '0x60617237bc60f73c0393c7a6d7352e16df20472a' => [
            'symbol' => 'TRX',
            'name' => 'Tron',
            'decimals' => 6,
            'logo' => '/tron.svg',
            'category' => 'crypto',
            'tagline' => 'Tron, represented on Cyberia.',
            'what' => 'TRX is a bridged Tron representation, 6 decimals, mint/burned by the bridge.',
            'why' => 'It brings TRX liquidity and price exposure onto the chain.',
        ],

        // ---- Commodities --------------------------------------------------

        '0x38297140d60b48f746ad83d851b852fd23ef9871' => [
            'symbol' => 'GOLD',
            'name' => 'Gold',
            'decimals' => 18,
            'logo' => '/gold.png',
            'category' => 'commodity',
            'tagline' => 'One token, one troy ounce of gold.',
            'what' => 'GOLD is a bridged, gold-backed ERC-20 where one whole token represents one troy ounce of allocated gold held by the custodian. 18 decimals.',
            'why' => 'It puts a hard-asset, inflation-hedge instrument on-chain so Cyberia users can hold and trade gold exposure alongside crypto and fiat.',
        ],

        '0xad9dfef9d671afcf29dbdd7df360e7ca8d5ac40b' => [
            'symbol' => 'SILVER',
            'name' => 'Silver',
            'decimals' => 18,
            'logo' => '/silver.png',
            'category' => 'commodity',
            'tagline' => 'Silver-backed token.',
            'what' => 'SILVER is a bridged, silver-backed ERC-20 (18 decimals) tracking the price of silver, mint/burned by the bridge.',
            'why' => 'A second precious-metal instrument for diversification and pricing on Cyberia. Some SILVER pools can be thin — check depth before large trades.',
        ],

        // ---- Partner tokens -----------------------------------------------

        '0x621021f18b6404123f98b1395c418868418acf36' => [
            'symbol' => 'HATCHER',
            'name' => 'Hatcher',
            'decimals' => 9,
            'logo' => '/hatcher.jpg',
            'category' => 'partner',
            'tagline' => 'Partner project — bridged to Cyberia from Solana.',
            'what' => 'Hatcher is a managed AI Agents hosting platform. It has features like easy agent deployment just by chatting, out-of-box preconfigured agents, mobile apps available, a full 3D city and rooms for agents, e-mails and many more.',
            'why' => 'As a partner token it trades on the Ritual DEX and is a listed lending market, bringing its community and Solana liquidity onto the Cyberia chain.',
            'links' => [
                ['label' => 'Website', 'url' => 'https://hatcher.host/'],
                ['label' => 'pump.fun', 'url' => 'https://pump.fun/coin/Cntmo5DJNQkB2vYyS4mUx2UoTW4mPrHgWefz8miZpump'],
            ],
        ],

        '0x19e92d8475522ff6c8f3660372b9dc6674d85cc8' => [
            'symbol' => 'ORBV',
            'name' => 'Orbserv',
            'decimals' => 6,
            'logo' => '/orbserv.jpg',
            'category' => 'partner',
            'tagline' => 'Partner project — bridged to Cyberia from Solana.',
            'what' => 'Orbserv is the financial layer for the agent economy, enabling autonomous agents to earn, spend, transact, and create value independently. The canonical ORBV supply lives on Solana; this token is its bridged form on Cyberia.',
            'why' => 'As a partner token it trades on the Ritual DEX and is a listed lending market, bringing its community and Solana liquidity onto the Cyberia chain.',
            'links' => [
                ['label' => 'Website', 'url' => 'https://orbserv.co'],
                ['label' => 'X (Twitter)', 'url' => 'https://x.com/orbserv'],
                ['label' => 'Telegram', 'url' => 'https://t.me/orbservco'],
                ['label' => 'pump.fun', 'url' => 'https://pump.fun/coin/HQJwmK24WN3e87aQzNHnUVK4SaNgYfrR3tDkGgWTpump'],
            ],
        ],

        '0x4945419cceef0dc70b054700de2750a056b03ee3' => [
            'symbol' => 'KRSQ',
            'name' => 'KARASIQUE',
            'decimals' => 18,
            'logo' => '/KARASIQUE.webp',
            'category' => 'partner',
            'tagline' => 'Partner project — the KARASIQUE token.',
            'what' => 'KRSQ (KARASIQUE) is a partner project\'s token on Cyberia, an owner-minted ERC-20 with 18 decimals.',
            'why' => 'A partner in the Cyberia ecosystem: trade KRSQ on Ritual, supply or borrow it in the lending market, and take part in its community.',
            'links' => [
                ['label' => 'Website', 'url' => 'https://akvarium228.ru/'],
                ['label' => 'Telegram', 'url' => 'https://t.me/akvarium228'],
            ],
        ],

        '0x3a5820be90c3fb9c5f3fb47a4859544193b0f8c6' => [
            'symbol' => 'YTN',
            'name' => 'Yenten',
            'decimals' => 18,
            'logo' => '/Yenten-logo.png',
            'category' => 'partner',
            'tagline' => 'Partner project — Yenten, a CPU-mined PoW coin.',
            'what' => 'YTN is the Cyberia token for Yenten, a CPU-mineable proof-of-work cryptocurrency listed as a partner project. 18 decimals, owner-minted.',
            'why' => 'As a partner token, Yenten gets an on-chain market and liquidity on Cyberia — tradable on Ritual and available in the lending market.',
            'links' => [
                ['label' => 'Website', 'url' => 'https://yentencoin.info/'],
            ],
        ],

        // ---- Cyberia launchpad --------------------------------------------

        '0x05cd1afd5b2df3cca6ceab80cbc21168ec981e8b' => [
            'symbol' => 'LAIN',
            'name' => 'Lain',
            'decimals' => 18,
            'logo' => '/lain.jpg',
            'category' => 'launchpad',
            'tagline' => 'Launched on the Cyberia launchpad.',
            'what' => 'LAIN is a community token minted through the Cyberia launchpad, themed on the Serial-Experiments-Lain motif that runs through the whole ecosystem. ERC-20, 18 decimals.',
            'why' => 'One of the tokens created on Cyberia\'s own launchpad. It leans into the Lain aesthetic — present everywhere, connected to everything. Trade it on Ritual.',
            'links' => [
                ['label' => 'Cyberia launchpad', 'url' => '/launchpad'],
            ],
        ],

        '0xd8c1f812add03ccde8d3c7f86fead181980cd7ec' => [
            'symbol' => 'MINE',
            'name' => 'Minecraft',
            'decimals' => 18,
            'logo' => '/mine.jpg',
            'category' => 'launchpad',
            'tagline' => 'Launched on the Cyberia launchpad — Minecraft-themed.',
            'what' => 'MINE is a community token minted through the Cyberia launchpad, themed after Minecraft. ERC-20, 18 decimals.',
            'why' => 'A launchpad-born token for the gaming corner of Cyberia; trade and pool it on Ritual.',
            'links' => [
                ['label' => 'Cyberia launchpad', 'url' => '/launchpad'],
            ],
        ],

        // ---- Community ----------------------------------------------------

        '0x3d32fe83ad0c1157fddca0a3280764c495cdad6d' => [
            'symbol' => 'TG',
            'name' => 'Telegram',
            'decimals' => 18,
            'logo' => '/telegram.svg',
            'category' => 'community',
            'tagline' => 'The community chat & governance token.',
            'what' => 'TG is Cyberia\'s Telegram community token: an ERC-20 with on-chain voting (ERC20Votes) minted and handed out by the community Telegram bot for participation and rewards.',
            'why' => 'It rewards active members of the Cyberia Telegram, and its vote-tracking makes it usable for community governance signals. Earn it by taking part in chat.',
        ],

        '0xeb91ec10462a249b9922d6d62fb2be73bd084ade' => [
            'symbol' => 'GOAL',
            'name' => 'Goal Bear Coin',
            'decimals' => 18,
            'logo' => '/goal.webp',
            'category' => 'community',
            'tagline' => 'Goal Bear Coin — a community meme token.',
            'what' => 'GOAL (Goal Bear Coin) is a community/meme ERC-20 on Cyberia, 18 decimals, owner-minted.',
            'why' => 'A lighthearted community token; trade and pool it on Ritual.',
        ],

    ],

];
