# Documentation Plan

This plan keeps Cyberia documentation organized by audience and by the task a reader wants to complete. It is the checklist for expanding the manual without duplicating product behavior or publishing staff runbooks.

## Audiences

| Audience | Goal | Published location |
| --- | --- | --- |
| First-time crypto user | Complete one safe task and understand every wallet prompt | `docs/user-guide/` |
| Returning Cyberia user | Find a product, asset identifier, or troubleshooting answer quickly | `docs/user-guide/` |
| External integrator | Connect a wallet, call the RPC, use contracts or the inference API | `docs/developers/`, `docs/ai-api.md` |
| Repository contributor | Find component ownership, local setup, tests, and sources of truth | `docs/developers/`, `docs/contributing.md` |
| Cyberia staff | Operate services, releases, nodes, monitoring, and administrative tools | Internal pages excluded from the public build |

## Page pattern for beginner guides

Every task guide should contain these parts in this order:

1. **Outcome** — one sentence saying what the reader will finish.
2. **Before you start** — wallet, network, asset, and gas requirements.
3. **Steps** — numbered actions using the labels visible in the interface.
4. **Wallet confirmations** — explain message signatures, approvals, and final transactions separately.
5. **Completion check** — show how to verify the result in the app and explorer.
6. **Next action** — link to the logical next guide.

Use plain language first and introduce one crypto term at a time. Link recurring concepts back to [Crypto basics](user-guide/crypto-basics.md) instead of redefining them on every page.

## Current public structure

### Learn

- Crypto basics
- Getting started
- Cyberia Wallet
- Why CYBER exists

### Move and use assets

- Bridge
- DEX, swaps, and liquidity
- Tokens and contract identifiers

### Participate

- Ecosystem products
- Account, profile, progression, and achievements
- Apps and downloads
- FAQ and troubleshooting

### Build

- Architecture
- Component guide
- Local development
- Testing and verification
- Network reference
- Inference API
- LainOS and Wired

## Publication boundary

The public VitePress configuration excludes operations manuals, monitoring and analytics runbooks, release procedures, and node-provisioning instructions. They remain source-controlled for staff workflows and are not added to public navigation, search, sitemap, or generated pages.

When a topic serves both developers and staff, split it into two pages:

- a public integration reference containing stable interfaces and local-development information;
- an internal runbook containing production topology, credentials, administrative actions, incident response, and deployment steps.

## Delivery roadmap

### Now: foundation

- Maintain the beginner learning path and shared crypto glossary.
- Keep network constants, official URLs, token identifiers, and contract links synchronized with checked-in sources of truth.
- Cover the wallet, CYBER, bridge, DEX, ecosystem products, account, and downloads.
- Maintain component selection, local development, testing, and integration guides for contributors.
- Keep operations and node provisioning outside the public build.

### Next: one guide per completed task

- Add focused walkthroughs for lending, staking/farming, launchpad, DAO, NFT market, and each on-chain game when their UI wording stabilizes.
- Add screenshots or short recordings only where they clarify a wallet confirmation or multi-screen flow.
- Add platform-specific installation pages when desktop, Android, iOS, and extension release channels each have stable public links.
- Add copyable developer examples for JSON-RPC, wallet connection, token reads, and transaction receipts.

### Ongoing: release discipline

- Review documentation in the same change as a public route, status, fee, asset, contract, or API update.
- Run `cd docs && npm run build` for every documentation change.
- Check links and UI labels during each product release.
- Review beginner guides with a reader who has not used Cyberia before.
- Archive or rewrite pages whose product flow has been replaced.

## Source ownership

| Claim | Source of truth |
| --- | --- |
| Network identity and public endpoints | Root `AGENTS.md`, chain configuration, public RPC |
| Routes and interface labels | Laravel routes and current frontend pages |
| Token decimals and bridge corridors | `backend/laravel/config/bridge.php` and deployment records |
| EVM contract addresses | `crypto/hardhat/deployments/` and explorer verification |
| Solana program and mint identifiers | `crypto/anchor/` and checked-in configuration |
| Component commands | The component's `package.json`, README, or nested `AGENTS.md` |
| Public documentation navigation | `docs/.vitepress/config.mts` |

## Definition of done for a new page

- It has one clear audience and one primary task.
- Steps match current interface labels and do not depend on hidden staff actions.
- Amounts, decimals, addresses, routes, and statuses come from a repository source of truth.
- It links to prerequisites and a logical next page.
- It includes a completion check.
- It is added to the correct overview and sidebar.
- `npm run build` completes successfully.
