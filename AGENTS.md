# AGENTS.md

Guidelines for AI agents working on Singularity.

---

## Project Snapshot

Singularity is the Cyberia monorepo. It contains the Cyberia Laravel bridge/site, the Ritual DEX frontend, static landing/blog pages, EVM and Solana contracts, Blockscout deployment config, daemon services, and operational scripts.

Cyberia network constants used across the repo:

- RPC: `https://rpc.cyberia.church`
- Chain ID: `49406`
- Native token: `CYBER`
- Explorer: `https://explorer.cyberia.church`
- Bridge: `https://bridge.cyberia.church`
- Site: `https://cyberia.church`
- DEX: `https://swap.cyberia.church`
- CYBER.sol mint: `E67WWiQY4s9SZbCyFVTh2CEjorEYbhuVJQUZb3Mbpump`

---

## Project Components

```text
singularity/
├── backend/laravel/      # Laravel 13 + Vue 3 + Inertia app, bridge UI/backend
├── frontend/ritual/      # Ritual DEX, React 18 / CRA / react-app-rewired
├── frontend/landing/     # Static HTML landing/brand pages
├── frontend/jekyll/      # Jekyll Cyberia blog/static site
├── frontend/desktop/     # Cyberia desktop app (Electron shell over the live site)
├── frontend/mobile/      # Cyberia mobile app (Capacitor shell, Android + iOS)
├── crypto/hardhat/       # EVM contracts, Hardhat 3 + viem
├── crypto/anchor/        # Solana/Anchor bridge contracts and scripts
├── crypto/quickswap-core/ # Legacy QuickSwap/Uniswap v2 core contracts
├── services/blockscout/  # Cyberia explorer: Blockscout docker-compose deploy config (official images)
├── services/cyberia-node/ # Cyberia L1 second node (polygon-edge follower/RPC); prepared, not deployed
├── services/ipfs/        # IPFS docker-compose config
├── services/lisp/        # Common Lisp daemon/http services
├── services/telegram-bot/ # Cyberia Telegram bot (Python): rewards, announcers, whales gate
├── services/lainos/      # LainOS: autonomous AI agent framework (TypeScript), Cyberia chain plugin
├── game/wired/           # Wired: 3D on-chain game (Godot 4), NPCs powered by LainOS
├── game/nocarrier/       # NO CARRIER: netstalking survival-horror sim (Godot 4, en/ru, optional on-chain NFT sealing)
├── scripts/              # Python, JS, and Lisp operational scripts/bots
├── linux/                # Cyberia OS build notes/config
└── logs/                 # Runtime logs
```

Do not rely on older docs that refer to `hardhat/` at the repo root; contracts now live under `crypto/`.

---

## General Rules

- Keep changes focused and atomic. Avoid unrelated formatting churn.
- Do not overwrite user changes. Check `git status --short` before and after edits.
- Prefer existing local patterns and helpers over introducing new abstractions.
- Do not edit generated dependency folders (`node_modules/`, `vendor/`, `target/`) or generated build outputs unless the user explicitly asks for deploy/build artifacts.
- Always verify changes with the narrowest useful command. If verification cannot pass because of known repo state, report the exact blocker and the command used.
- For production/deploy requests, confirm the expected artifact exists after building, especially `frontend/ritual/build/index.html` and `frontend/ritual/build/static/`.
- When drafting posts for the user, assume there is no character limit unless the user explicitly sets one.

LLM orientation:

- Treat `AGENTS.md` as the cross-repo source of truth, and nested `AGENTS.md` files as authoritative inside their subtree.
- `CLAUDE.md` mirrors this guidance for Claude Code; keep it in sync when changing broad repo instructions.
- Root `README.md` is user-facing and currently less complete than these agent notes. Do not copy stale README paths such as root `hardhat/`.
- Avoid broad filesystem walks through generated/runtime trees. Prefer `rg --files` and exclude `node_modules/`, `vendor/`, `target/`, `build/`, `_site/`, `artifacts/`, `cache/`, `test-ledger/`, `logs/`, and `linux/custom-root/` unless the task is specifically about those paths.
- Never print secrets from `.env`, wallet keypair JSON files, cookies, bot tokens, private keys, or Blockscout production env files. Report variable names, not values.

Important generated/runtime paths:

- Dependency folders: `backend/laravel/vendor/`, `**/node_modules/`
- Build outputs: `frontend/ritual/build/`, `frontend/jekyll/_site/`, `frontend/desktop/dist/`, `frontend/mobile/www/`, `frontend/mobile/android/app/build/`
- Contract outputs: `crypto/hardhat/artifacts/`, `crypto/hardhat/cache/`, `crypto/anchor/target/`, `crypto/anchor/test-ledger/`
- Blockscout data volumes under `services/blockscout/docker-compose/services/*-data/`, `services/blockscout/docker-compose/services/dets/`, and Redis dumps
- OS/rootfs artifacts under `linux/custom-root/`

---

## Laravel Backend

See [`backend/laravel/AGENTS.md`](backend/laravel/AGENTS.md) for Laravel Boost and Laravel-specific rules. Those instructions are authoritative for Laravel work.

Stack:

- PHP 8.3+
- Laravel 13
- Inertia v3 + Vue 3
- Fortify + Sanctum
- Wayfinder
- Pest 4
- Laravel Pint
- Tailwind CSS v4

Common commands:

```bash
cd backend/laravel
composer install
npm install
composer run dev
npm run build
composer run ci:check
php artisan test --compact
vendor/bin/pint --dirty --format agent
```

Notes:

- Use Wayfinder route helpers from `@/routes` and `@/actions` instead of hardcoded Laravel route URLs when wiring Laravel Inertia pages.
- Vue pages live in `backend/laravel/resources/js/pages`.
- The bridge welcome page is intentionally layoutless in `resources/js/app.ts`.
- `npm run types:check` may expose pre-existing TypeScript issues in old pages; do not hide new errors inside that noise.
- Progression (XP, levels, daily streaks, quests) lives in `App\Services\GamificationService` with rules in `config/gamification.php`, surfaced on `/profile` and `/leaderboard`. XP is paid through the append-only `xp_entries` ledger keyed by `(source, reference)`; the browser may only report visits/page views, while swaps, liquidity, bridges and governance are credited from ground truth by `gamification:sync`. Do not pay value XP from client-reported events.
- Retention analytics (DAU/WAU/MAU, new vs returning, weekly cohorts, progression health) live in `App\Services\UserAnalyticsService` and render on `/crm/analytics` alongside the existing funnel.
- The unified multichain wallet (`/wallet`, `resources/js/lib/wallet/`, screens in `resources/js/components/wallet/`) is non-custodial and lives entirely in the browser: one BIP-39 seed phrase, one chain adapter per network in `chains.ts` (every EVM network — Cyberia, Robinhood, BNB, Base — on BIP-44 coin type 60 and therefore sharing one address; Solana and Monero on SLIP-0010; Bitcoin and Litecoin on BIP-84), the phrase AES-GCM encrypted in localStorage. Balances, history, fee quotes and signing are browser-side too; Laravel only serves a public Solana RPC URL, cached USD quotes and the saved XMR payout address. Never route a seed, phrase or private key through Laravel, an Inertia prop or a log line. Derivation vectors, amount formatting, QR encoding and history parsing are covered by `npm run test:frontend` (`tests/Frontend/*Test.mjs`, run with the `@/` alias hook).
- The Bitcoin family is built and signed in-browser (`lib/wallet/utxo.ts` plus `bech32.ts`/`base58check.ts` — no third-party Bitcoin dependency): P2WPKH only, BIP-143 sighash, coin selection largest-first, dust folded into the fee, broadcast through an Esplora API (mempool.space, litecoinspace.org). A browser cannot speak the Electrum protocol, so every UTXO endpoint must be a CORS-open HTTPS Esplora root. Everything in that file is pinned to the BIPs' own vectors in `tests/Frontend/WalletUtxoTest.mjs`; do not change an address, digest or signature path without those staying green. Legacy/P2SH accounts derive and receive but are deliberately not signed.
- ERC20 tokens on EVM networks live in `lib/wallet/erc20.ts` and `tokenList.ts`, surfaced by `components/wallet/TokenList.vue` inside the network screen — a token shares the network's account, address and gas coin, so it is never a card of its own in the portfolio. Never hardcode a token's decimals: they come from the chain's Blockscout `tokenlist` index or from `decimals()` on the contract, because Cyberia's own USDC and USDT are six-decimal and a guessed eighteen is off by a factor of a trillion. Sending estimates gas but never exceeds `ERC20_TRANSFER_GAS_CAP`, which is the figure the fee quote and the signing sentence promised. Fee quotes are per asset — `wallet.feesFor(chain, token)` — and "not enough token" and "not enough gas" are separate states, since holding USDC with no CYBER is the failure people actually hit.
- Users can add networks themselves (`lib/wallet/customChains.ts`, screen `WalletAddNetwork.vue`): an EVM chain by chain id + HTTPS RPC, or a Bitcoin fork by SLIP-44 coin type + address type + prefix + Esplora API. The record is stored in localStorage and the account is re-derived from the vault, so removing a network forgets an endpoint and never coins. Custom chains are layered over the built-in registry via `setCustomWalletChains()`; `walletChains()` is the combined list and `WALLET_CHAINS` is the built-ins only. Every user-added network is marked `custom` and drawn violet + dashed, and its unverified-endpoint warning appears on receive and above the send form.
- Wallet UI rules, in order of importance: a number that could not be read renders as "—" and never as `0`; every signature is preceded by one plain-language sentence and a hold-to-sign control; the seed phrase appears only behind a held finger or a re-entered password, never passively; networks are encoded by hue **plus** shape **plus** a two-letter tile carried on the chain adapter's own `mark` (square = EVM and every square shares one address, circle = Solana, diamond = Monero, soft square = Bitcoin family, violet + dashed = added by the user), and transaction status uses a separate amber/green/red family. The portfolio groups networks by that family. The surface stays dark in either site theme (`resources/css/wallet.css`, `cw-` namespace) and every string is bilingual through `walletMessages`.

---

## Ritual DEX Frontend

Path: `frontend/ritual/`

Stack:

- React 18
- Create React App via `react-app-rewired`
- TypeScript 4.1-era project with newer dependency types
- Material UI v4
- Ethers v5, Web3Modal, QuickSwap-derived swap code

Common commands:

```bash
cd frontend/ritual
npm install
npm start
npm run build
npm run test
npm run ipfs-deploy
```

Known build reality:

- Plain `npm run build` currently runs ESLint and TypeScript gates and can fail on existing lint/type drift.
- If the user explicitly wants a deployable artifact without code fixes, use:

```bash
cd frontend/ritual
DISABLE_ESLINT_PLUGIN=true TSC_COMPILE_ON_ERROR=true npm run build
```

- After any Ritual build, verify:

```bash
test -f build/index.html
test -d build/static
find build/static -maxdepth 2 -type f | wc -l
```

- `npm run ipfs-deploy` uses `ipfs-deploy build`. It may fail on modern Node with `RequestInit: duplex option is required when sending a body`; report that exactly rather than pretending deployment succeeded.
- Be careful with `frontend/ritual/build/`: it is an artifact directory. Only modify or deploy it when the user explicitly asks for build/deploy work.

---

## Native App Shells

Paths: `frontend/desktop/` (Electron), `frontend/mobile/` (Capacitor)

Both ship the Laravel site as an installable app. Neither bundles the frontend: they render `CYBERIA_APP_URL` (default `https://cyberia.church`) in a native window/WebView, so a production deploy is also an app update. The per-directory `README.md` files are authoritative.

**Both apps are the Cyberia wallet first.** They launch on `CYBERIA_APP_PATH` (default `/wallet`), and inside a native shell that route drops the site header and footer and fills the frame (`resources/js/layouts/NativeShellLayout.vue`, chosen in `app.ts` from `isNativeShell()`); everything else in the shell keeps the normal site chrome, and the wallet's masthead links back to the site. `/wallet` is a public route so the app works straight after install — the keys are browser-side and were never the server's to gate; signing in only adds the XMR payout binding. `CYBERIA_APP_PATH` is validated as a same-origin absolute path: a full URL or `//host` falls back to `/wallet` instead of repointing the app at another origin.

```bash
cd frontend/desktop
npm install
npm test
npm run dist:linux      # AppImage + deb; dist:win needs Wine, dist:mac needs macOS

cd frontend/mobile
npm install
npm test
npm run sync            # regenerate www/ and copy config into android/ and ios/
npm run android:apk     # needs a local Android SDK + JDK 21
```

- Windows/macOS installers and the Android APK are built by `.github/workflows/apps.yml` (tag `app-v*` or manual dispatch).
- `frontend/mobile/android/` and `frontend/mobile/ios/` are committed: they hold the `cyberia://` scheme, the App Links intent filters, and the generated icons.
- Deep links only resolve to the apps once `APP_ANDROID_SHA256_FINGERPRINT` / `APP_IOS_APP_ID` are set in the Laravel `.env`; both `/.well-known/` association files 404 until then.
- The site detects the shells from the `CyberiaDesktop/` and `CyberiaMobile/` user-agent suffixes (`backend/laravel/resources/js/lib/native.ts`).

---

## Static Frontends

### Landing Pages

Path: `frontend/landing/`

- Plain static HTML/CSS files.
- No build step is required for `index.html` and the brand identity HTML files.
- Keep styling inline with the existing editorial/brand-document look.
- External Cyberia links should use `target="_blank"` and `rel="noopener noreferrer"`.

### Jekyll Blog

Path: `frontend/jekyll/`

Common commands:

```bash
cd frontend/jekyll
bundle install
bundle exec jekyll serve
bundle exec jekyll build
```

- Posts are in `_posts/`.
- Do not edit `_site/` unless the user explicitly asks for generated static output.

---

## Contracts

### EVM / Hardhat

Path: `crypto/hardhat/`

Stack:

- Hardhat 3
- viem
- TypeScript
- OpenZeppelin contracts
- Foundry-compatible Solidity tests

Common commands:

```bash
cd crypto/hardhat
npm install
npx hardhat test
npx hardhat test solidity
npx hardhat test nodejs
```

- Deployment scripts/modules live under Hardhat project folders such as `ignition/` and scripts.
- `hardhat.config.ts` requires `DEPLOYER_PK` at import time. If running local compile/test commands without a real deploy key, use a throwaway 32-byte private key in the environment rather than reading or printing `.env`.
- Mint/burn administration for deployed Cyberia ERC20s is centralized in `scripts/token-admin.ts`, backed by `deployments/cyberia-tokens.json`. Bridge-specific one-shot scripts are `scripts/relay-mint.ts` and `scripts/relay-burn.ts`.
- Never commit private keys or `.env` secrets.

### Solana / Anchor

Path: `crypto/anchor/`

Stack:

- Anchor
- Solana Web3.js
- SPL Token
- Rust + TypeScript tests/scripts

Common commands:

```bash
cd crypto/anchor
npm install
anchor build
anchor test
npm run lint
npm run lint:fix
```

- `test-ledger/` and `target/` are generated/runtime artifacts.
- Scripts include bridge initialization and relayer helpers in `scripts/`.

---

## Blockscout / Explorer

Path: `services/blockscout/`

This is **deployment configuration only** — Cyberia docker-compose files that run the official Blockscout images (`ghcr.io/blockscout/blockscout`, pinned to a tagged release; `backend`/`nft_media_handler` are pinned to `11.0.0`). The explorer's Elixir source is **not** vendored here. The compose setup points backend JSON-RPC to `host.docker.internal:8545` and uses Cyberia `CHAIN_ID=49406`.

Common commands:

```bash
cd services/blockscout/docker-compose
docker compose up -d
docker compose down
```

Important files:

- `services/blockscout/docker-compose/docker-compose.yml`
- `services/blockscout/docker-compose/services/nginx.yml`
- `services/blockscout/docker-compose/proxy/default.conf.template`
- `services/blockscout/docker-compose/proxy/explorer.conf.template`

Be careful editing compose files: some paths are production-host-specific, e.g. `/root/singularity/...`.

---

## Lisp Services

Paths:

- `services/lisp/` for daemon/http service code
- `scripts/lisp/` for small script experiments

Common commands:

```bash
sbcl --noinform --load services/lisp/daemon.lisp
sbcl --noinform --load services/lisp/run-server.lisp
```

- Modules live in `services/lisp/modules/`.
- Logs are written under `logs/`.
- Some service helpers call Python scripts for price data.

---

## Operational Scripts

Path: `scripts/`

- `scripts/python/` contains Telegram/GitHub/X airdrop and crawler bots.
- `scripts/js/price.js` contains JS price tooling.
- `.env` files and cookie files in scripts may contain secrets. Do not print or commit sensitive values.

Python setup:

```bash
cd scripts/python
python3 -m venv .venv
. .venv/bin/activate
pip install -r requirements.txt
```

JS setup:

```bash
cd scripts/js
npm install
node price.js
```

---

## Verification Checklist

Use the smallest relevant checks:

- Laravel PHP change: `cd backend/laravel && vendor/bin/pint --dirty --format agent && php artisan test --compact`
- Laravel frontend change: `cd backend/laravel && npm run lint:check && npm run types:check && npm run build`
- Ritual change: `cd frontend/ritual && npx eslint <changed files>`; for deploy artifact use the build command noted above.
- Hardhat change: `cd crypto/hardhat && npx hardhat test`
- Anchor change: `cd crypto/anchor && anchor test` or at least `anchor build`
- Jekyll change: `cd frontend/jekyll && bundle exec jekyll build`
- Desktop shell change: `cd frontend/desktop && npm test`; add `npm run pack` when the Electron main process changed.
- Mobile shell change: `cd frontend/mobile && npm test && npm run sync`
- Static landing change: inspect the HTML and, if possible, open it locally or run an HTML validator if available.
- Blockscout compose/proxy change: `cd services/blockscout/docker-compose && docker compose config`

If a command is unavailable or fails because of the existing repo state, say so plainly and include the command and high-signal error.
