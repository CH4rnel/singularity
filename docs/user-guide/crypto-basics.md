# Crypto Basics

This page explains the few ideas you need before using Cyberia. You do not need previous blockchain experience.

## The five things to know first

### 1. A wallet is your signing tool

A crypto wallet stores the keys that let you approve actions for a blockchain address. The address is public and can be shared to receive assets. The recovery phrase and private keys control the wallet and stay private.

Cyberia works with familiar EVM wallets such as MetaMask and Rabby, and it also has a [multichain wallet](wallet.md) at <https://cyberia.church/wallet>.

### 2. A network is the blockchain you are using

The same wallet can connect to several networks. Cyberia has its own network identity:

| Field | Value |
| --- | --- |
| Network | Cyberia |
| Chain ID | `49406` |
| Native coin | CYBER |
| Explorer | <https://explorer.cyberia.church> |

Always check the selected network before signing. A token on Solana, Base, or Cyberia may have the same symbol while being a different on-chain asset.

### 3. Gas is the fee for an on-chain action

Sending a token, swapping, approving a token, voting, or interacting with a contract creates a transaction. On Cyberia, transaction fees are paid with native CYBER.

An ERC-20 token such as USDC cannot pay Cyberia gas by itself. Keep a small CYBER balance available for transactions.

### 4. A signature is your approval

Wallets ask for two common kinds of signatures:

- A **message signature** proves that you control an address. It normally has no network fee.
- A **transaction signature** authorizes an on-chain action and shows a network fee.

Read the wallet prompt, confirm the network and destination, and sign only the action you intended to start.

### 5. The explorer is your receipt book

After a transaction is sent, the wallet shows a transaction hash beginning with `0x`. Paste it into the [Cyberia explorer](https://explorer.cyberia.church) to see its status, sender, recipient, amount, fee, and contract activity.

## Words you will see in the apps

| Term | Plain-language meaning |
| --- | --- |
| Address | The public destination for an account, such as `0x…` on an EVM network |
| Balance | The amount held by an address on one network |
| Token | An asset managed by a smart contract |
| Native coin | The network's built-in asset; CYBER on Cyberia |
| Contract address | The unique identifier of a token or application contract |
| Approval | Permission for a contract to use a chosen ERC-20 token balance |
| Swap | An on-chain trade through a liquidity pool |
| Bridge | A transfer workflow between two blockchain networks |
| Slippage | The allowed difference between a quoted swap and its execution |
| Liquidity | Assets supplied to a pool so users can trade |
| Confirmation | Evidence that a transaction was included in the chain |

## Your first safe practice transfer

1. Open your wallet and copy the full Cyberia address.
2. Compare its first six and last four characters with the address shown in the sending app.
3. Send a small amount first.
4. Copy the transaction hash after the wallet broadcasts it.
5. Open the hash in the explorer and wait for a successful status.
6. Confirm the new balance before sending the remaining amount.

## Recovery phrase checklist

1. Write the recovery phrase offline in the exact order shown.
2. Store it separately from the device that holds the wallet.
3. Never enter it into a support chat, bridge form, social message, or block explorer.
4. Use the phrase only when creating or restoring the wallet you chose.
5. Complete a wallet's backup confirmation before receiving assets.

You are ready for [Getting started](getting-started.md) when you can identify your public address, the selected network, the fee currency, and the transaction hash.
