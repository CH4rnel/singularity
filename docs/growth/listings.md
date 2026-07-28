# Cyberia external listings readiness

Last repository audit: 2026-07-25.

This document prepares data for external listings. It does **not** authorize
submitting forms, opening pull requests, or publishing adapters.

## Canonical project data

| Field                 | Value                                                             | Repository source                               |
| --------------------- | ----------------------------------------------------------------- | ----------------------------------------------- |
| Chain name            | Cyberia                                                           | `backend/laravel/resources/js/lib/evmChains.ts` |
| Chain ID / network ID | `49406`                                                           | `backend/laravel/resources/js/lib/evmChains.ts` |
| Namespace             | `eip155:49406`                                                    | Derived from the EVM chain ID                   |
| Native currency       | Cyber (`CYBER`), 18 decimals                                      | `backend/laravel/resources/js/lib/evmChains.ts` |
| Public RPC            | `https://rpc.cyberia.church`                                      | `backend/laravel/resources/js/lib/evmChains.ts` |
| Explorer              | `https://explorer.cyberia.church`                                 | `backend/laravel/resources/js/lib/evmChains.ts` |
| Website               | `https://cyberia.church`                                          | root `AGENTS.md`                                |
| DEX                   | `https://swap.cyberia.church`                                     | root `AGENTS.md`                                |
| Bridge                | `https://bridge.cyberia.church`                                   | root `AGENTS.md`                                |
| Source                | `https://github.com/cyberia-temple/singularity`                   | landing page                                    |
| X                     | `https://x.com/cyberia_temple`                                    | landing page                                    |
| X community           | `https://x.com/i/chat/group_join/g2052379607618179294/V3aEe965FJ` | landing page                                    |
| Discord               | `https://discord.gg/J7H5VNhnW`                                    | landing page                                    |
| Telegram              | `https://t.me/cyberia_network`                                    | landing page                                    |

### Logo candidates

These are repository paths, not yet permanent public asset URLs:

- `backend/laravel/public/favicon.svg`
- `backend/laravel/public/cyberia_logo.png`
- `backend/laravel/public/token-icons/cyberia.png`
- `frontend/ritual/public/cyberia.png`

Before submission, create a versioned public asset location and verify each
listing's current size, background, file-size, and format requirements.

## On-chain contract inventory

The addresses below are sourced from runtime configuration and deployment
manifests. The core Cyberia and Robinhood Chain addresses were also read from
their public RPCs during the 2026-07-25 audit. This confirms deployed bytecode,
not explorer source-code verification.

TODO before presenting these as verified contracts: confirm the source-code
verification status on each explorer and publish matching sources where it is
absent.

### Cyberia — chain ID 49406

| Component            | Address                                      | Explorer                                                                                       |
| -------------------- | -------------------------------------------- | ---------------------------------------------------------------------------------------------- |
| WCYBER               | `0x78272aAd03E4b9d7A9134e874BA6d419B534F6c9` | [contract](https://explorer.cyberia.church/address/0x78272aAd03E4b9d7A9134e874BA6d419B534F6c9) |
| Ritual V2 factory    | `0xB0aC30907c04b61F1482e62eA66eF4562a690917` | [contract](https://explorer.cyberia.church/address/0xB0aC30907c04b61F1482e62eA66eF4562a690917) |
| Ritual V2 router     | `0x8bECfB12Ab113586D8deD3D343aEfFd8eD54FD62` | [contract](https://explorer.cyberia.church/address/0x8bECfB12Ab113586D8deD3D343aEfFd8eD54FD62) |
| ASH MasterChef       | `0xd540DEa828567160FFDe5e792ca359aDD1f6B03D` | [contract](https://explorer.cyberia.church/address/0xd540DEa828567160FFDe5e792ca359aDD1f6B03D) |
| EVM bridge           | `0xEf2c8E731006EEDD8F44f5Ea03A389635BB28f90` | [contract](https://explorer.cyberia.church/address/0xEf2c8E731006EEDD8F44f5Ea03A389635BB28f90) |
| Lending comptroller  | `0xe66aa9842dc74F1c10ede19cA20Ece6E08F1CC88` | [contract](https://explorer.cyberia.church/address/0xe66aa9842dc74F1c10ede19cA20Ece6E08F1CC88) |
| Lending price oracle | `0x8fEA279fb70D3D1B20a0E50cbC649c83C41Dc4D1` | [contract](https://explorer.cyberia.church/address/0x8fEA279fb70D3D1B20a0E50cbC649c83C41Dc4D1) |
| ASH                  | `0x992Fca0a89DD95afb17751f6CC233Adb9B089df5` | [token](https://explorer.cyberia.church/token/0x992Fca0a89DD95afb17751f6CC233Adb9B089df5)      |
| HATCHER              | `0x621021F18b6404123f98b1395c418868418ACF36` | [token](https://explorer.cyberia.church/token/0x621021F18b6404123f98b1395c418868418ACF36)      |
| ORBV                 | `0x19E92D8475522FF6c8f3660372B9dc6674d85cC8` | [token](https://explorer.cyberia.church/token/0x19E92D8475522FF6c8f3660372B9dc6674d85cC8)      |

Additional token, market, and pool addresses live in:

- `backend/laravel/resources/js/lib/cyberiaTokens.ts`
- `crypto/hardhat/deployments/cyberia-lending.json`
- `crypto/hardhat/deployments/cyberia-ash-emission.json`

### Robinhood Chain — chain ID 4663

| Component         | Address                                      | Explorer                                                                                             |
| ----------------- | -------------------------------------------- | ---------------------------------------------------------------------------------------------------- |
| aeWETH            | `0x0Bd7D308f8E1639FAb988df18A8011f41EAcAD73` | [contract](https://robinhoodchain.blockscout.com/address/0x0Bd7D308f8E1639FAb988df18A8011f41EAcAD73) |
| Ritual V2 factory | `0xD199e6ae74B992F017f8940B26Fa18A7dD30eE86` | [contract](https://robinhoodchain.blockscout.com/address/0xD199e6ae74B992F017f8940B26Fa18A7dD30eE86) |
| Ritual V2 router  | `0xB0aC30907c04b61F1482e62eA66eF4562a690917` | [contract](https://robinhoodchain.blockscout.com/address/0xB0aC30907c04b61F1482e62eA66eF4562a690917) |
| FundedFarm        | `0x4798F67D8D741DC09ae4409Da2D180524E72A99C` | [contract](https://robinhoodchain.blockscout.com/address/0x4798F67D8D741DC09ae4409Da2D180524E72A99C) |
| CYBER             | `0x753979e6585CCa139fbB1918966D563a25eEB3B2` | [token](https://robinhoodchain.blockscout.com/token/0x753979e6585CCa139fbB1918966D563a25eEB3B2)      |
| Bridged ASH       | `0xa284bF7D1d941ED8dEd25f8E592003E9e5373284` | [token](https://robinhoodchain.blockscout.com/token/0xa284bF7D1d941ED8dEd25f8E592003E9e5373284)      |
| ETH/CYBER pair    | `0x4E93763183A3eC492f01C06AE28805f0C1d0e6E7` | [pair](https://robinhoodchain.blockscout.com/address/0x4E93763183A3eC492f01C06AE28805f0C1d0e6E7)     |
| ETH/ASH pair      | `0xd34e47cFDd037Cb691012C3a6356d2694176bb54` | [pair](https://robinhoodchain.blockscout.com/address/0xd34e47cFDd037Cb691012C3a6356d2694176bb54)     |

`crypto/hardhat/deployments/robinhood-dex.json` retains an earlier ASH and
MasterChef deployment. They are explicitly retired in
`crypto/hardhat/deployments/robinhood-funded-farm.json`; listing material must
use the funded farm and bridged ASH addresses above.

## Public data endpoints

| Endpoint                                  | Purpose                                    | Readiness                                                              |
| ----------------------------------------- | ------------------------------------------ | ---------------------------------------------------------------------- |
| `https://cyberia.church/api/dex/apr`      | Indexed pool APR/TVL snapshot for the site | Public JSON; APY is variable and must not be represented as guaranteed |
| `https://cyberia.church/analytics`        | Human-readable ecosystem analytics         | Public page                                                            |
| `https://explorer.cyberia.church/api/v2/` | Blockscout API base used by the app        | Confirm endpoint-specific availability before listing                  |
| `https://rpc.cyberia.church`              | EVM JSON-RPC                               | Public; archive-history capability remains to be tested                |

The internal same-origin endpoint `/api/rpc/cyberia` is a browser proxy, not a
third-party integration endpoint.

## Known deployment anchors

These are the deployment or pool-addition anchors actually present in the
repository. A pool-add block must not be described as a contract deployment
block.

| Item                                             | Repository evidence                                                                       |
| ------------------------------------------------ | ----------------------------------------------------------------------------------------- |
| ASH emission start                               | block `6374644` in `cyberia-ash-emission.json`                                            |
| BNB/CYBER farm pool addition                     | block `12342276`, tx `0x7dc8942380af237162fcb0c7cc36c6d303f9a639399b9988e81adc12671684f6` |
| SPY/CYBER farm pool addition                     | block `13678632`, tx `0x86bf8e6bda65857744fbf0b9ff344b6e490210edc61533386cce0fab4da13975` |
| ORBV/CYBER farm pool addition                    | block `13850280`, tx `0x255c4ec5aa14eaa41ce6237365f1b1d6b45771b9ff31509933ffaf951bb20e65` |
| Cyberia bridge manifest timestamp                | `2026-05-02T17:43:22.752Z`                                                                |
| Current Robinhood funded farm manifest timestamp | `2026-07-24T23:18:23.101Z`                                                                |

TODO before adapters: recover exact deployment block and transaction for every
factory, router, farm, bridge, lending controller, and token included in a
submission.

## DefiLlama chain listing

Official process:

- Add the chain to `projects/helper/chains.json` in
  `DefiLlama/DefiLlama-Adapters`.
- Add required token mappings and pricing identifiers in
  `projects/helper/tokenMapping.js`.
- Submit a TVL adapter for at least one protocol on the chain; DefiLlama states
  that it does not add a chain without a tracked protocol.
- Use a unique chain slug and the EVM short name agreed for the listing.
- Test against a public RPC that can serve all historical blocks required by
  the adapter.

Sources:

- <https://docs.llama.fi/list-your-project/how-to-add-a-new-blockchain>
- <https://docs.llama.fi/list-your-project/submit-a-project>

Readiness:

- [x] Public EVM RPC, chain ID, native currency, explorer, factory, and router
      are documented.
- [x] Ritual has on-chain liquidity contracts suitable for a protocol adapter.
- [ ] Decide canonical DefiLlama slug/short name: proposed `cyberia`, owner
      approval required.
- [ ] Confirm archive RPC support from the first adapter block.
- [ ] Establish pricing mappings for CYBER, ASH, and every counted asset.
- [ ] Determine exact adapter start block/timestamp.
- [ ] Prepare the chain and token-mapping changes in a fork.
- [ ] Submit the adapter PR only after owner approval.

## DefiLlama TVL adapter

Proposed scope:

- Ritual DEX TVL: enumerate pairs from factory
  `0xB0aC30907c04b61F1482e62eA66eF4562a690917` and count reserves without
  double-counting staked LP tokens.
- Lending TVL: count supplied underlying balances across markets listed by
  comptroller `0xe66aa9842dc74F1c10ede19cA20Ece6E08F1CC88`, with borrowed balances handled
  per DefiLlama methodology.
- Staking/pool2: report separately where required; never include the same
  underlying reserves in both DEX TVL and farm TVL.

Checklist:

- [ ] Confirm pricing support for all counted underlying tokens.
- [ ] Verify historical `allPairsLength` and market calls through archive RPC.
- [ ] Specify methodology, `timetravel`, start, and any token substitutions.
- [ ] Test the adapter using the official repository tooling.
- [ ] Reconcile output against `/api/dex/apr` and direct reserve calls.

Official adapter guide:
<https://docs.llama.fi/list-your-project/how-to-write-an-sdk-adapter>.

## DefiLlama DEX volume adapter

Proposed scope: index `Swap` events from every Ritual pair created by the
Cyberia factory. Return `dailyVolume` in token balances using the current
dimension-adapter API.

- [ ] Recover the factory deployment block.
- [ ] Confirm the exact pair ABI and fee tier used by the deployed factory.
- [ ] Handle token prices and decimals without assuming every asset is priced.
- [ ] Deduplicate swaps and validate a sample day against explorer logs.
- [ ] Add and test under `dexs/` in `DefiLlama/dimension-adapters`.

## DefiLlama fees/revenue adapter

For a Uniswap V2-style DEX, LP fees are generally embedded in pool reserves.
The exact Ritual fee and any protocol-fee destination must be confirmed from
the deployed bytecode/source and factory configuration before publishing a
methodology.

- [ ] Confirm swap fee rate from deployed contracts.
- [ ] Confirm whether protocol fees are enabled and identify the recipient.
- [ ] Calculate `dailyFees`, `dailyUserFees`, `dailySupplySideRevenue`, and
      `dailyRevenue` according to the current DefiLlama definitions.
- [ ] Do not claim protocol revenue if all swap fees accrue only to LPs.
- [ ] Test under `fees/` in `DefiLlama/dimension-adapters`.

Current dimension-adapter guide:
<https://docs.llama.fi/list-your-project/other-dashboards>.

## GeckoTerminal chain and DEX listing

GeckoTerminal's official EVM path is an application request. Its documentation
asks applicants to check whether the DEX fork is supported; unsupported forks
follow the custom-integration route.

- [x] EVM chain ID, RPC, explorer, native currency, factory, and router.
- [x] Active factory pairs exist on Cyberia.
- [ ] Confirm that the deployed QuickSwap/Uniswap V2 variant is covered by the
      current supported-forks list.
- [ ] Prepare factory deployment block and a sample active pair with reserves.
- [ ] Prepare stable, public logo URLs.
- [ ] Prepare project contact email; it is not stored in the repository.
- [ ] Apply only after owner approval.

Official guidance:
<https://support.coingecko.com/hc/en-us/articles/22611672824473-How-do-I-get-my-EVM-Chain-DEX-listed-on-GeckoTerminal>.

## ChainList / ethereum-lists

ChainList consumes the `ethereum-lists/chains` registry. The registry requires
a CAIP-2 filename, unique name/short name and chain ID, native currency, RPC,
info URL, and an EIP-3091 explorer. Icons must be public IPFS assets under
250 KB and the repository's CI and formatting checks must pass.

Prepared chain record (icon intentionally omitted until a CID is approved):

```json
{
  "name": "Cyberia",
  "chain": "CYBER",
  "rpc": ["https://rpc.cyberia.church"],
  "faucets": [],
  "nativeCurrency": {
    "name": "Cyber",
    "symbol": "CYBER",
    "decimals": 18
  },
  "infoURL": "https://cyberia.church",
  "shortName": "cyberia",
  "chainId": 49406,
  "networkId": 49406,
  "explorers": [
    {
      "name": "Cyberia Explorer",
      "url": "https://explorer.cyberia.church",
      "standard": "EIP3091"
    }
  ]
}
```

- [ ] Check `49406`, `Cyberia`, and `cyberia` for collisions immediately before
      submission.
- [ ] Pin a compliant logo to public IPFS and add `_data/icons/cyberia.json`.
- [ ] Add `_data/chains/eip155-49406.json`.
- [ ] Run `./gradlew run` and the repository's Prettier command.

Registry instructions:
<https://github.com/ethereum-lists/chains/blob/master/README.md>.

## DappRadar

DappRadar's current developer flow requests a project name, website, social
links, descriptions, a 250×250 PNG/JPG logo (maximum 150 KB), category/tags,
screenshots, and live smart-contract addresses.

Recommended submission:

- Primary category: DeFi.
- Product: Ritual/Cyberia, with factory, router, farm, bridge, and lending
  controller disclosed as separate contracts.
- Landing URL: `https://cyberia.church/robinhood-chain` for the acquisition
  campaign, or the canonical `https://cyberia.church` for the ecosystem.

- [ ] Confirm whether Cyberia chain already exists in DappRadar's chain picker.
- [ ] If absent, coordinate chain integration before a dapp submission.
- [ ] Produce one square logo and up to three current product screenshots.
- [ ] Approve short (≤160 characters) and full descriptions.
- [ ] Submit through the Developer Dashboard only after owner approval.

Official guide:
<https://dappradar.com/blog/how-to-list-your-dapps-on-dappradar-for-free>.

## CoinMarketCap

The official request form supports new cryptoasset, exchange/chain, and market
pair requests. The repository has technical URLs and contracts but not enough
verified supply, organization, launch, and contact data for a truthful
submission.

- [x] Website, source, explorer, chain ID, RPC, social and chat URLs.
- [x] Contract and explorer URLs for key tokens.
- [ ] Owner-approved legal/project representative and contact email.
- [ ] Evidence-backed project launch/genesis date.
- [ ] Transparent 200×200 square PNG at a permanent public URL.
- [ ] Third-person 450–600 word factual description.
- [ ] Auditable circulating, total, and maximum supply values.
- [ ] Numeric-only supply API endpoints, or an explicit determination that they
      are not applicable.
- [ ] Supported market/pair URLs with non-inflated volume evidence.
- [ ] Security audit links and team/funding disclosure where applicable.
- [ ] Submit the correct chain/exchange/asset form only after owner approval;
      do not send duplicates.

Official request directory:
<https://support.coinmarketcap.com/hc/en-us/articles/360018997951-Link-to-Request-Form>.

## Owner decisions and missing evidence

The following block external submissions:

1. Canonical organization/contact identity for listing forms.
2. Exact Cyberia genesis date and genesis block evidence.
3. Exact deployment blocks/transactions for core contracts.
4. Archive RPC retention and rate-limit guarantees.
5. Canonical chain slug/short name and permanent logo CIDs/URLs.
6. Token pricing identifiers and verified supply methodology.
7. Deployed DEX swap-fee rate and protocol revenue configuration.
8. Approved factual descriptions, screenshots, and security/audit references.
