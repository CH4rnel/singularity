# CYBER.sol 420 SOL plan — source and calculation notes

Snapshot: 2026-08-20 08:10 UTC. Audience: product stakeholders. Delivery: portable HTML report.

## Decision frame

Build an executable, two-person development and marketing plan that gives CYBER.sol a credible chance to move from roughly 278 SOL market cap to a durable position above the 420 SOL PumpSwap creator-fee boundary by September 20, 2026. This is a growth plan, not a promise of price performance or a plan to manufacture trades.

## Evidence inventory

- Pump fee documentation, last updated May 20, 2026: canonical SOL pools pay a 0.300% creator fee below 420 SOL market cap and 0.950% from 420 to 1,470 SOL. Pump defines market cap as current token price in SOL multiplied by one billion tokens.
- Pump coin API snapshot at 2026-08-20 08:10 UTC: 278.070656 SOL market cap, $24,217.27 USD market cap, graduated coin, one billion-token basis.
- DexScreener token API snapshot at the same time: PumpSwap canonical pair `Dk19ZrUAJLW2e6Ra1qkaZk1pwb48y5cwQu6xfcFXYtEg`, 76.8043 SOL and 276,204,357 CYBER.sol in the pool, $12,926.73 liquidity, $220.93 24-hour volume, six 24-hour transactions, and 187 holders on the public pair page.
- Public community surfaces: 229 Telegram members in the latest crawl; 575 X followers in a third-party search snapshot; 10 GitHub stars, four forks, 29 open issues, and 412 commits on the public repository page/current checkout.
- Repository evidence: live or implemented L1, bridge, DEX, wallet, Telegram Mini App, analytics, contributor progression, and a Pioneer Season preview. The preview explicitly says that campaign verification, value minimums, distinct-day logic, and abuse controls are not yet deployed.
- Public Cyberia analytics snapshot: live market/indexer page, working public activity feed, and existing funnel event instrumentation. Private CRM funnel values were not accessible in this analysis and must be exported on day one.

## Calculation spot-checks

Pump's definition gives the threshold token price:

```text
420 SOL / 1,000,000,000 tokens = 0.000000420 SOL per token
```

Current distance:

```text
420 / 278.070656 - 1 = 51.04% price increase
```

The USD equivalent moves with SOL. The Pump snapshot implied about $87.09 per SOL, making 420 SOL about $36,578 at that moment. This is not a fixed dollar target.

Directional constant-product estimate of gross buy input, using the DexScreener quote reserve and Pump's current 1.25% total fee:

```text
gross SOL input ≈ reserveSOL × (sqrt(target/current) - 1) / (1 - total fee)
```

This produces approximately 17.8 SOL to print 420 SOL, 21.2 SOL to print 450 SOL, and 26.5 SOL to print 500 SOL if nothing else trades and the displayed reserves are exact. It is not an execution quote. Routing, fee handling, arbitrage, reserve changes, sells, and price movement make the real number different. It demonstrates only why a 141.9 SOL increase in displayed market cap is not the same thing as 141.9 SOL—or 420 SOL—of capital entering the pool.

Fee economics at the observed 24-hour volume:

```text
24-hour volume ≈ $220.93 / $87.09 = 2.54 SOL
creator fee below boundary ≈ 2.54 × 0.0030 = 0.0076 SOL/day
creator fee above boundary ≈ 2.54 × 0.0095 = 0.0241 SOL/day
incremental fee ≈ 0.0165 SOL/day
```

At unchanged volume, spending 18 SOL solely to reach the fee tier would require roughly 2,769 SOL of above-tier trading volume to earn back that 18 SOL from the incremental 0.65 percentage points. The fee tier has value only when accompanied by materially greater organic volume.

## KPI target logic

- The primary outcome is not a single tick above 420 SOL. The operating target is a 500 SOL daily close with a seven-day median of at least 450 SOL and no observed daily close below 420 SOL during the final four days.
- Holder and trader targets are provisional because a complete historical holder/trader export was unavailable. They are intended as operating thresholds for a low-base campaign and must be recalibrated after the August 20–22 baseline pull.
- A "qualified holder" means a wallet that voluntarily proves control, has a non-dust CYBER.sol balance, and completes at least one real product action. The exact non-dust minimum should be selected after the holder distribution is reviewed; it must not be optimized to force purchases.
- Volume targets exclude project-controlled wallets, market makers paid by the project, circular routes, self-trades, and known arbitrage-only traffic.

## Visualization contract

- Analytical question: how far is the current SOL-denominated market cap from the fee boundary and a usable safety buffer?
- Takeaway: the gap is a 51% price increase; a 500 SOL operating target provides a more useful buffer than a one-tick crossing.
- Family/type: comparison, vertical bar.
- Dataset: four reviewed rows—current, fee boundary, minimum buffer, operating target—with market cap, implied price, gap, role, and source timestamp.
- Palette: single-root sequential blue; no redundant category legend. The 420 SOL boundary is one of the plotted categories.
- Footprint: full-width report block; semantic table fallback retained by the portable builder.

## Report structure map

1. Title.
2. Executive Summary.
3. Fee-boundary finding and visual evidence.
4. Distribution bottleneck and existing asset base.
5. Single campaign/funnel recommendation.
6. Development priorities.
7. Marketing motion and creator-fee-funded spending gates.
8. Dated execution plan and two-person cadence.
9. KPI scoreboard and stop rules.
10. Further questions.
11. Caveats and assumptions.

No time-series chart is included because only a point-in-time market snapshot and a short 24-hour activity window were available. A line chart from those data would imply a trend that was not established. Exact lookup tables are used for the dated plan, fee-funded spending gates, and KPI definitions.

## Validation assessment

Overall: **share with caveats**.

- Verified: Pump fee boundary and formula; current Pump and Dex market snapshots; constant-product arithmetic; public repository and community counts; existence of product and campaign building blocks.
- Directional, not verified execution advice: SOL input estimates to move the pool price.
- User-confirmed constraint: no external marketing budget; realized creator fees may be reinvested.
- Missing: owner-confirmed creator-fee recipient/control, current claimable creator-fee balance, realized fees for the last seven/30 days, top-holder concentration, complete seven-/30-day buyer/seller history, authenticated CRM funnel, and the second team member's skill profile.
- Fee runway scenarios use `daily volume × 0.003 × days` and assume the token stays below 420 SOL for the whole period. They are planning sensitivities, not forecasts; the fee wallet history should replace them once available.
- Required caveat: no marketing plan can guarantee market capitalization. The plan must exclude wash trading, fake holders, circular volume, undisclosed paid promotion, guaranteed-return language, or coordinated price manipulation.
