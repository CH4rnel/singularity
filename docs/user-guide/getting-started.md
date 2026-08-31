# Getting Started

By the end of this guide, you will have a wallet connected to Cyberia, a small CYBER balance for network fees, and a transaction you can verify in the explorer.

If the terms wallet, network, gas, or transaction hash are new, read [Crypto basics](crypto-basics.md) first.

## Before you start

You need:

- a current web browser;
- a wallet you control;
- an offline place to record a new recovery phrase if you are creating a wallet;
- a small amount of a supported asset if you plan to bridge or swap.

Choose one wallet path:

- Open [Cyberia Wallet](https://cyberia.church/wallet) to use the built-in multichain wallet.
- Use an EVM wallet such as MetaMask or Rabby and add Cyberia manually below.

## 1. Create or open your wallet

For Cyberia Wallet:

1. Open <https://cyberia.church/wallet>.
2. Choose **Create wallet** for a new wallet or **Import wallet** for a recovery phrase you already control.
3. For a new wallet, record the recovery phrase and complete the backup confirmation.
4. Set the local vault password.
5. Select Cyberia from the network list.

For MetaMask, Rabby, or another EVM wallet, unlock the wallet and continue to the next section.

## 2. Add the Cyberia network to an EVM wallet

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

Completion check: the wallet's active network says **Cyberia**, and its chain ID is `49406` or `0xC0FE`.

## 3. Copy and check your address

1. Open the account or receive screen.
2. Select Cyberia.
3. Copy the full address beginning with `0x`.
4. Paste it into a note temporarily and compare its first six and last four characters with the wallet.
5. Remove the temporary note after the comparison.

The public address can be shared to receive assets. It is different from a recovery phrase or private key.

## 4. Get CYBER for gas

CYBER is the native token of the network — every transaction needs a little of it for gas. Two ways to get started:

- **Gas drop.** When you bridge any asset into Cyberia and your receiving address has no CYBER yet, the bridge automatically tops it up with a small amount of native CYBER (currently 0.01 CYBER) so you can make your first transactions. You don't need to do anything — it arrives together with your bridged tokens.
- **Convert CYBER.sol.** If you hold CYBER.sol (the Solana token), bridge it to Cyberia and redeem it for native CYBER at a fixed 1000 : 1 rate — either by ticking the convert option in the [bridge](bridge.md#converting-cybersol-to-native-cyber), or later at [cyberia.church/convert](https://cyberia.church/convert).

Once you have any CYBER, you can also buy more on the [DEX](dex.md).

## 5. Bring assets in

Use the [bridge](bridge.md) to move assets from Solana, TON, or Robinhood Chain into Cyberia. Stablecoins (USDC, USDT), SOL, TON, ETH, SPY and several community tokens are supported — see the [token list](tokens.md).

Start with a small test amount. Keep the source transaction hash and follow the bridge request until it shows the destination transaction.

## 6. See your tokens

Bridged assets on Cyberia are ERC-20 tokens. MetaMask doesn't show unknown tokens automatically — import them once via **Import tokens** using the contract addresses from the [token list](tokens.md), or check any address's balances on the [explorer](https://explorer.cyberia.church).

## 7. Verify your first transaction

1. Copy the transaction hash from the wallet or bridge request.
2. Open <https://explorer.cyberia.church>.
3. Paste the hash into search.
4. Confirm the status, sender, recipient, asset, and amount.
5. Bookmark the explorer and keep the hash until the receiving balance appears.

You are now ready to learn [why CYBER is used](cyber.md), make a [swap](dex.md), or explore the [rest of the ecosystem](ecosystem.md).

## Solana and TON wallets

The bridge talks to source-chain wallets directly:

- **Solana**: Phantom/Solflare-compatible wallets, used to sign the deposit when bridging from Solana.
- **TON**: TON Connect wallets (Tonkeeper and friends), used to sign the deposit when bridging from TON.

You can also [sign in to the site](account-and-profile.md) with an EVM or Solana wallet instead of an email account.
