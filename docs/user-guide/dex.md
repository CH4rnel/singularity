# DEX — Swapping and Liquidity

There are two trading surfaces, backed by the same on-chain QuickSwap-style (Uniswap v2) pools:

- **Ritual DEX** — <https://swap.cyberia.church>: the full DEX with swap, pools, farm, and wrap pages.
- **In-app swap** — <https://cyberia.church/swap> and <https://cyberia.church/liquidity> on the main site.

Both need a connected EVM wallet on the Cyberia network and a little CYBER for gas ([getting started](getting-started.md)).

## Swapping

1. Connect your wallet and pick the tokens and amount. Native CYBER can be traded directly — the router wraps/unwraps it automatically.
2. Review the quote: rate, price impact, and the route. The router searches across **all** liquidity pools with multi-hop routes (up to 5 hops), so pairs without a direct pool still trade through intermediate tokens.
3. For ERC-20 inputs, approve the token once, then confirm the swap. With ~1-second blocks, swaps confirm almost immediately.

Tips:

- **Watch price impact.** Some pools are small; a large order against a shallow pool moves the price a lot. The UI shows the impact before you confirm.
- **"No route with liquidity found"** means no pool path currently connects those two tokens with enough liquidity — try a smaller amount or route manually via CYBER or USDC.

## Providing liquidity

On the **Pools** page (Ritual) or **/liquidity** (main site):

1. Pick a pair and deposit both tokens at the current pool ratio.
2. You receive LP tokens representing your share; trading fees accrue to the pool.
3. Remove liquidity any time by redeeming the LP tokens.

Standard AMM caveats apply — impermanent loss is real; pool APR estimates shown in the UI are based on recent trading volume and can change quickly. Very small ("dust") pools may show no APR at all.

Providing liquidity earns the on-chain **Liquidity Farmer** achievement; a first swap earns **First Exchange** ([profile](account-and-profile.md#achievements)).

## Farm and Wrap

Ritual also has:

- **Farm** — stake LP tokens in reward farms where available.
- **Wrap** — wrap/unwrap native CYBER ↔ WCYBER (`0x78272aAd03E4b9d7A9134e874BA6d419B534F6c9`) manually. Normally you don't need this; the swap pages handle wrapping for you.

## Getting tokens to trade

Assets arrive on Cyberia through the [bridge](bridge.md). The full list of tradable assets and their contract addresses is in [tokens.md](tokens.md); live token pages with prices are at <https://cyberia.church/tokens>.
