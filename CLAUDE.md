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
| `frontend/extension/` | Manifest V3, esbuild, ethers v6 | Cyberia Wallet browser extension: own vault, EIP-1193 provider for dapps |
| `crypto/hardhat/` | Hardhat 3, Solidity, viem, ethers v6, OpenZeppelin 4.9 | EVM contracts and deployment scripts |
| `crypto/anchor/` | Anchor, Rust, Solana Web3.js, SPL Token | Solana bridge contracts and relayer scripts |
| `crypto/quickswap-core/` | Truffle-era Uniswap v2 core fork | Legacy DEX core contracts/tests |
| `services/blockscout/` | Docker Compose (official Blockscout images) | Cyberia explorer deployment config; no Elixir source vendored |
| `services/cyberia-node/` | polygon-edge/IBFT PoA, Docker Compose | Cyberia L1 second node: non-validating full/RPC follower (chainID 49406); prepared, not deployed |
| `services/ipfs/` | Docker Compose | IPFS service config |
| `services/lisp/` | Common Lisp/SBCL | Daemon and HTTP services |
| `services/telegram-bot/` | Python, python-telegram-bot, web3, SQLAlchemy | Cyberia Telegram bot: wallet rewards, chat tokens, on-chain announcers, pump.fun buy bot, whales gate |
| `services/lainos/` | TypeScript, Node, Anthropic SDK, viem | LainOS: autonomous AI agent framework (ElizaOS-like) with a Cyberia chain plugin |
| `game/wired/` | Godot 4, GDScript | Wired: 3D on-chain game; NPCs think via LainOS, world reacts to the Cyberia chain |
| `game/nocarrier/` | Godot 4, GDScript | NO CARRIER: first-person netstalking survival-horror sim (en/ru); world/UI/audio built procedurally in code; browser build connects a wallet and mints decoded anomalies as CyberiaNFTs |
| `scripts/` | Python, JS, Lisp | Airdrop bots, crawlers, price scripts, operations |
| `linux/` | Linux build notes/config | Cyberia OS artifacts |

Each component manages its own dependencies. There is no root Turbo/Nx workspace.

## Safety Rules

- Do not print or commit secrets from `.env`, wallet keypair JSON files, cookies, bot tokens, private keys, or production Blockscout env files. Mention variable names only.
- Do not edit generated dependency/runtime folders unless explicitly requested: `node_modules/`, `vendor/`, `target/`, `build/`, `_site/`, `artifacts/`, `cache/`, `test-ledger/`, `logs/`, `linux/custom-root/`, `frontend/desktop/dist/`, `frontend/mobile/www/`, `frontend/extension/dist/`, and Blockscout data volumes under `services/blockscout/docker-compose/services/*-data/`.
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

### Browser Extension — `frontend/extension/`

`frontend/extension/README.md` is authoritative. Unlike the shells this one bundles a wallet: MV3 service worker holding an AES-GCM/PBKDF2 vault in the same format as `lib/wallet/vault.ts`, EVM accounts on `m/44'/60'/0'/0/{index}` (so the site's phrase derives the same accounts), and an EIP-1193 + EIP-6963 provider injected **only** into granted origins — there is no `<all_urls>` content script, and `chrome.scripting.registerContentScripts` is re-synced from the grants. Every signing method stops at a human in a separate approval window; `PASSTHROUGH_METHODS` is the complete list of calls that reach the chain unattended. The relay applies `chrome.proxy` browser-wide (MV3 has no per-extension route) and says so in the UI; `proxy`/`privacy` are optional permissions asked for in a click.

```bash
cd frontend/extension && npm install && npm test && npm run zip
```

`npm run zip` produces `Cyberia-extension.zip` — the asset `.github/workflows/apps.yml` attaches to the `app-v*` release and `config/downloads.php` looks for. `/download/extension` is its permanent short link.

Windows/macOS installers and the Android APK come from `.github/workflows/apps.yml` (tag `app-v*` or manual dispatch); the Android build needs a local SDK otherwise. A tag also publishes the GitHub release that `/download` serves. `frontend/mobile/www/` and `frontend/desktop/dist/` are generated; `frontend/mobile/android/` and `ios/` are committed because they carry the deep-link intent filters and icons.

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
- Unified multichain wallet (`/wallet`): one BIP-39 seed phrase derives an EVM account (Cyberia 49406, Robinhood 4663, BNB 56, Base 8453 — all the *same* address), a Solana account, a native Monero account and BIP-84 Bitcoin and Litecoin accounts (`resources/js/lib/wallet/`, one adapter per chain in `chains.ts`, screens in `components/wallet/`, page `pages/Wallet.vue`, styling in `resources/css/wallet.css`). Non-custodial — the phrase is generated in the browser, AES-GCM encrypted under the user's password in localStorage, and never sent to Laravel; the route only passes a public Solana RPC URL, USD quotes and the saved XMR payout address. Balances, history, fee quotes and signing are all browser-side; Monero is receive-only (no view-key scanner). The flow is welcome → risk notice → hold-to-reveal seed → backup check → vault password → portfolio, with send guarded by a plain-language sentence and hold-to-sign. Adapters may declare `fetchFees`, `fetchHistory` and `awaitOutcome`; a chain that declares none says so in the UI rather than showing an empty list. Adding an EVM network is one `evmChain({...})` entry pointing at an existing `lib/evmChains.ts` row plus a `mark` (hue in `wallet.css`, shape, two-letter tag) on that entry — `NetworkMark.vue` reads the mark off the registry. In-app history needs a keyless index — Blockscout chains (Cyberia, Robinhood) get it, Etherscan-family ones (BNB, Base) declare `historyNote` instead. Derivation is pinned to published vectors in `tests/Frontend/` — run `npm run test:frontend`.
- Bitcoin family: `lib/wallet/utxo.ts` with `bech32.ts` and `base58check.ts`, no third-party Bitcoin package. P2WPKH only — BIP-143 sighash, DER low-S signatures, largest-first coin selection, dust folded into the fee, broadcast through an Esplora HTTPS API (mempool.space for BTC, litecoinspace.org for LTC; a browser cannot reach an Electrum server). Legacy/P2SH accounts derive and receive but are not signed. `tests/Frontend/WalletUtxoTest.mjs` pins BIP-84/BIP-44 addresses, BIP-173/350 bech32 and the BIP-143 digest *and* signature — keep it green before touching anything in that path.
- User-added networks: `lib/wallet/customChains.ts` + `WalletAddNetwork.vue` (reachable from the portfolio, both network pickers and Security). An EVM chain needs a name, chain id, HTTPS RPC and symbol; a Bitcoin fork needs a SLIP-44 coin type, address type, prefix (bech32 HRP or base58 version byte — the design mock omitted this and there is no address without it) and an Esplora API. Records live in localStorage; adapters are rebuilt from them through `setCustomWalletChains()`, so removing a network forgets its endpoint and never the account. `walletChains()` = built-ins + custom, `WALLET_CHAINS` = built-ins only. Custom chains render violet + dashed and carry an unverified-endpoint warning on receive and above the send form.
- ERC20 tokens on EVM networks (`lib/wallet/erc20.ts`, `tokenList.ts`, `components/wallet/TokenList.vue`). Discovery is the chain's own keyless Blockscout `tokenlist` — one call returning symbol, **decimals** and balance, which is why nothing here hardcodes decimals (Cyberia's USDC/USDT are 6, not 18). Chains without Blockscout declare `tokensNote` and take contracts by hand; a hand-added token is read on-chain before it is stored, kept at zero balance, and hidden explicitly, while an indexed token at zero is dropped. Tokens live inside the network screen (they share its address and gas) and roll into that network's portfolio card. Sending: `transfer()` with a live `estimateGas` capped at `ERC20_TRANSFER_GAS_CAP` (120k) — the cap is what the fee quote promises, so a transfer that would exceed it is refused rather than signed for more than shown. Fee quotes are keyed per asset (`wallet.feesFor(chain, token)`), and the send screen separates "not enough token" from "not enough gas" because they are different balances. The same rows also read as a cross-network roll-up (`WalletTokens.vue`, portfolio → Tokens) whose rows open one token in full (`WalletToken.vue`: balance, value, decimals, contract, indexed vs added by hand). No price chart — the wallet only ever has point-in-time pool quotes, so a curve would be invented.
- Analytics (`components/wallet/WalletAnalytics.vue`, portfolio → Analytics) is computed in the browser from balances and quotes the page already holds: allocation per holding, coins vs tokens, and a seven-day transfer histogram from the loaded histories. Unpriced holdings are counted and named, never folded in at zero; the histogram says how many networks have a browser-readable index. There is no value-over-time curve because nothing stores this vault's history.
- Accounts (`lib/wallet/accounts.ts`, `WalletAccounts.vue`, `WalletImportAccount.vue`, switcher chip on the portfolio). Four kinds, and the difference is what the one backup covers: `seed` (a BIP-44 account off the vault phrase — every network at once), `phrase` (a second phrase imported whole, its own root, its own backup), `key` (one private key, one chain), `watch` (an address, `capabilities.send` forced false). The account number lands where each ecosystem puts it — address segment on EVM, account segment on Solana and the Bitcoin family, nowhere on Monero (`chain.path(index)`, `WalletKeySource` in `keys.ts`); `deriveAccounts(phrase, record)` and `sourceFor()` are the only two places the kinds are distinguished. **The vault now seals a document, not a bare phrase** (`VaultContents` = phrase + accounts + activeId, record `version: 2`): an imported key must be no more readable at rest than the seed. `unsealVault()` returns a `reseal` closure over the AES key so adding an account never re-prompts for the password, and a v1 record (bare phrase) is still read and migrated lazily. Switching accounts clears every chain read, because all of it belonged to the previous one.
- End-to-end encrypted chat between wallets, addressed by EVM address (`lib/wallet/chatCrypto.ts` + `chat.ts`, `components/wallet/WalletChat.vue`, `Api\WalletChatController`, routes `/api/wallet/chat/{nonce,verify,keys,keys/{address},messages}`, `config/wallet.php`, `wallet:chat-prune`). Laravel relays ciphertext it has no key for: the conversation key is an ECDH between the two wallets' *messaging* keys, and each of those is derived one-way from an account's EVM private key (`evmChatKey`) — separate from the key that signs transactions, needing no extra backup, absent on a `watch` account. An address is a hash, so keys are published to a directory with an EIP-191 signature naming address **and** key; the browser re-verifies every record and pins it on first sight, so the relay can withhold a key but never substitute one (`chatKeyStatement` must stay byte-identical to `WalletChatController::keyStatement`). Messages are AES-GCM with id/sender/recipient/timestamp as AAD, padded to 256-byte blocks, and cached on the device as ciphertext. Stated limits, in the UI as well as here: no forward secrecy (static key), and the relay sees who talks to whom and when. `sent_at`/`issued_at` are string columns because a signature covers those exact characters. Covered by `tests/Frontend/WalletChatTest.mjs` and `tests/Feature/WalletChatRelayTest.php`.
- The six destinations (`pages/Wallet.vue` `TABS`/`TAB_OF`): wallet, chat, feed, launchpad, DAO, Lain. Tokens, analytics, accounts, network detail and security are places *inside* the wallet tab, reached from the portfolio — they are ways of reading your holdings. Messages was one of those for exactly one release and it was wrong: a correspondence is not a way of reading holdings, and nobody looks for it inside them, so it is a tab (~65px a label; the mono tracking is dropped below 390px and the font shrinks below 340px) with the unread badge on it. The desktop rail lists all eleven. `/wallet` is also a top-level link in the site header (`components/web3/nav.ts` `walletItem`, rendered as a link rather than a menu group) and in the dashboard sidebar.
- Feed, DAO and profile in the wallet are **read-only, and that is structural**: the wallet has no session (`Api\WalletSocialController`, `GET /api/wallet/{feed,dao,dao/proposals/{id},profile/{address}}`, client in `lib/wallet/social.ts`). There is nobody to post, comment or vote as, so those screens say so and link out to the site rather than drawing controls that would fail. Nothing there is new disclosure — the same fields the public feed/DAO/profile pages already render. DAO bars are drawn from **voting power**, never voter count (`tally()`); an address nobody has claimed is a valid profile answer, not an error.
- Token sites live in IPFS (`IpfsService` + `LaunchpadSiteService`, `config/ipfs.php`, column `launchpad_tokens.ipfs_cid`, command `launchpad:pin-sites`, node in `services/ipfs/`). An uploaded page is pinned wrapped as `index.html` (so the bare CID renders as a site) and the CID is its permanent address: the API returns `ipfs_cid`/`ipfs_uri`/`ipfs_url`, `site_url` prefers the gateway over a path on this host when no subdomain was claimed, and the hosted copy declares the IPFS one canonical via a `Link` header. Pinning never blocks an upload — a down node just leaves `ipfs_cid` null for the hourly `launchpad:pin-sites` to pick up (`--force` re-pins everything). In prod `IPFS_API_URL` must resolve from inside the Laravel container.
- Launchpad in the wallet (`lib/wallet/launchpad.ts`, `WalletLaunchpad.vue`) reads LaunchpadNative on Cyberia browser-side through `lib/launchpadChains.ts`. It is a *fair launch* — the coin that paid for it is burned into locked liquidity — so there are no tiers, no allocation, no vesting and no cap, and the screen has no vocabulary for any of them. Buying is a swap, which this wallet does not do, so the detail links out to the DEX. `poolQuote()` is pure and pinned: reserves are ordered by token address, and reading `token0` backwards inverts every price on the screen.
- `$LAIN` holders' room in the wallet (`components/wallet/WalletLain.vue`, `Api\WalletLainController`, routes `/api/wallet/lain/{nonce,verify,chat}`, prompt in `LainChatService::replyForHolder`): a chat with Lain gated on holding ≥ `services.lain.minimum_share_bps` (10%) of the live `$LAIN` supply. The share is read from the contract in the browser (`erc20TotalSupply` + `readToken`) before anything is sent, then the wallet signs a server-composed EIP-191 challenge (one-shot nonce; wording differs from the login message so the proof cannot be replayed at `/api/wallet/verify`); the recovered address's share is re-checked on every turn, so an aged proof or a sold balance shuts the room. The transcript stays in localStorage (`lib/wallet/lainChat.ts`), goes up as capped context only, and is cleared with the vault — Laravel persists nothing. `wallet.signMessage()` is the only place a wallet key is used without a transaction. Covered by `tests/Feature/WalletLainRoomTest.php`.
- Wallet USD quotes: `WalletPriceService` (CYBER from the DexScreener CYBER.sol feed; ETH/BNB/SOL/XMR/BTC/LTC from CoinGecko, deduplicated because Robinhood and Base both pay gas in ETH; cached 5 min under `wallet.prices.v2` — bump the key when the payload shape changes) feeds the `/wallet` Inertia prop and `GET /api/wallet/prices`. It also returns `tokens.cyberia` — per-contract USD from the DEX pool graph via `CyberiaPrices` + the indexer's `dex_pools` table, the same numbers `/tokens` and `/crm/analytics` show. A price that cannot be read is `null`, never `0` — the UI renders "—" and marks the portfolio total partial. User-added networks and tokens on chains with no pool graph are unpriced.
- App distribution (`/download`, `DownloadController` + `AppDownloadService`, `config/downloads.php`, `resources/js/pages/Download.vue`, `lib/downloadMessages.ts`): the page reads the newest `app-v*` GitHub release and offers the file for the visitor's platform, in ru/en. Two addresses reach the same build — the release asset (which also carries version, date and size) and the permanent `/releases/latest/download/<file>`, which is why installer names carry no version. Three states, because they read differently: `published` (offer exactly the files that release carries — a missing APK means no Android card, never a dead button), `none` (GitHub answered, no `app-v*` release exists yet, so nothing is offered), `unknown` (GitHub unreadable — the permanent URLs are shown without a version, the only case where a link is not confirmed). `/download/{windows,macos,linux,android}` redirects straight to that platform's current file — the link to paste into a message. Covered by `tests/Feature/DownloadPageTest.php`.
- Profile page (`/profile`): per-user CEX-style deposit addresses (BTC/LTC/YTN P2PKH from per-chain HD seeds, XMR integrated address; `UserDepositAddressService`, operator command `bridge:user-deposit`) plus on-chain nicknames/achievements via the `CyberiaProfile` contract at `0xa9101ee859850c037b0867156b3535F78A387C0d` (`crypto/hardhat/contracts/CyberiaProfile.sol`, Laravel `ProfileOnchainService`/`AchievementService`, override via `CYBERIA_PROFILE_ADDRESS`).

## Verification

Use the smallest relevant check:

- Laravel PHP: `cd backend/laravel && vendor/bin/pint --dirty --format agent && php artisan test --compact`
- Laravel frontend: `cd backend/laravel && npm run lint:check && npm run types:check && npm run build`
- Ritual: `cd frontend/ritual && npx eslint <changed files>` or the deploy build flow above
- Desktop shell: `cd frontend/desktop && npm test` (add `npm run pack` when the Electron main process changed)
- Mobile shell: `cd frontend/mobile && npm test && npm run sync`
- Browser extension: `cd frontend/extension && npm test && npm run build`
- Hardhat: `cd crypto/hardhat && npx hardhat test`
- Anchor: `cd crypto/anchor && anchor test` or at least `anchor build`
- NO CARRIER: `cd game/nocarrier && godot4 --headless --import . && godot4 --headless --path . -s tests/smoke.gd`
- Jekyll: `cd frontend/jekyll && bundle exec jekyll build`
- Static landing: inspect HTML and validate if a validator is available
- Blockscout compose/proxy: `cd services/blockscout/docker-compose && docker compose config`
