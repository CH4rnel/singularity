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
- Do not edit generated dependency/runtime folders unless explicitly requested: `node_modules/`, `vendor/`, `target/`, `build/`, `_site/`, `artifacts/`, `cache/`, `test-ledger/`, `logs/`, `linux/custom-root/`, and Blockscout data volumes under `services/blockscout/docker-compose/services/*-data/`.
- Prefer `rg --files` and scoped searches over broad `find` walks through runtime trees.
- Root `README.md` is user-facing and currently less complete than agent docs. Do not rely on stale root paths such as `hardhat/`; EVM contracts live in `crypto/hardhat/`.

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
- Profile page (`/profile`): per-user CEX-style deposit addresses (BTC/LTC/YTN P2PKH from per-chain HD seeds, XMR integrated address; `UserDepositAddressService`, operator command `bridge:user-deposit`) plus on-chain nicknames/achievements via the `CyberiaProfile` contract at `0xa9101ee859850c037b0867156b3535F78A387C0d` (`crypto/hardhat/contracts/CyberiaProfile.sol`, Laravel `ProfileOnchainService`/`AchievementService`, override via `CYBERIA_PROFILE_ADDRESS`).

## Verification

Use the smallest relevant check:

- Laravel PHP: `cd backend/laravel && vendor/bin/pint --dirty --format agent && php artisan test --compact`
- Laravel frontend: `cd backend/laravel && npm run lint:check && npm run types:check && npm run build`
- Ritual: `cd frontend/ritual && npx eslint <changed files>` or the deploy build flow above
- Hardhat: `cd crypto/hardhat && npx hardhat test`
- Anchor: `cd crypto/anchor && anchor test` or at least `anchor build`
- NO CARRIER: `cd game/nocarrier && godot4 --headless --import . && godot4 --headless --path . -s tests/smoke.gd`
- Jekyll: `cd frontend/jekyll && bundle exec jekyll build`
- Static landing: inspect HTML and validate if a validator is available
- Blockscout compose/proxy: `cd services/blockscout/docker-compose && docker compose config`
