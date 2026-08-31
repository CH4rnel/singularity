# Cyberia Wallet

The [Cyberia Wallet](https://cyberia.church/wallet) is a non-custodial multichain wallet that runs in your browser and is also the home screen of the desktop and mobile apps. One BIP-39 recovery phrase derives accounts for every supported network.

The recovery phrase and private keys are not sent to Cyberia's server. The vault is encrypted on the device with AES-256-GCM using a key derived from your password, then stored in browser storage.

::: danger Your recovery phrase is the wallet
Write the phrase down offline and verify the backup before funding the wallet. Cyberia cannot reset the password, recover the phrase, reverse a transfer, or restore a vault after browser storage is cleared.
:::

## Create or restore a wallet

1. Open <https://cyberia.church/wallet> and choose **Create wallet** or **Import wallet**.
2. For a new wallet, record the recovery phrase offline in the order shown.
3. Complete the backup confirmation, or validate the phrase entered during import.
4. Set a strong vault password. This password protects the encrypted copy on this device; it is not an account with a server-side reset.
5. Unlock the vault and select the network you want to use.

You can derive additional numbered accounts from the same phrase. Imported phrases and private keys are separate accounts and need their own backups; they are not covered by the primary recovery phrase.

Completion check: lock and unlock the vault once, then compare the first and last characters of its receive address with the address you recorded.

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

## More networks

The wallet also ships knowing over 120 further EVM networks — Ethereum, Arbitrum, Optimism, Polygon, Avalanche, zkSync, Linea, Scroll, Mantle, Blast, Gnosis, Celo and so on. They start switched **off**. Open **Networks** from the portfolio to search the list by name, ticker or chain ID and switch on the ones you use.

Switching a network on does not create an account. Your recovery phrase already derives the same `0x` address on all of them; what changes is that the portfolio draws a card for that network and reads its balance on every refresh. That is the cost of switching one on, and it is why the list does not start fully enabled.

Each row states what that network can do here before you switch it on:

- **balances · tokens · history** — the network has a public keyless index, so token balances and transfers appear in the wallet.
- **balances only** — no such index exists for that network. The wallet reads the balance and can send; tokens must be added by contract address, and history is read in that network's own explorer.

Every endpoint in this list was checked against the live network before shipping: the RPC has to answer a browser and report its own chain ID. Endpoints can still go down or change hands — treat an unreachable network as an endpoint problem, not a lost account, and test with a small amount after switching one on.

## Receive

1. Unlock the wallet and choose the destination network.
2. Open **Receive**.
3. Copy the full address or show its QR code.
4. Confirm that the sender selected the same network and asset.
5. Start with a small test transfer.
6. Open the transaction hash in that network's explorer and wait for confirmation.

## Send

1. Select the network or token.
2. Paste and verify the recipient address.
3. Enter the amount and choose a fee tier when offered.
4. Read the final signing sentence, then hold the confirmation control to sign and broadcast.
5. Keep the transaction hash until the transfer is confirmed.
6. Open the hash from the wallet history and compare the destination and amount.

ERC-20 tokens share the EVM network's address and require that network's native coin for gas. For example, holding USDC on Cyberia does not pay a fee denominated in CYBER.

## Tokens and portfolio analytics

On supported EVM networks, the wallet reads token balances from the chain's index and can also track a contract you add manually. Token decimals come from the contract or index; the wallet does not assume 18 decimals.

Portfolio analytics are computed in the browser from balances, quotes, and histories already loaded by the page. Holdings that cannot be priced are named as excluded instead of being treated as zero. The wallet does not invent a historical value curve from a current snapshot.

## Add a custom network

The wallet can add:

- an EVM network with a chain ID and HTTPS RPC endpoint;
- a Bitcoin-family network with its coin type, address format, prefixes, and HTTPS Esplora API.

Custom networks are marked as unverified. Their endpoints can observe requests and return misleading chain data, so use infrastructure you trust and verify the chain ID independently. Removing a custom network forgets its configuration; it does not remove the derived account or move funds.

## Cross-chain swap

The **swap** screen trades on Cyberia's own liquidity. A **cross-chain swap** is the other case — spending an asset on one network to receive a different asset on another, for example USDC on Base for SOL on Solana. Cyberia holds no liquidity on other chains, so the wallet asks an external routing service that does.

What happens when you confirm:

1. You sign one deposit transaction on the network you are spending from. Depending on the token, an allowance transaction may go first.
2. The deposit goes to the routing service's contract on that network — not to Cyberia.
3. The routing service delivers the destination asset to the recipient address.

Before you sign, the screen shows what you will receive, the minimum guaranteed amount, the routing service's fee, Cyberia's fee, and the estimated delivery time. **There is no cancel between the deposit and the delivery.**

Fees: Cyberia takes a percentage of the amount you send, deducted from the input on the source chain. The figure on the review screen is the one contained in the quote from the routing service, which is what will actually be charged. If the routing service does not apply Cyberia's fee to a particular route, the screen says so and you are not charged it.

Limits, and the reasons for them:

- The **source** must be an EVM network you have switched on in this wallet — that is where the wallet gets both an endpoint to broadcast through and a balance you have already seen.
- The **destination** must be a network whose addresses this wallet can validate: any EVM network, Solana, or Bitcoin. Other networks the routing service serves are listed with that as the reason. A cross-chain swap cannot be recalled, so the wallet will not send to an address format it cannot check.
- The recipient defaults to your own address on the destination network. You can change it; the screen says plainly when the recipient is not you.

After signing, the screen keeps the transaction hashes and polls the routing service for the outcome. Closing the screen does not stop the route.

## Security checklist

- Keep the phrase offline and never paste it into support chats, bridge forms, websites, or issue reports.
- Bookmark the wallet URL and check the origin before unlocking.
- Test a new network or address with a small amount first.
- Verify token contracts against the [token reference](tokens.md) and an explorer.
- Lock the vault when you leave the device; use full-disk encryption for the device itself.
- Treat imported private keys as separate backup material.
- Clear the vault only after confirming that every seed phrase and imported key is recoverable.

## What the server can see

Normal RPC providers can see the public addresses they are asked about, just as any blockchain explorer can. Cyberia's Laravel app supplies public configuration, price quotes, and a Solana RPC relay, but signing stays in the browser. Cross-chain quotes are requested through Cyberia's server, which sees the addresses and amounts in a quote and adds Cyberia's fee to the request; it holds no key and signs nothing. Features that explicitly require an address or proof—such as account linking or holders-only chat—ask before sending that public address to the server.
