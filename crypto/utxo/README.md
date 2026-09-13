# Bitcoin and Litecoin payouts

The half of the BTC/LTC bridge corridors that has to hold a key.

Everything else about these two chains can be read by asking a stranger:
Esplora — the index behind [mempool.space](https://mempool.space) and
[litecoinspace.org](https://litecoinspace.org) — will tell anyone what is
unspent on any address, for no key, no account and no rate-limit worth
mentioning. Laravel does that part itself (`App\Services\Bitcoin\EsploraApiService`).
Signing cannot be asked of anybody, so it happens here.

```bash
npm install
npm test          # coin selection, fees, and the version bytes
npm run typecheck
```

## What it does

```bash
npm run relay -- <bitcoin|litecoin> <recipient> <amount-satoshis> [request-id]
```

It gathers the pool's unspent outputs from Esplora, selects the largest ones
until the payout is covered, signs each input with the key that controls its
address, broadcasts, and prints JSON:

```json
{"broadcastTxHash":"<txid>"}
{"txHash":"<txid>","chain":"bitcoin","amount":"9995000","fee":"374","feeRateSatPerVb":"6","inputCount":1,"spentAddresses":["1Bg…"]}
```

The first line is printed **before** the broadcast, on purpose: the bridge
reads stdout as it is written, so a process killed between sending and
reporting still leaves a row naming the transaction it sent.

| Variable | What it is |
|---|---|
| `UTXO_RELAYER_WIFS` | JSON array of WIFs — the central wallet first, then the deposit addresses of transfers already minted. `UTXO_RELAYER_WIF` takes a single one. |
| `UTXO_CHANGE_ADDRESS` | Where change goes. Defaults to the first key's address. |
| `UTXO_ESPLORA_URL` | Index base URL. Defaults per chain. |
| `UTXO_FEE_TARGET_BLOCKS` | Confirmation target to price (default 6). |
| `UTXO_MIN_FEE_RATE` / `UTXO_MAX_FEE_RATE` | Floor and ceiling in sat/vB (default 1 and 200). |

Laravel passes all of these from `config/bridge.php`; nothing here reads a
`.env` of its own.

## The four decisions worth knowing

**The recipient receives the amount exactly.** The miner's fee comes out of the
pool, paid for upstream by the flat fee the corridor retains
(`bridge.chains.*.payout_fee`). A payout that quietly delivered less than it
promised would be a bridge that lies by an amount nobody can predict in
advance.

**A lost broadcast is not a lost payout.** The transaction is signed before
anything is sent, so its txid is known in advance. If the POST fails, times
out, or the node answers `txn-already-known`, the relay asks the index whether
*that* txid exists instead of rebuilding and sending a second transfer. This is
the one failure that could double-pay, and it is the reason the script never
retries a broadcast.

**A fee spike is a refusal, not a surprise.** The rate comes from Esplora's own
estimate for the configured target, clamped between a floor and a ceiling. Over
the ceiling the payout fails and a person looks at it — which is better than a
transfer that costs more than it moves.

**Only confirmed outputs are spent.** Unconfirmed change is skipped, so the
capacity Laravel advertises (which counts the same way) and what the relay can
actually build from never disagree.

## Why not the wallet's signer

`backend/laravel/resources/js/lib/wallet/utxo.ts` already signs P2WPKH
transactions in the browser, pinned to BIP-143 test vectors. It is not reused
here because the two do different things: that one spends one address the user
holds a key for, this one spends a pool of dozens and has to keep going when a
public index rate-limits it halfway through. bitcoinjs-lib is the same library
Yenten's relay has been using in production, and this is deliberately that
script's shape with Esplora underneath.

## Litecoin

bitcoinjs-lib ships Bitcoin's network parameters and not Litecoin's, so
`LITECOIN_NETWORK` is declared here: `pubKeyHash` 0x30, `scriptHash` 0x32,
`wif` 0xb0, bech32 `ltc`. Those four numbers are what decides whether an
address belongs to this chain or another one, so the test pins them against a
published key rather than against whatever the code happens to produce.
