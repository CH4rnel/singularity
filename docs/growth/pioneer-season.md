# Cyberia Pioneer Season — verification design

Status: **Coming soon**. The user interface does not accept or claim mission
completion. No token reward is promised.

## Existing foundation

Cyberia already has:

- authenticated EVM and Solana wallet profiles;
- an `activity_events` store and activity indexer;
- server-side achievements derived from indexed activity;
- bridge request/status records;
- indexed DEX, liquidity, lending, and profile actions;
- achievement badges exposed in the current profile UI.

This is enough to build a verifier, but the current achievement rules do not
yet prove the complete multi-day Pioneer sequence with minimum-value and
anti-sybil controls.

## Mission model

| Mission                | Evidence accepted                                                                             | Client event role                      |
| ---------------------- | --------------------------------------------------------------------------------------------- | -------------------------------------- |
| Connect wallet         | Server-authenticated wallet ownership challenge                                               | UX funnel only; never proof            |
| Bridge supported asset | Finalized source transaction plus finalized destination mint/release linked by bridge request | `bridge_completed` is diagnostic only  |
| Swap                   | Finalized Ritual pair `Swap` event involving the wallet                                       | `swap_completed` is diagnostic only    |
| Add liquidity or stake | Finalized pair mint/deposit or farm/staking deposit event                                     | `staking_completed` is diagnostic only |
| Return on another day  | A second eligible finalized action in a later UTC date bucket                                 | No client-only proof                   |

## Proposed data model

### `pioneer_seasons`

- `id`
- `slug`
- `name`
- `status` (`draft`, `coming_soon`, `active`, `ended`)
- `starts_at`, `ends_at`
- `rules_version`
- `rules_json`
- timestamps

### `pioneer_wallets`

- `id`
- `season_id`
- `chain_namespace`
- `wallet_address_normalized`
- `first_eligible_at`
- `last_eligible_at`
- `eligible_day_count`
- `risk_score`
- `status` (`observed`, `eligible`, `review`, `disqualified`)
- timestamps

Unique index: season plus chain namespace plus normalized wallet.

### `pioneer_actions`

- `id`
- `season_id`
- `pioneer_wallet_id`
- `mission`
- `chain_id`
- `transaction_hash`
- `log_index`
- `block_number`
- `block_hash`
- `block_timestamp`
- `contract_address`
- `token_address`
- `token_symbol_snapshot`
- `raw_amount`
- `normalized_amount`
- `valuation_currency`
- `valuation_amount`
- `valuation_source`
- `confirmations`
- `verification_status`
- `rules_version`
- timestamps

Unique index: chain ID plus transaction hash plus log index plus mission. This
prevents the same log from being credited twice.

### `pioneer_statuses`

- `season_id`
- `pioneer_wallet_id`
- `connected_at`
- `bridge_completed_at`
- `swap_completed_at`
- `liquidity_or_staking_completed_at`
- `return_action_completed_at`
- `points`
- `badge`
- `calculated_at`
- `calculation_version`

This table is a rebuildable projection, not the source of truth.

## Events to index

Exact event signatures must be taken from the deployed, verified ABIs before
the season is activated.

- Bridge: request/deposit plus completion/mint/release events and the existing
  bridge request linkage.
- Ritual pairs: `Swap`, `Mint`, and `Burn`.
- MasterChef/FundedFarm: deposit and withdraw events by pool ID.
- Single-token staking: deposit/stake and withdraw/unstake events.
- Lending is not part of the first mission set, but supplied/borrowed events can
  be indexed for later seasons.
- Wallet authentication: successful nonce/signature verification stored by the
  server, without storing the signature as campaign evidence.

The indexer must handle confirmation depth, removed logs, and chain
reorganizations. A completion can become final only after the per-chain
confirmation threshold.

## Minimum-value policy

Minimums must be approved before launch and stored in versioned season rules.
Do not hardcode a marketing USD claim in the UI.

Recommended rule shape:

```json
{
  "bridge": {
    "minimumsByToken": {
      "TODO_TOKEN_ADDRESS": "TODO_RAW_AMOUNT"
    }
  },
  "swap": {
    "minimumUsd": "TODO"
  },
  "liquidityOrStaking": {
    "minimumUsd": "TODO",
    "minimumHoldSeconds": "TODO"
  },
  "returnAction": {
    "minimumUtcDayGap": 1,
    "maximumWindowDays": 7
  }
}
```

For unpriced assets, use explicit raw-token thresholds approved per contract
instead of silently assigning an invented USD price.

## Sybil controls

- Credit only finalized logs from allowlisted production contracts and assets.
- Require minimum economic size and, for LP/staking, a minimum holding period.
- Ignore self-swaps, zero-liquidity dust, reverted transactions, duplicate
  logs, and test/deprecated contracts.
- Detect circular bridge/swap paths and repeated funding from the same source;
  flag them for review rather than auto-disqualifying without policy.
- Rate-limit wallet authentication and campaign status endpoints.
- Store rule and calculation versions so results can be reproduced.
- Make any manual override auditable with reason and actor.
- Keep points non-transferable and non-monetary unless a separately reviewed
  reward design is approved.

## Backend implementation plan

1. Freeze season dates, supported contracts/assets, minimums, confirmations,
   and scoring in a versioned rules object.
2. Extend the activity indexer with finalized bridge, pair, farm, and staking
   event ingestion.
3. Backfill from exact deployment blocks and reconcile counts with explorer
   queries.
4. Add an idempotent `pioneer:recalculate` projection job.
5. Expose an authenticated, read-only status endpoint that returns verified
   missions and evidence links.
6. Add admin review for flagged wallets without exposing risk heuristics.
7. Replace the Coming soon UI only after testnet replay and production
   reconciliation pass.

## Launch blockers

- Owner-approved season dates, eligible assets, minimum amounts, points, and
  badge wording.
- Exact deployed event ABIs and start blocks.
- Confirmation/reorg policy for Cyberia, Robinhood Chain, and Solana.
- Reliable token price source or raw-token thresholds.
- Documented privacy retention policy for normalized wallet addresses.
- Evidence that the activity indexer can backfill and stay caught up.
