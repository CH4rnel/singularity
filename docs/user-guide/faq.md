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
First check the destination address on the destination chain's explorer, because the on-chain transaction may confirm after the page's monitoring window. If the destination transaction is not present, keep the request ID and source transaction hash so the recorded request can be reviewed.

**My request shows "expired".**
No deposit was detected within the monitoring window. If you did send the deposit in time, it is still honored — reopen the request/claim flow, or check back; detected late deposits are credited.

**Why is a route greyed out ("Coming soon")?**
The corridor is prepared in the bridge but is not accepting new submissions at that moment. Use the source, destination, and asset selectors to see the [currently available combinations](bridge.md#supported-routes).

**What are the fees?**
Flat ~$0.10 on stablecoins only, small network-fee retention on native-coin payouts, zero bridge fee otherwise — details in [bridge.md](bridge.md#fees). Source-chain gas is always yours.

**How does the bridge complete a transfer?**
The Cyberia relayer verifies the confirmed source transaction and performs the matching destination payout. A completed request shows both transaction hashes; see [How settlement is verified](bridge.md#how-settlement-is-verified).

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
