# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

**Singularity** is the Cyberia ecosystem monorepo. Cyberia is an EVM-compatible chain (Chain ID: 49406, RPC: https://rpc.cyberia.church, native token: CYBER). The repo contains a bridge UI/backend, a DEX frontend, EVM + Solana smart contracts, a block explorer, and operational bots/scripts.

## Components

| Directory | Stack | Purpose |
|-----------|-------|---------|
| `backend/laravel/` | Laravel 13, Vue 3, Inertia v3, Vite, Tailwind v4 | Bridge UI + backend API |
| `frontend/ritual/` | React 18, CRA, Material-UI v4, Ethers v5 | Cyberia DEX interface |
| `frontend/jekyll/` | Jekyll (Ruby) | Static blog |
| `crypto/hardhat/` | Hardhat 3, Solidity, viem, OpenZeppelin 4.9 | EVM smart contracts |
| `crypto/anchor/` | Anchor, Rust, SPL Token | Solana smart contracts |
| `services/blockscout/` | Blockscout fork, Docker Compose | Block explorer |
| `services/lisp/` | Common Lisp (SBCL) | Daemon/HTTP services |
| `scripts/python/` | Python 3, web3, telegram-bot | Airdrop bots, crawlers |

Each component manages its own dependencies — no Turbo/Nx monorepo tooling.

## Commands

### Backend (Laravel) — `backend/laravel/`

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate:fresh --seed

composer run dev          # Full stack dev: PHP server + queue + logs + Vite
npm run build             # Production asset build
php artisan test          # Run Pest tests
./vendor/bin/pest         # Run Pest directly

composer run lint         # PHP Pint autofix
composer run lint:check   # PHP Pint check (no changes)
npm run lint              # ESLint autofix
npm run lint:check        # ESLint check
npm run format            # Prettier autofix
npm run format:check      # Prettier check
npm run types:check       # Vue/TS type check

composer run ci:check     # Full CI suite (lint + types + tests)
```

### EVM Contracts (Hardhat) — `crypto/hardhat/`

```bash
npm install
npx hardhat compile
npx hardhat test                   # All tests
npx hardhat test nodejs            # Node.js tests only
npx hardhat test solidity          # Solidity tests only

# Deploy to Cyberia network
npx hardhat run scripts/<script>.ts --network cyberia
```

### Solana Contracts (Anchor) — `crypto/anchor/`

```bash
npm install
anchor build
anchor test
npm run lint
npm run lint:fix
```

### DEX Frontend — `frontend/ritual/`

```bash
npm install
npm start                                     # Dev server port 3000
npm run build
DISABLE_ESLINT_PLUGIN=true npm run build      # Skip lint gate
npm run ipfs-deploy
```

### Jekyll Blog — `frontend/jekyll/`

```bash
bundle install
bundle exec jekyll serve
bundle exec jekyll build
```

### Explorer — `services/blockscout/docker-compose/`

```bash
docker compose up -d
docker compose down
```

## Architecture

### Bridge

The bridge is the core feature of `backend/laravel/`. Users lock funds on one chain and receive them on another. Key files:
- `app/Services/BridgeService.php` — cross-chain logic, 1% fee deduction
- `app/Http/Controllers/BridgeController.php` — API endpoints
- `routes/api.php` — REST API for wallet auth and bridge operations
- Hot wallet on Solana: `E6E8AeKoT6i2zmwrGyDF2LwfEfjX9Xg8LfEj2Fu8Yf7w`

### Authentication

Two-path Web3 login (no passwords):
1. **EVM**: MetaMask → ECDSA nonce signing → `Web3LoginController`
2. **Solana**: Phantom → `SolanaWalletAuthController`

Both use a nonce-based challenge stored in `WalletNonce`, verified, then exchanged for a Laravel Sanctum token.

### Laravel Frontend (Vue 3 + Inertia)

Pages live in `resources/js/pages/`. Composables handle Web3 state:
- `useSolanaWallet`, `useWallet` — wallet connection
- `useBridge` — bridge transaction flow
- `useWalletAuth` — authentication

Routes are typed via Wayfinder (`@/routes`, `@/actions`). An RPC proxy at `/api/rpc/cyberia` prevents mixed-content issues when the app is served over HTTPS.

### DAO Contracts (`crypto/hardhat/contracts/dao/`)

New contracts (undeployed at repo start): `DAOFactory.sol`, `DAOGovernor.sol`, `StakingVault.sol`. The Laravel backend has corresponding `Dao`, `Proposal`, `ProposalVote`, `ProposalComment` models, indicating the full DAO flow spans both backend and contracts.

### EVM Contract Layout (`crypto/hardhat/contracts/`)

- **Tokens**: `USDC.sol`, `USDT.sol`, `VotingToken.sol`, `WCYBER.sol`, `CyberToken.sol`
- **Bridge**: `CyberBridge.sol`
- **DEX**: `quickswap/` (AMM fork), `Multicall3.sol`
- **DAO**: `DAOFactory.sol`, `DAOGovernor.sol`, `StakingVault.sol`
- **Utilities**: `TokenFactory.sol`, `TelegramChatToken.sol`, `GitHub.sol`

Hardhat 3 uses EDR simulation. Networks: `hardhatMainnet`, `hardhatOp`, `sepolia`, `cyberia`.

## Environment Variables

### Backend (`backend/laravel/.env`)

```
APP_KEY, APP_URL, APP_DEBUG
DB_CONNECTION (default: sqlite)
CYBERIA_RPC_URL               # default: https://rpc.cyberia.church
BRIDGE_EVM_RPC_URL
BRIDGE_EVM_CONTRACT_ADDRESS
BRIDGE_RELAYER_PRIVATE_KEY
BRIDGE_RELAYER_ADDRESS
BRIDGE_SOLANA_RPC_URL
BRIDGE_SOLANA_PROGRAM_ID
PROXY_URL                     # optional HTTP proxy
```

### Hardhat (`crypto/hardhat/.env`)

```
DEPLOYER_PK                   # required for all deployments
CYBERIA_RPC_URL               # optional, default: https://rpc.cyberia.church
USDC_OWNER, USDT_OWNER        # optional, default to deployer
SEPOLIA_PRIVATE_KEY, SEPOLIA_RPC_URL
```

## CI

GitHub Actions (`.github/workflows/`):
- **lint.yml** — PHP Pint + ESLint + Prettier on push/PR to `develop`, `main`, `master`, `workos`
- **tests.yml** — Pest 4 on PHP 8.3/8.4/8.5 + Node 22 matrix
