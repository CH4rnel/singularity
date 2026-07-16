# Getting Started

This page gets you from zero to a working Cyberia wallet with gas.

## 1. Add the Cyberia network to your wallet

Cyberia is an EVM chain, so any EVM wallet works: MetaMask, Rabby, Trust Wallet, Coinbase Wallet, etc.

In MetaMask: **Settings → Networks → Add a network manually**, then enter:

| Field | Value |
| --- | --- |
| Network name | Cyberia |
| RPC URL | `https://rpc.cyberia.church` |
| Chain ID | `49406` |
| Currency symbol | `CYBER` |
| Block explorer URL | `https://explorer.cyberia.church` |

The apps on [cyberia.church](https://cyberia.church) can also add the network for you: when you connect a wallet on the wrong network, they prompt a one-click network switch/add (chain ID `0xC0FE` in hex).

Blocks on Cyberia are produced roughly every second, so transactions usually confirm near-instantly.

## 2. Get CYBER for gas

CYBER is the native token of the network — every transaction needs a little of it for gas. Two ways to get started:

- **Gas drop.** When you bridge any asset into Cyberia and your receiving address has no CYBER yet, the bridge automatically tops it up with a small amount of native CYBER (currently 0.01 CYBER) so you can make your first transactions. You don't need to do anything — it arrives together with your bridged tokens.
- **Convert CYBER.sol.** If you hold CYBER.sol (the Solana token), bridge it to Cyberia and redeem it for native CYBER at a fixed 1000 : 1 rate — either by ticking the convert option in the [bridge](bridge.md#converting-cybersol-to-native-cyber), or later at [cyberia.church/convert](https://cyberia.church/convert).

Once you have any CYBER, you can also buy more on the [DEX](dex.md).

## 3. Bring assets in

Use the [bridge](bridge.md) to move assets from Solana, TON, or Robinhood Chain into Cyberia. Stablecoins (USDC, USDT), SOL, TON, ETH, SPY and several community tokens are supported — see the [token list](tokens.md).

## 4. See your tokens

Bridged assets on Cyberia are ERC-20 tokens. MetaMask doesn't show unknown tokens automatically — import them once via **Import tokens** using the contract addresses from the [token list](tokens.md), or check any address's balances on the [explorer](https://explorer.cyberia.church).

## Solana and TON wallets

The bridge talks to source-chain wallets directly:

- **Solana**: Phantom/Solflare-compatible wallets, used to sign the deposit when bridging from Solana.
- **TON**: TON Connect wallets (Tonkeeper and friends), used to sign the deposit when bridging from TON.

You can also [sign in to the site](account-and-profile.md) with an EVM or Solana wallet instead of an email account.
