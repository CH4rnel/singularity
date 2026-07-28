# Cyberia partner campaign kit

Last updated: 2026-07-25.

Use this kit only for integrations that are live in the current application
configuration. Do not announce a bridge direction, market, pool, farm, lending
market, APY, reward, or network before verifying it in production.

## Campaign sequence

### 1. Partnership announcement

Goal: establish the relationship and explain the user benefit without implying
that technical integration is already live.

```text
Cyberia × {{PARTNER}}

We are working with {{PARTNER}} to make {{TOKEN}} usable across
{{CONFIRMED_NETWORKS}} through Cyberia's on-chain infrastructure.

Integration details and verified contract links will be published when the
production routes are live.

Learn more: {{ATTRIBUTED_PARTNER_PAGE_URL}}
```

CTA: the partner page, not a non-functional dapp route.

### 2. Integration live announcement

Publish only after every named action has been exercised with a real
transaction and the contracts are linked.

```text
The {{PARTNER}} integration is live on Cyberia.

You can now {{CONFIRMED_ACTION_SUMMARY}}. Transactions settle on-chain and can
be checked in the Cyberia explorer.

Start here: {{ATTRIBUTED_PARTNER_PAGE_URL}}
Contracts: {{PARTNER_PAGE_CONTRACT_SECTION_URL}}

DeFi positions and reward rates are variable and involve smart-contract,
liquidity and market risk.
```

### 3. Tutorial or demo

Use four to six concrete steps, one action per screen:

1. Open the attributed partner page.
2. Connect a compatible wallet.
3. Switch to the network shown by the action card.
4. Open the action and review token addresses before signing.
5. Submit the transaction and wait for confirmation.
6. Verify the transaction in the network explorer.

```text
How to use {{TOKEN}} on Cyberia

1. Open {{ATTRIBUTED_PARTNER_PAGE_URL}}
2. Connect your wallet and select {{NETWORK}}
3. Choose {{ACTION}}
4. Review the token and contract address
5. Confirm on-chain, then verify the transaction in {{EXPLORER}}

Tutorial: {{ATTRIBUTED_TUTORIAL_URL}}
```

Never crop out token addresses, network labels, risk copy, or the final explorer
confirmation in screenshots or video.

### 4. Results post

Publish only measured, reproducible on-chain data. State the time range,
networks, contracts, query method, and whether values are USD estimates.

```text
{{PARTNER}} × Cyberia — {{REPORTING_PERIOD}} on-chain update

• Unique transacting wallets: {{VERIFIED_VALUE}}
• Confirmed transactions: {{VERIFIED_VALUE}}
• Returning wallets within 7 days: {{VERIFIED_VALUE}}
• {{ACTION_METRIC}}: {{VERIFIED_VALUE}}

Scope: {{NETWORKS_AND_CONTRACTS}}
Methodology: {{PUBLIC_METHODOLOGY_URL}}
Explorer/query: {{PUBLIC_EVIDENCE_URL}}

Values are measured from confirmed on-chain activity. USD estimates, if shown,
use {{PRICE_SOURCE}} at {{PRICE_TIMESTAMP}}.
```

Do not publish placeholders, unverified dashboards, or client-reported
completion events as on-chain results.

## UTM requirements

Required campaign URLs:

```text
https://cyberia.church/partners/{{slug}}?utm_source={{partner_channel}}&utm_medium={{placement}}&utm_campaign={{campaign_name}}
```

Conventions:

- `utm_source`: stable publisher identifier, for example `ash`, `hatcher`,
  `orbserv`, `cyberia_x`, or `partner_discord`.
- `utm_medium`: placement type, for example `social`, `community`,
  `newsletter`, `docs`, or `video`.
- `utm_campaign`: lowercase campaign identifier, for example
  `cyberia_partner_launch_2026q3`.
- Use ASCII lowercase and underscores; do not place wallet addresses, user IDs,
  emails, signatures, referral secrets, or free-form personal data in UTMs.
- Every partner should use its own source value. Do not reuse a generic link
  across unrelated posts.
- Preserve the partner page as the first landing page so the site's session
  attribution can carry UTMs into the dapp.
- Short links may redirect to the full UTM URL, but the destination and
  analytics parameters must remain inspectable.

The client records only `source`, `medium`, `campaign`, `partner`, `network`,
`token`, and `action_type`.

## Recommended pinning window

Keep the integration-live post pinned for at least 7 full days. Prefer 14 days
when the partner has no higher-priority launch conflict. Keep the tutorial
linked in the partner's docs or link hub for at least 30 days.

The results post should follow only after a complete observation window,
normally 7–14 days, and after on-chain reconciliation.

## Assets Cyberia provides to a partner

- Partner landing URL and approved UTM examples.
- Confirmed actions and supported networks.
- Token contract addresses, standards, and direct explorer links.
- A 1:1 logo, social card, and one mobile and one desktop screenshot.
- A short factual description and approved risk copy.
- A 30–60 second transaction demo ending on explorer confirmation.
- Wallet/network switching instructions.
- Support escalation contact and incident pause procedure.
- Public metric methodology and reporting window before any results post.
- Accessibility-ready alt text and captions.

## ASH campaign copy

Confirmed scope in the current repository:

- ASH token on Cyberia and bridged ASH on Robinhood Chain.
- Trading and liquidity interfaces for both networks.
- ASH single-token staking and lending market on Cyberia.
- Eligible ASH LP farming on Cyberia and a funded ETH/ASH LP farm on Robinhood
  Chain.
- No public ASH bridge action is exposed by the current bridge configuration,
  so campaign copy must not say users can bridge ASH.

### Integration live

```text
ASH is live across Ritual on Cyberia and Robinhood Chain.

Trade ASH, add liquidity, and stake eligible LP tokens through Cyberia's
on-chain interfaces. ASH single-token staking and the ASH lending market are
also available on Cyberia.

Start: https://cyberia.church/partners/ash?utm_source=ash&utm_medium=social&utm_campaign=cyberia_partner_launch_2026q3

Reward rates are variable. Review token addresses, pool liquidity and
smart-contract risk before transacting.
```

### Tutorial

```text
How to trade or provide ASH liquidity

1. Open the ASH page and connect your EVM wallet.
2. Select Cyberia or Robinhood Chain in the destination action.
3. Verify the ASH contract shown on the page.
4. Open Trade ASH or Add ASH liquidity.
5. Confirm the transaction and verify it in the selected chain explorer.

Guide: https://cyberia.church/partners/ash?utm_source=ash&utm_medium=tutorial&utm_campaign=cyberia_partner_launch_2026q3
```

## HATCHER campaign copy

Confirmed scope in the current repository:

- HATCHER Token-2022 mint on Solana and bridged ERC-20 on Cyberia.
- Solana ↔ Cyberia HATCHER bridge route.
- Ritual trading and liquidity, HATCHER single-token staking, a
  CYBER/HATCHER LP farm, and lending/borrowing on Cyberia.

### Integration live

```text
HATCHER is live across Solana and Cyberia.

Bridge HATCHER between Solana and Cyberia, then trade it on Ritual, add
liquidity, stake HATCHER or an eligible CYBER/HATCHER LP token, or open the
Cyberia lending market.

Start: https://cyberia.church/partners/hatcher?utm_source=hatcher&utm_medium=social&utm_campaign=cyberia_partner_launch_2026q3

All actions settle on-chain. Reward and borrowing rates are variable and DeFi
positions involve smart-contract, liquidity and liquidation risk.
```

### Tutorial

```text
How to move and use HATCHER on Cyberia

1. Open the HATCHER partner page.
2. Connect the wallet required by the selected bridge direction.
3. Bridge HATCHER between Solana and Cyberia.
4. Wait for completion and verify both network transactions.
5. Open Ritual to trade, add liquidity, stake, farm or use the lending market.

Guide: https://cyberia.church/partners/hatcher?utm_source=hatcher&utm_medium=tutorial&utm_campaign=cyberia_partner_launch_2026q3
```

## Orbserv / ORBV campaign copy

Confirmed scope in the current repository:

- ORBV Token-2022 mint on Solana and bridged ERC-20 on Cyberia.
- Solana ↔ Cyberia ORBV bridge route.
- Ritual trading and liquidity, ORBV single-token staking, an ORBV/CYBER LP
  farm, and lending/borrowing on Cyberia.

### Integration live

```text
ORBV is live across Solana and Cyberia.

Bridge ORBV between Solana and Cyberia, trade it on Ritual, add liquidity,
stake ORBV or an eligible ORBV/CYBER LP token, or open the Cyberia lending
market.

Start: https://cyberia.church/partners/orbserv?utm_source=orbserv&utm_medium=social&utm_campaign=cyberia_partner_launch_2026q3

All actions settle on-chain. Reward and borrowing rates are variable and DeFi
positions involve smart-contract, liquidity and liquidation risk.
```

### Tutorial

```text
How to move and use ORBV on Cyberia

1. Open the Orbserv partner page.
2. Connect the wallet required by the selected bridge direction.
3. Bridge ORBV between Solana and Cyberia.
4. Wait for completion and verify both network transactions.
5. Open Ritual to trade, add liquidity, stake, farm or use the lending market.

Guide: https://cyberia.church/partners/orbserv?utm_source=orbserv&utm_medium=tutorial&utm_campaign=cyberia_partner_launch_2026q3
```

## Launch gate

Before any partner post goes live:

- [ ] Execute every announced route with a small production transaction.
- [ ] Recheck contract addresses against runtime configuration.
- [ ] Confirm the partner name, logo, link, and final wording.
- [ ] Confirm support ownership and incident pause copy.
- [ ] Verify desktop and mobile UTM URLs.
- [ ] Verify `partner_cta_clicked` and downstream funnel events without
      collecting wallet addresses.
- [ ] Capture explorer evidence and archive the measurement query.
