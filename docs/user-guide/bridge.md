# Bridge

The Cyberia bridge moves assets between external chains and Cyberia: <https://cyberia.church/bridge>.

## Supported routes

Availability is operator-configured and changes over time; the bridge UI always shows the current state. As of July 2026 the defaults are:

| Route | Direction | Status | Assets |
| --- | --- | --- | --- |
| Solana ↔ Cyberia | both | **Live** | CYBER.sol, USDC, USDT, SOL, HATCHER |
| TON ↔ Cyberia | both | **Live** | TON, KRSQ, GOAL |
| Robinhood Chain → Cyberia | inbound | **Live** | ETH, SPY |
| Cyberia → Robinhood Chain | outbound | Coming soon | ETH, SPY |
| Yenten ↔ Cyberia | both | Coming soon | YTN |
| Bitcoin ↔ Cyberia | both | Coming soon | BTC |
| Litecoin ↔ Cyberia | both | Coming soon | LTC |
| Monero ↔ Cyberia | both | Coming soon | XMR |
| BNB Chain ↔ Cyberia | both | Not open | USDT, BNB |
| Base ↔ Cyberia | both | Not open | USDC, ETH |

"Coming soon" routes are visible in the UI but greyed out and cannot be submitted yet.

## How a transfer works

**Into Cyberia (e.g. Solana → Cyberia):**

1. Open the bridge, pick the source chain, token, amount, and your Cyberia (EVM) receiving address.
2. Sign the deposit in your source-chain wallet (Phantom for Solana, Tonkeeper/TON Connect for TON, MetaMask for EVM chains). Deposits go to the bridge's published hot wallet.
3. The relayer verifies your deposit on-chain and pays out the corresponding token on Cyberia — for most assets by minting the Cyberia wrapper token to your address.
4. If your Cyberia address holds no CYBER, a small **gas drop** (0.01 CYBER) is included so you can transact immediately.

**Out of Cyberia (e.g. Cyberia → Solana):**

1. Pick Cyberia as the source, the destination chain, token, amount, and destination address.
2. Approve and send the transaction in your EVM wallet. The wrapper token is burned (or, for CYBER.sol, redeemed through the bridge contract).
3. The relayer pays out the original asset on the destination chain from its reserves.

Every completed request links both transaction hashes, so you can verify the source and destination legs on their respective explorers.

## Request statuses

| Status | Meaning |
| --- | --- |
| `awaiting_deposit` | The bridge is waiting for your source-chain deposit to appear. |
| `pending` | Deposit seen; queued for verification/processing. |
| `processing` | The relayer is verifying the deposit and sending the payout. |
| `completed` | Payout confirmed on the destination chain. Both tx hashes are shown. |
| `failed` | Something went wrong; see the [FAQ](faq.md#bridge) — the payout may still have landed. |
| `expired` | No deposit arrived within the monitoring window. A deposit that did land in time is still honored. |

## Fees

- **Stablecoins (USDC, USDT):** a flat fee of about $0.10, taken in the bridged token. (Config also allows a percentage fee; it is currently 0.)
- **Other tokens:** no bridge fee.
- **Native-coin payouts** (e.g. receiving ETH on Base/Robinhood, TON on TON, YTN on Yenten): a small amount of the bridged asset is retained to cover destination-chain network fees — for example 0.01 TON on TON payouts. The quote shown before you submit is what you'll receive.
- You always pay normal network gas on the source chain yourself.

## Converting CYBER.sol to native CYBER

CYBER.sol (the Solana token) and native CYBER (Cyberia's gas token) are different assets — see [tokens.md](tokens.md). You can convert bridged CYBER.sol into native CYBER at a **fixed 1000 : 1 rate** (1000 CYBER.sol → 1 CYBER); the converted CYBER.sol is burned (sent to `0x…dEaD`):

- **During bridging:** when bridging CYBER.sol from Solana, tick the convert option and you receive native CYBER directly instead of the wrapped token.
- **Any time later:** at <https://cyberia.church/convert>, using the wrapped CYBER.sol already in your Cyberia wallet.

The conversion is one-way.

## Personal deposit addresses

For chains without smart-contract wallets (Bitcoin, Litecoin, Monero, Yenten), the bridge uses per-user deposit addresses shown on your [profile page](account-and-profile.md) — each account gets its own address, so deposits are credited automatically. These corridors are currently in the "coming soon" state.

## Trust model

Be aware of what you are trusting:

- The bridge is **relayer-operated**: a project-controlled hot wallet verifies deposits and executes payouts. It is not a trustless light-client bridge.
- Wrapper tokens on Cyberia are minted/burned by the bridge; their backing is the relayer's reserves on the source chains.
- RPC and explorer endpoints are project-operated infrastructure.

Verify contract addresses on the [explorer](https://explorer.cyberia.church) and Solana mints on Solana explorers before treating any token as canonical. Key contracts are listed in [tokens.md](tokens.md).

## If something looks stuck

See the [FAQ](faq.md). Short version: check the request status on the bridge page first, then look up your destination address on the destination chain's explorer — payouts occasionally confirm on-chain after the UI has already flagged a timeout.
