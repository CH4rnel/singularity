# Component Guide

Use this page to find the smallest part of Singularity that owns the behavior you want to change. Start from the product boundary, then follow the component README and nearest `AGENTS.md`.

## User-facing products

| Product | Primary path | Stack | First local command |
| --- | --- | --- | --- |
| Main site, wallet, bridge, profile, DeFi and APIs | `backend/laravel/` | Laravel 13, Inertia, Vue 3 | `composer run dev` |
| Ritual DEX | `frontend/ritual/` | React 18, react-app-rewired | `npm start` |
| Landing pages | `frontend/landing/` | Static HTML/CSS/JS | Serve the directory locally |
| Blog | `frontend/jekyll/` | Jekyll | Follow its `Gemfile` and `_config.yml` |
| Desktop shell | `frontend/desktop/` | Electron | `npm start` |
| Mobile shell | `frontend/mobile/` | Capacitor | `npm run sync` |
| Browser wallet extension | `frontend/extension/` | MV3, TypeScript | `npm run build` |
| Wired | `game/wired/` | Godot 4 | Open `project.godot` |
| NO CARRIER | `game/nocarrier/` | Godot 4 | Open `project.godot` |
| Game SDK | `game/SDK/` | TypeScript | Read `game/SDK/docs/README.md` |

The Laravel subtree has its own `AGENTS.md`; read it in full before changing Laravel, Vue, Inertia, routes, bridge behavior, wallet code, or tests.

## Contracts and chain integrations

| Area | Primary path | Source of truth |
| --- | --- | --- |
| Cyberia EVM contracts | `crypto/hardhat/contracts/` | Contract source plus `crypto/hardhat/deployments/` |
| Deployment and relay scripts | `crypto/hardhat/scripts/` | Script source and checked-in deployment records |
| Solana programs | `crypto/anchor/programs/` | Anchor program source |
| Solana client and relayer scripts | `crypto/anchor/scripts/` | Script source and program configuration |
| Legacy AMM core | `crypto/quickswap-core/` | Solidity source in that package |
| TON integration | `crypto/ton/` | Package source and local README/config |
| Yenten integration | `crypto/yenten/` | Package source and local README/config |

For public identifiers and wallet configuration, use the [Network reference](network-reference.md). Do not copy an address from prose when a deployment record or verified explorer contract is available.

## Services

| Service | Path | Purpose |
| --- | --- | --- |
| Block explorer configuration | `services/blockscout/` | Docker Compose deployment of official Blockscout images |
| IPFS | `services/ipfs/` | IPFS service configuration |
| Telegram bot | `services/telegram-bot/` | Rewards, announcements, gates, market signals, and Mini App |
| LainOS | `services/lainos/` | Autonomous agent framework and Cyberia chain plugin |
| Lisp services | `services/lisp/` | Common Lisp daemons and HTTP services |

Operational provisioning and production runbooks are maintained in Cyberia's internal documentation set rather than the public developer manual.

## Choose the entry point

### Change a Laravel page

1. Find the route in `backend/laravel/routes/`.
2. Follow it to the controller or Inertia render call.
3. Find the Vue page in `backend/laravel/resources/js/pages/`.
4. Reuse routes from `@/routes` and actions from `@/actions`.
5. Add or update the narrow PHP and frontend tests that pin the behavior.

### Change a wallet feature

1. Start in `backend/laravel/resources/js/components/wallet/` for screens.
2. Use `backend/laravel/resources/js/lib/wallet/` for chain-independent wallet logic.
3. Use `chains.ts` and the relevant chain adapter for network behavior.
4. Keep recovery phrases, private keys, signing, balances, and history browser-side.
5. Run the matching tests under `backend/laravel/tests/Frontend/`.

### Change the bridge

1. Identify the corridor in `backend/laravel/config/bridge.php`.
2. Trace admission and inventory through the Laravel bridge services.
3. Trace the on-chain leg to `crypto/hardhat/` or `crypto/anchor/`.
4. Preserve token decimals and integer smallest-unit arithmetic across both sides.
5. Test configuration, request state transitions, capacity, and the relevant contract or script.

### Change a contract

1. Find the deployed contract and record in `crypto/hardhat/deployments/`.
2. Edit the Solidity source and its test together.
3. Compile and run the narrow Hardhat tests locally.
4. Update frontend ABI/address consumers and documentation when the public interface changes.
5. Keep deployment as a separate, explicitly authorized workflow.

### Change an application shell

1. Confirm whether the behavior belongs to the shared live site or to the shell itself.
2. Make site behavior changes in Laravel; keep shell changes in `frontend/desktop/`, `frontend/mobile/`, or `frontend/extension/`.
3. Run the shell's own tests and build command.
4. Confirm its expected artifact without editing generated output by hand.
