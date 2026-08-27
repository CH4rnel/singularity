# Cyberia Wallet

The [Cyberia Wallet](https://cyberia.church/wallet) is a non-custodial multichain wallet that runs in your browser and is also the home screen of the desktop and mobile apps. One BIP-39 recovery phrase derives accounts for every supported network.

The recovery phrase and private keys are not sent to Cyberia's server. The vault is encrypted on the device with AES-256-GCM using a key derived from your password, then stored in browser storage.

::: danger Your recovery phrase is the wallet
Write the phrase down offline and verify the backup before funding the wallet. Cyberia cannot reset the password, recover the phrase, reverse a transfer, or restore a vault after browser storage is cleared.
:::

## Create or restore a wallet

1. Open <https://cyberia.church/wallet> and choose to create a new wallet or restore an existing BIP-39 phrase.
2. Set a strong vault password. This password protects the encrypted copy on this device; it is not an account with a server-side reset.
3. Record the recovery phrase offline in the order shown.
4. Confirm the backup before receiving funds.

You can derive additional numbered accounts from the same phrase. Imported phrases and private keys are separate accounts and need their own backups; they are not covered by the primary recovery phrase.

## Built-in networks

| Network | Asset | What works in the wallet |
| --- | --- | --- |
| Cyberia | CYBER | Balance, history, token discovery, receive, and send |
| Robinhood Chain | ETH | Balance, history, token discovery, receive, and send |
| BNB Chain | BNB | Balance, receive, and send; in-app history depends on an available indexer |
| Base | ETH | Balance, receive, and send; in-app history depends on an available indexer |
| Solana | SOL | Balance, history, receive, and send through Cyberia's read/write RPC relay |
| Bitcoin | BTC | Native SegWit balance, history, receive, and send through the configured Esplora service |
| Litecoin | LTC | Native SegWit balance, history, receive, and send through the configured Esplora service |
| Monero | XMR | Address derivation and receive only; balance scanning and spending require a Monero wallet restored from the same phrase |

Every built-in EVM network uses the same BIP-44 account and therefore displays the same `0x` address. Solana, Monero, Bitcoin, and Litecoin use their own derivation paths and address formats.

## Receive and send

To receive, choose a network, copy its full address, and confirm that the sender selected the same network. An address that looks valid on another chain does not make the transfer compatible.

To send:

1. Select the network or token.
2. Paste and verify the recipient address.
3. Enter the amount and choose a fee tier when offered.
4. Read the final signing sentence, then hold the confirmation control to sign and broadcast.
5. Keep the transaction hash until the transfer is confirmed.

ERC-20 tokens share the EVM network's address and require that network's native coin for gas. For example, holding USDC on Cyberia does not pay a fee denominated in CYBER.

## Tokens and portfolio analytics

On supported EVM networks, the wallet reads token balances from the chain's index and can also track a contract you add manually. Token decimals come from the contract or index; the wallet does not assume 18 decimals.

Portfolio analytics are computed in the browser from balances, quotes, and histories already loaded by the page. Holdings that cannot be priced are named as excluded instead of being treated as zero. The wallet does not invent a historical value curve from a current snapshot.

## Add a custom network

The wallet can add:

- an EVM network with a chain ID and HTTPS RPC endpoint;
- a Bitcoin-family network with its coin type, address format, prefixes, and HTTPS Esplora API.

Custom networks are marked as unverified. Their endpoints can observe requests and return misleading chain data, so use infrastructure you trust and verify the chain ID independently. Removing a custom network forgets its configuration; it does not remove the derived account or move funds.

## Security checklist

- Keep the phrase offline and never paste it into support chats, bridge forms, websites, or issue reports.
- Bookmark the wallet URL and check the origin before unlocking.
- Test a new network or address with a small amount first.
- Verify token contracts against the [token reference](tokens.md) and an explorer.
- Lock the vault when you leave the device; use full-disk encryption for the device itself.
- Treat imported private keys as separate backup material.
- Clear the vault only after confirming that every seed phrase and imported key is recoverable.

## What the server can see

Normal RPC providers can see the public addresses they are asked about, just as any blockchain explorer can. Cyberia's Laravel app supplies public configuration, price quotes, and a Solana RPC relay, but signing stays in the browser. Features that explicitly require an address or proof—such as account linking or holders-only chat—ask before sending that public address to the server.
