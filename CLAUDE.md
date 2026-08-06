# CLAUDE.md

This file provides Claude Code guidance for the Singularity repository. Keep it aligned with `AGENTS.md`, which is the cross-agent source of truth.

## Overview

**Singularity** is the Cyberia ecosystem monorepo. Cyberia is an EVM-compatible chain with chain ID `49406`, RPC `https://rpc.cyberia.church`, native token `CYBER`, explorer `https://explorer.cyberia.church`, bridge `https://bridge.cyberia.church`, site `https://cyberia.church`, DEX `https://swap.cyberia.church`, and CYBER.sol mint `E67WWiQY4s9SZbCyFVTh2CEjorEYbhuVJQUZb3Mbpump`.

## Components

| Directory | Stack | Purpose |
|-----------|-------|---------|
| `backend/laravel/` | Laravel 13, Vue 3, Inertia v3, Vite, Tailwind v4 | Bridge/site backend and UI |
| `frontend/ritual/` | React 18, CRA/react-app-rewired, Material UI v4, ethers v5 | Ritual Cyberia DEX |
| `frontend/landing/` | Static HTML/CSS | Landing and brand pages |
| `frontend/jekyll/` | Jekyll | Cyberia blog/static site |
| `frontend/desktop/` | Electron 43, electron-builder | Cyberia desktop app (Linux/Windows/macOS) wrapping the live site |
| `frontend/mobile/` | Capacitor 8, Android, iOS | Cyberia mobile app (Android/iOS) wrapping the live site |
| `crypto/hardhat/` | Hardhat 3, Solidity, viem, ethers v6, OpenZeppelin 4.9 | EVM contracts and deployment scripts |
| `crypto/anchor/` | Anchor, Rust, Solana Web3.js, SPL Token | Solana bridge contracts and relayer scripts |
| `crypto/quickswap-core/` | Truffle-era Uniswap v2 core fork | Legacy DEX core contracts/tests |
| `services/blockscout/` | Docker Compose (official Blockscout images) | Cyberia explorer deployment config; no Elixir source vendored |
| `services/cyberia-node/` | polygon-edge/IBFT PoA, Docker Compose | Cyberia L1 second node: non-validating full/RPC follower (chainID 49406); prepared, not deployed |
| `services/ipfs/` | Docker Compose | IPFS service config |
| `services/lisp/` | Common Lisp/SBCL | Daemon and HTTP services |
| `services/telegram-bot/` | Python, python-telegram-bot, web3, SQLAlchemy | Cyberia Telegram bot: wallet rewards, chat tokens, on-chain announcers, whales gate |
| `services/lainos/` | TypeScript, Node, Anthropic SDK, viem | LainOS: autonomous AI agent framework (ElizaOS-like) with a Cyberia chain plugin |
| `game/wired/` | Godot 4, GDScript | Wired: 3D on-chain game; NPCs think via LainOS, world reacts to the Cyberia chain |
| `game/nocarrier/` | Godot 4, GDScript | NO CARRIER: first-person netstalking survival-horror sim (en/ru); world/UI/audio built procedurally in code; browser build connects a wallet and mints decoded anomalies as CyberiaNFTs |
| `scripts/` | Python, JS, Lisp | Airdrop bots, crawlers, price scripts, operations |
| `linux/` | Linux build notes/config | Cyberia OS artifacts |

Each component manages its own dependencies. There is no root Turbo/Nx workspace.

## Safety Rules

- Do not print or commit secrets from `.env`, wallet keypair JSON files, cookies, bot tokens, private keys, or production Blockscout env files. Mention variable names only.
- Do not edit generated dependency/runtime folders unless explicitly requested: `node_modules/`, `vendor/`, `target/`, `build/`, `_site/`, `artifacts/`, `cache/`, `test-ledger/`, `logs/`, `linux/custom-root/`, `frontend/desktop/dist/`, `frontend/mobile/www/`, and Blockscout data volumes under `services/blockscout/docker-compose/services/*-data/`.
- Prefer `rg --files` and scoped searches over broad `find` walks through runtime trees.
- Root `README.md` is user-facing and currently less complete than agent docs. Do not rely on stale root paths such as `hardhat/`; EVM contracts live in `crypto/hardhat/`.
- When drafting posts for the user, assume there is no character limit unless the user explicitly sets one.

## Commands

### Laravel Backend — `backend/laravel/`

Nested instructions in `backend/laravel/AGENTS.md` are authoritative.

```bash
composer install
npm install
composer run dev
npm run build
composer run ci:check
php artisan test --compact
vendor/bin/pint --dirty --format agent
```

Use Wayfinder imports from `@/routes` and `@/actions` instead of hardcoded Laravel URLs. Vue pages live in `resources/js/pages`.

### Ritual DEX — `frontend/ritual/`

```bash
npm install
npm start
npm run build
npm run test
npm run ipfs-deploy
```

Plain `npm run build` can fail on existing ESLint/TypeScript drift. For an explicit deploy artifact request without fixing old drift:

```bash
DISABLE_ESLINT_PLUGIN=true TSC_COMPILE_ON_ERROR=true npm run build
test -f build/index.html
test -d build/static
find build/static -maxdepth 2 -type f | wc -l
```

`npm run ipfs-deploy` may fail on modern Node with `RequestInit: duplex option is required when sending a body`; report that exactly if it happens.

### Hardhat EVM — `crypto/hardhat/`

```bash
npm install
npx hardhat compile
npx hardhat test
npx hardhat test solidity
npx hardhat test nodejs
npx hardhat run scripts/<script>.ts --network cyberia
```

`hardhat.config.ts` requires `DEPLOYER_PK` at import time. For local compile/test without a real deploy key, set a throwaway 32-byte private key in the environment. Never read or print `.env` secrets.

Mint/burn administration for deployed Cyberia ERC20s is in `scripts/token-admin.ts`, using `deployments/cyberia-tokens.json`. Bridge relayer one-shot scripts are `scripts/relay-mint.ts` and `scripts/relay-burn.ts`.

### Anchor Solana — `crypto/anchor/`

```bash
npm install
anchor build
anchor test
npm run lint
npm run lint:fix
```

Bridge scripts include `scripts/init-bridge.ts`, `scripts/relay-release-native.ts`, `scripts/relay-spl-transfer.ts`, and `scripts/slot-burn-and-payout.ts`.

### Native App Shells — `frontend/desktop/`, `frontend/mobile/`

Both render the live site (`CYBERIA_APP_URL`, default `https://cyberia.church`) instead of bundling it, so a production deploy updates both apps. Both are the Cyberia **wallet** first: they launch on `CYBERIA_APP_PATH` (default `/wallet`), which renders without site chrome inside a native shell (`layouts/NativeShellLayout.vue`, selected in `app.ts` via `isNativeShell()`), with the rest of the site one link away. `CYBERIA_APP_PATH` must be a same-origin absolute path — a full URL or `//host` falls back to `/wallet`. Per-directory `README.md` files are authoritative.

```bash
cd frontend/desktop && npm install && npm test && npm run dist:linux
cd frontend/mobile && npm install && npm test && npm run sync
```

Windows/macOS installers and the Android APK come from `.github/workflows/apps.yml` (tag `app-v*` or manual dispatch); the Android build needs a local SDK otherwise. `frontend/mobile/www/` and `frontend/desktop/dist/` are generated; `frontend/mobile/android/` and `ios/` are committed because they carry the deep-link intent filters and icons.

### Static Frontends

```bash
cd frontend/jekyll
bundle install
bundle exec jekyll serve
bundle exec jekyll build
```

`frontend/landing/` is plain static HTML/CSS and needs no build step.

### Blockscout — `services/blockscout/docker-compose/`

```bash
docker compose up -d
docker compose down
docker compose config
```

Be careful with production-host paths such as `/root/singularity/...`.

## Architecture Notes

- Laravel bridge logic is under `backend/laravel/app/Services/BridgeService.php`, `BridgeRelayerService.php`, `BridgeFeeService.php`, and `ProcessBridgeRequest.php`.
- Web3 auth uses EVM and Solana nonce flows backed by `WalletNonce` and Sanctum.
- Laravel frontend wallet/bridge composables live under `backend/laravel/resources/js/composables/`.
- EVM contracts include bridge (`CyberBridge.sol`, `WrappedCyberSol.sol`), tokens (`USDC.sol`, `USDT.sol`, `BTC.sol`, `LTC.sol`, `SOL.sol`, `RUB.sol`, `GOLD.sol`, etc.), DAO (`contracts/dao/`), lending (`contracts/lending/`), launchpad/NFT, and QuickSwap forks.
- Anchor bridge instructions live in `crypto/anchor/programs/anchor/src/instructions/`.
- Gamification/retention: `GamificationService` + `config/gamification.php` (XP ledger `xp_entries`, `user_stats`, `user_quests`; `/profile` panel, public `/leaderboard`, `gamification:sync` command). Client events never pay value XP — only visits/page views; swaps, liquidity, bridges and governance are credited from the indexer, `bridge_requests` and the DAO tables. `UserAnalyticsService` adds retention cohorts to `/crm/analytics`.
- Unified multichain wallet (`/wallet`): one BIP-39 seed phrase derives an EVM account (Cyberia 49406, Robinhood 4663, BNB 56, Base 8453 — all the *same* address), a Solana account and a native Monero account (`resources/js/lib/wallet/`, one adapter per chain in `chains.ts`, screens in `components/wallet/`, page `pages/Wallet.vue`, styling in `resources/css/wallet.css`). Non-custodial — the phrase is generated in the browser, AES-GCM encrypted under the user's password in localStorage, and never sent to Laravel; the route only passes a public Solana RPC URL, USD quotes and the saved XMR payout address. Balances, history, fee quotes and signing are all browser-side; Monero is receive-only (no view-key scanner). The flow is welcome → risk notice → hold-to-reveal seed → backup check → vault password → portfolio, with send guarded by a plain-language sentence and hold-to-sign. Adapters may declare `fetchFees`, `fetchHistory` and `awaitOutcome`; a chain that declares none says so in the UI rather than showing an empty list. Adding an EVM network is one `evmChain({...})` entry pointing at an existing `lib/evmChains.ts` row, plus a hue in `wallet.css` and a tile in `NetworkMark.vue`. In-app history needs a keyless index — Blockscout chains (Cyberia, Robinhood) get it, Etherscan-family ones (BNB, Base) declare `historyNote` instead. Derivation is pinned to published vectors in `tests/Frontend/` — run `npm run test:frontend`.
- Wallet USD quotes: `WalletPriceService` (CYBER from the DexScreener CYBER.sol feed; ETH/BNB/SOL/XMR from CoinGecko, deduplicated because Robinhood and Base both pay gas in ETH; cached 5 min) feeds the `/wallet` Inertia prop and `GET /api/wallet/prices`. A price that cannot be read is `null`, never `0` — the UI renders "—" and marks the portfolio total partial.
- Profile page (`/profile`): per-user CEX-style deposit addresses (BTC/LTC/YTN P2PKH from per-chain HD seeds, XMR integrated address; `UserDepositAddressService`, operator command `bridge:user-deposit`) plus on-chain nicknames/achievements via the `CyberiaProfile` contract at `0xa9101ee859850c037b0867156b3535F78A387C0d` (`crypto/hardhat/contracts/CyberiaProfile.sol`, Laravel `ProfileOnchainService`/`AchievementService`, override via `CYBERIA_PROFILE_ADDRESS`).

## Verification

Use the smallest relevant check:

- Laravel PHP: `cd backend/laravel && vendor/bin/pint --dirty --format agent && php artisan test --compact`
- Laravel frontend: `cd backend/laravel && npm run lint:check && npm run types:check && npm run build`
- Ritual: `cd frontend/ritual && npx eslint <changed files>` or the deploy build flow above
- Desktop shell: `cd frontend/desktop && npm test` (add `npm run pack` when the Electron main process changed)
- Mobile shell: `cd frontend/mobile && npm test && npm run sync`
- Hardhat: `cd crypto/hardhat && npx hardhat test`
- Anchor: `cd crypto/anchor && anchor test` or at least `anchor build`
- NO CARRIER: `cd game/nocarrier && godot4 --headless --import . && godot4 --headless --path . -s tests/smoke.gd`
- Jekyll: `cd frontend/jekyll && bundle exec jekyll build`
- Static landing: inspect HTML and validate if a validator is available
- Blockscout compose/proxy: `cd services/blockscout/docker-compose && docker compose config`
