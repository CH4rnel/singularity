# Singularity

Singularity is the open-source monorepo for **Cyberia**: an experimental EVM Layer 1 and application stack for making contribution, community activity, liquidity, and governance visible on-chain.

If you arrived from **pump.fun**: `CYBER.sol` is the Solana-facing community asset. The chain itself is **Cyberia** (`chainId 49406`) and uses native **CYBER** for gas. Singularity is the software stack that connects those surfaces: the website, bridge, DEX, launchpad, analytics, contracts, bots, and explorer configuration.

## What Is This?

Cyberia is trying to solve a specific problem: open-source and community work creates real value, but most of that work is not recorded, rewarded, or governed by durable infrastructure.

Singularity is the implementation layer:

- **Cyberia L1**: an EVM-compatible network where CYBER pays for transactions.
- **Contribution rails**: Telegram, GitHub, and X bots/scripts that can distribute tokens for participation.
- **User applications**: bridge, DEX, launchpad, NFT market, lending UI, slots, DAO pages, and analytics.
- **Liquidity and onboarding**: `CYBER.sol` on Solana is the public entry point for pump.fun users and external liquidity.
- **Operations**: Blockscout explorer config, deployment scripts, bridge relayers, and service daemons.

The long-term direction is a settlement layer for contributor economies: actions such as code contribution, moderation, community participation, token launches, and governance should become measurable, auditable economic events.

## What Works Now?

Live or implemented surfaces in this repo:

| Surface | URL / Path | Status |
| --- | --- | --- |
| Cyberia L1 | `https://rpc.cyberia.church`, chain `49406` | Live RPC/network config |
| Explorer | `https://explorer.cyberia.church` | Blockscout deployment config |
| Main site / bridge app | `https://cyberia.church`, `https://bridge.cyberia.church` | Laravel + Vue/Inertia app |
| DEX | `https://swap.cyberia.church` | Ritual/QuickSwap-derived React app |
| Bridge | `backend/laravel`, `crypto/hardhat`, `crypto/anchor` | EVM/Solana bridge UI, contracts, relayer scripts |
| Launchpad | `backend/laravel/resources/js/pages/Launchpad.vue` | Token launch UI + metadata storage |
| Lending | `backend/laravel/resources/js/pages/Lending.vue` | EVM lending UI/contracts |
| DAO | `backend/laravel/resources/js/pages/dao`, `crypto/hardhat/contracts/dao` | DAO UI/models/contracts |
| Analytics | `/analytics` in Laravel app | Public product analytics page |
| Bots/scripts | `scripts/python` | Telegram/GitHub/X airdrop and crawler scripts |
| Blog/static pages | `frontend/jekyll`, `frontend/landing` | Static content and brand pages |

This is an active experimental stack. Some components are production-facing, some are prototypes, and some are operational scripts used by the project.

## Why So Much Elixir?

GitHub language stats may show this repository as mostly **Elixir** because `services/blockscout/` contains a Blockscout fork. That does not mean the Cyberia application itself is an Elixir app.

The main product surfaces are split like this:

- **Laravel + Vue** (`backend/laravel/`): bridge/site app, launchpad, analytics, DAO pages, lending UI, slots UI.
- **React** (`frontend/ritual/`): Ritual DEX frontend.
- **Solidity / TypeScript** (`crypto/hardhat/`): Cyberia EVM contracts and deployment scripts.
- **Rust / TypeScript** (`crypto/anchor/`): Solana bridge program and relayer scripts.
- **Elixir** (`services/blockscout/`): explorer fork/configuration, not the core app.
- **Python** (`scripts/python/`): operational bots, airdrop scripts, and crawlers.

If you want to inspect the user-facing product, start with `backend/laravel/` and `frontend/ritual/`. If you want to inspect the explorer, start with `services/blockscout/`.

## Token Utility

There are multiple Cyberia-related tokens. They are not interchangeable.

### CYBER

`CYBER` is the native token of the Cyberia EVM chain.

Current role:

- pays gas on Cyberia mainnet;
- powers network operations and contract interaction;
- is the base asset users need to transact on chain.

Planned direction:

- deeper use in DAO/governance flows;
- contributor and ecosystem incentives;
- liquidity, lending, and launchpad mechanics.

### CYBER.sol

`CYBER.sol` is a separate Solana token:

```text
E67WWiQY4s9SZbCyFVTh2CEjorEYbhuVJQUZb3Mbpump
```

Current role:

- public market entry point via pump.fun;
- external liquidity and attention layer;
- community asset for users who discover Cyberia from Solana.

Important: `CYBER.sol` is **not currently a 1:1 wrapped version of CYBER**. Its connection to Cyberia is strategic and ecosystem-level, not a live peg.

Roadmap intent:

- bridge value and participation from Solana into Cyberia;
- give holders clearer participation/governance paths;
- connect Solana-side attention with Cyberia-side applications.

### How The Solana Token Connects To The Code

This repo does not ask you to trust a README string alone. The current connection between `CYBER.sol` and Cyberia is visible in code and deployment files:

| Item | Value / File |
| --- | --- |
| Solana `CYBER.sol` mint | `E67WWiQY4s9SZbCyFVTh2CEjorEYbhuVJQUZb3Mbpump` |
| Cyberia wrapped `CYBER.sol` ERC-20 | `0x7DcDa19Cf984ca708E5fA228AC148e7d82D508BA` |
| Cyberia bridge contract | `0xEf2c8E731006EEDD8F44f5Ea03A389635BB28f90` |
| Cyberia CYBER token contract used by bridge | `0x0D1FF5C35DB9c399f2385C8E5C166622e7b12086` |
| Laravel bridge token map | `backend/laravel/config/bridge.php` |
| EVM bridge deployment record | `crypto/hardhat/deployments/cyberia-bridge.json` |
| EVM bridge contract | `crypto/hardhat/contracts/CyberBridge.sol` |
| Solana bridge program | `crypto/anchor/programs/anchor/src/instructions/` |

Current bridge model:

```text
Solana CYBER.sol
  -> lock in Solana bridge vault
  -> backend relayer observes the event
  -> CyberBridge.releaseCyberSol(...)
  -> mint wrapped CYBER.sol on Cyberia EVM

wrapped CYBER.sol on Cyberia EVM
  -> CyberBridge.redeemCyberSol(...)
  -> burn on Cyberia EVM
  -> backend relayer releases CYBER.sol on Solana
```

This means a holder should distinguish three assets:

- **CYBER**: native Cyberia gas/network token.
- **CYBER.sol**: Solana SPL/Token-2022 market asset.
- **wrapped CYBER.sol on Cyberia**: EVM-side representation minted by the bridge contract at `0x7DcDa19Cf984ca708E5fA228AC148e7d82D508BA`.

What is live/implemented:

- the Laravel bridge configuration maps the Solana mint to the EVM wrapped token;
- the EVM bridge contract has `releaseCyberSol` and `redeemCyberSol` paths;
- the Anchor program has lock/redeem/release instructions for native/wrapped SPL bridge flows;
- the relayer scripts live in `crypto/hardhat/scripts/` and `crypto/anchor/scripts/`.

What is still trust-sensitive:

- the bridge is relayer-operated, not a fully trustless light-client bridge;
- bridge owner/relayer authority can affect mint/release operations and fees;
- RPC and explorer endpoints are project-operated infrastructure;
- users should verify deployed contract addresses on `https://explorer.cyberia.church` and the Solana mint on Solana explorers before treating any token as canonical.

Roadmap for reducing ambiguity:

- publish a single canonical bridge status page with live vault balances, wrapped supply, relayer address, and fee settings;
- add clearer contract verification links for every deployed Cyberia address;
- document owner/relayer rotation and multisig/timelock policy if/when governance hardening is added;
- expose a user-facing proof trail for each bridge transfer from Solana tx to Cyberia tx and back.

### Other Tokens

The repo also contains contribution/community token experiments such as Telegram/GitHub/X-related tokens and mintable bridge assets such as USDC/USDT wrappers. These support onboarding, testing, bridge flows, and ecosystem mechanics.

## Architecture

```text
                 pump.fun / Solana users
                         |
                         v
                 CYBER.sol liquidity
                         |
                         v
┌──────────────────────────────────────────────────────────┐
│                     Singularity                          │
│                                                          │
│  frontend/landing + frontend/jekyll  -> public narrative │
│  backend/laravel                    -> bridge/site app   │
│  frontend/ritual                    -> DEX UI            │
│  crypto/hardhat                     -> EVM contracts     │
│  crypto/anchor                      -> Solana bridge     │
│  scripts/python                     -> bots/airdrops     │
│  services/blockscout                -> explorer config   │
│  services/lisp                      -> daemon services   │
└──────────────────────────────────────────────────────────┘
                         |
                         v
              Cyberia EVM L1, chain ID 49406
                         |
                         v
        CYBER gas, apps, contracts, DAO, bridge, DEX
```

## Network Constants

```text
Network: Cyberia
RPC: https://rpc.cyberia.church
Chain ID: 49406
Native token: CYBER
Explorer: https://explorer.cyberia.church
Bridge: https://bridge.cyberia.church
Site: https://cyberia.church
DEX: https://swap.cyberia.church
CYBER.sol mint: E67WWiQY4s9SZbCyFVTh2CEjorEYbhuVJQUZb3Mbpump
```

## Repository Map

```text
singularity/
├── backend/laravel/       # Laravel 13 + Vue 3 + Inertia app
├── frontend/ritual/       # Ritual DEX, React 18 / CRA / react-app-rewired
├── frontend/landing/      # Static landing and brand pages
├── frontend/jekyll/       # Jekyll blog/static site
├── crypto/hardhat/        # EVM contracts, Hardhat 3 + viem
├── crypto/anchor/         # Solana/Anchor bridge contracts and scripts
├── crypto/quickswap-core/ # Legacy QuickSwap/Uniswap v2 core fork
├── services/blockscout/   # Blockscout fork + Cyberia docker-compose config
├── services/ipfs/         # IPFS docker-compose config
├── services/lisp/         # Common Lisp daemon/http services
├── scripts/               # Python, JS, and Lisp operational scripts/bots
├── linux/                 # Cyberia OS notes/config
└── logs/                  # Runtime logs
```

## Quick Start

Pick the component you want. This is a monorepo, not a single root application.

### Laravel app

```bash
cd backend/laravel
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run build
composer run dev
```

### Ritual DEX

```bash
cd frontend/ritual
npm install
npm start
```

### EVM contracts

```bash
cd crypto/hardhat
npm install
npx hardhat test
```

`crypto/hardhat/hardhat.config.ts` expects `DEPLOYER_PK` in the environment. Use a throwaway private key for local compile/test work if you are not deploying.

### Solana / Anchor contracts

```bash
cd crypto/anchor
npm install
anchor build
anchor test
```

### Blockscout explorer

```bash
cd services/blockscout/docker-compose
docker compose config
docker compose up -d
```

The Blockscout subtree is large and Elixir-heavy because it is an explorer fork. It is operational infrastructure for `https://explorer.cyberia.church`, not the Laravel bridge/site app.

## Development Notes

- Agent/developer instructions live in [`AGENTS.md`](AGENTS.md).
- Laravel-specific agent instructions live in [`backend/laravel/AGENTS.md`](backend/laravel/AGENTS.md).
- Do not commit `.env` files, wallet keys, cookies, or private keys.
- Generated folders such as `node_modules/`, `vendor/`, `target/`, `build/`, `_site/`, `artifacts/`, and `cache/` should not be edited manually.

## Project Activity

Public project health signals live in the repo:

- CI is defined in [`.github/workflows/ci.yml`](.github/workflows/ci.yml) for Laravel and EVM contract checks.
- Release notes should be kept in [`CHANGELOG.md`](CHANGELOG.md).
- The release process is documented in [`docs/RELEASES.md`](docs/RELEASES.md).
- Starter contributor tasks are tracked in [`docs/GOOD_FIRST_ISSUES.md`](docs/GOOD_FIRST_ISSUES.md) and can be copied into GitHub Issues with the `good first issue` label.
- Issue templates live in [`.github/ISSUE_TEMPLATE`](.github/ISSUE_TEMPLATE).

GitHub Issues and Discussions should be enabled in the repository settings so roadmap questions, user reports, and contribution tasks are visible instead of happening only in private chats.

## License

GPL-3.0. See [LICENSE](LICENSE).
