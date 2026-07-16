# FAQ and Troubleshooting

## Wallet and network

**MetaMask says "wrong network" or nothing happens when I connect.**
Make sure the Cyberia network is added and selected: chain ID `49406`, RPC `https://rpc.cyberia.church` — see [getting started](getting-started.md). The apps offer a one-click network switch when a connected wallet is on another chain.

**I bridged tokens in, but my wallet shows nothing.**
The tokens are almost certainly there — MetaMask just doesn't display unknown ERC-20s automatically. Check your address on the [explorer](https://explorer.cyberia.church), then use **Import tokens** in MetaMask with the contract address from the [token list](tokens.md).

**I have tokens but transactions fail.**
You need native CYBER for gas. If you arrived via the bridge you received a small gas drop (0.01 CYBER); if it ran out, [convert some CYBER.sol](bridge.md#converting-cybersol-to-native-cyber) or buy CYBER on the [DEX](dex.md).

## Bridge

**How long does a transfer take?**
Usually a few minutes end-to-end: source-chain confirmation plus relayer verification and payout. Cyberia-side payouts confirm in seconds; payouts on slower external chains take longer.

**My bridge transfer shows "failed".**
First check the destination address on the destination chain's explorer — a payout can time out in the UI yet still confirm on-chain, especially on chains with slow RPCs. If the funds genuinely didn't arrive, the deposit is recorded against your request and the transfer can be re-run by the operator; your deposit is not lost.

**My request shows "expired".**
No deposit was detected within the monitoring window. If you did send the deposit in time, it is still honored — reopen the request/claim flow, or check back; detected late deposits are credited.

**Why is a route greyed out ("Coming soon")?**
The corridor is visible but not yet open for submissions — see the [route table](bridge.md#supported-routes) for what's live.

**What are the fees?**
Flat ~$0.10 on stablecoins only, small network-fee retention on native-coin payouts, zero bridge fee otherwise — details in [bridge.md](bridge.md#fees). Source-chain gas is always yours.

**Is the bridge trustless?**
No. It is relayer-operated by the project — read the [trust model](bridge.md#trust-model) before moving significant value.

## DEX

**"No route with liquidity found" / "Insufficient liquidity".**
No pool path currently connects the pair with enough depth for your size. Try a smaller amount, or swap in two steps through CYBER or USDC.

**The price impact looks huge.**
The pool for that pair is shallow. Trade smaller, or check whether a better-routed pair exists on the [tokens page](https://cyberia.church/tokens).

**What's WCYBER?**
Wrapped native CYBER — the ERC-20 form the DEX pools use internally. The swap UI wraps/unwraps automatically; you only touch it directly on Ritual's Wrap page.

## Assets

**Is CYBER.sol the same as CYBER?**
No — three related but distinct assets exist. Read [tokens.md](tokens.md#cyber-vs-cybersol-vs-wrapped-cybersol); the short version: CYBER is Cyberia's gas token, CYBER.sol is the Solana market token, and wrapped CYBER.sol is its bridged copy on Cyberia. A fixed one-way converter turns 1000 CYBER.sol into 1 CYBER.

**How do I verify a token is genuine?**
Compare the contract address against the [token list](tokens.md) and check it on <https://explorer.cyberia.church>. Anyone can deploy a token with any name — the address is the identity.
