# Bridge

The Cyberia bridge moves assets between external chains and Cyberia: <https://cyberia.church/bridge>.

## Supported routes

Availability is read from the running bridge. Open the source and destination selectors to see the routes accepting new requests now; the page also shows when a prepared corridor is not yet accepting submissions.

Cyberia's bridge configuration includes connectors for Solana, TON, Robinhood Chain, BNB Chain, Base, Yenten, Bitcoin, Litecoin, and Monero. Assets configured across those connectors include CYBER.sol, USDC, USDT, SOL, TON, ETH, SPY, HATCHER, ORBV, KRSQ, GOAL, YTN, BTC, LTC, XMR, CYBER, and BNB. The selectable combination in the live bridge is the source of truth for a new transfer.

To check a route:

1. Open <https://cyberia.church/bridge>.
2. Choose the source network.
3. Choose the destination network.
4. Open the asset selector.
5. Continue only when the page offers a quote for that exact direction and asset.

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

For chains without smart-contract wallet connection, the bridge can use a per-user or per-request deposit address. When such a corridor is enabled, the bridge or your [profile page](account-and-profile.md) displays the address and the request explains how the deposit is attributed.

## How settlement is verified

The bridge uses a relayer to verify the confirmed source transaction and execute the matching destination payout. Cyberia wrapper tokens are issued and redeemed through the bridge contracts, while destination availability is checked before the transfer begins.

Each completed request records the source and destination transaction hashes. Open both links to follow the asset from its origin transaction to the receiving address. Canonical token and contract identifiers are listed in [Tokens and contracts](tokens.md).

## If something looks stuck

See the [FAQ](faq.md). Short version: check the request status on the bridge page first, then look up your destination address on the destination chain's explorer. The explorer is the final record of the destination transaction.
