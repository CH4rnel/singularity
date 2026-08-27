# Architecture

Singularity is organized around product and runtime boundaries. Laravel is the main integration layer, but the chain, DEX, contracts, native shells, bots, and services remain independently buildable components.

## System view

```text
Users and operators
        |
        +-- cyberia.church / bridge.cyberia.church
        |      Laravel 13 + Inertia + Vue 3
        |      site, wallet, bridge, social, DAO, console, APIs
        |
        +-- swap.cyberia.church
        |      Ritual React DEX
        |
        +-- desktop / mobile / extension
        |      native shells and browser extension
        |
        +-- explorer.cyberia.church
               Blockscout official images

Laravel jobs and service daemons
        |
        +-- bridge relayers ---- Solana / TON / external EVM chains
        +-- monitoring -------- host heartbeats and remote probes
        +-- Telegram bot ------ rewards, announcements, wallet Mini App
        +-- LainOS ------------ autonomous agents and Cyberia plugin
        |
        v
Cyberia EVM L1 (chain 49406)
        |
        +-- application contracts and profiles
        +-- bridge wrappers and inventory-backed payouts
        +-- Ritual AMM pools, farms, launchpad, lending, DAO, games
```

## Repository map

| Path | Responsibility | Stack |
| --- | --- | --- |
| `backend/laravel/` | Main site, bridge, wallet surface, APIs, jobs, operator console | Laravel, Inertia, Vue |
| `frontend/ritual/` | Full DEX UI | React, CRA, react-app-rewired |
| `frontend/landing/`, `frontend/jekyll/` | Static brand and blog content | HTML, Jekyll |
| `frontend/desktop/` | Desktop shell over the live site | Electron |
| `frontend/mobile/` | Android and iOS shells | Capacitor |
| `frontend/extension/` | Non-custodial MV3 browser wallet | TypeScript, browser APIs |
| `crypto/hardhat/` | EVM contracts, scripts, deployment records | Solidity, Hardhat, viem |
| `crypto/anchor/` | Solana bridge programs and relayer scripts | Rust, Anchor, TypeScript |
| `crypto/quickswap-core/` | Legacy AMM core contracts | Solidity |
| `services/blockscout/` | Explorer deployment configuration | Docker Compose |
| `services/cyberia-node/` | Prepared follower/RPC node | polygon-edge |
| `services/telegram-bot/` | Rewards, announcers, gates, Mini App | Python |
| `services/lainos/` | Autonomous agent framework | TypeScript |
| `services/lisp/` | Daemon and HTTP services | Common Lisp |
| `game/wired/`, `game/nocarrier/` | On-chain and networked games | Godot 4 |
| `scripts/` | Operational scripts and bots | Python, JavaScript, Lisp, shell |

## Important boundaries

### Browser custody

The unified wallet derives keys and signs in the browser. Laravel may supply public RPC endpoints, price quotes, or account-linked settings, but recovery phrases and private keys must never enter a request, an Inertia property, analytics, or logs.

### Bridge admission and settlement

The bridge is relayer-operated and inventory-constrained. Capacity is claimed server-side before the wallet opens. Payout happens before any irreversible source-side burn where the corridor permits that order, and a durable payout transaction hash prevents duplicate payouts on retries.

### Three analytics systems

These answer different questions and are deliberately separate:

| System | Subject | Main documentation |
| --- | --- | --- |
| Site events | Visitors and site conversion | Operator console context |
| Product analytics | Anonymous wallet installations and activation | [Product analytics](../product-analytics.md) |
| Service monitoring | Runtime health and service usage | [Monitoring](../monitoring.md) |

### Explorer boundary

`services/blockscout/` is deployment configuration for official Blockscout images. The Blockscout Elixir application source is not vendored here.

## Sources of truth

- Network identity and public constants: [network reference](network-reference.md) and the checked-in chain configuration.
- Laravel product behavior: application code, tests, `backend/laravel/AGENTS.md`, and feature-specific docs.
- EVM deployments: `crypto/hardhat/deployments/` and verified explorer contracts.
- Bridge corridors, tokens, decimals, inventory, and relay behavior: `backend/laravel/config/bridge.php` plus contract deployment records.
- Monitoring inventory: `backend/laravel/config/monitoring.php`, never migrations.
- AI models, providers, and quotas: `backend/laravel/config/ai.php`.
