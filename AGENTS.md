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
├── crypto/hardhat/       # EVM contracts, Hardhat 3 + viem
├── crypto/anchor/        # Solana/Anchor bridge contracts and scripts
├── services/blockscout/  # Blockscout fork + Cyberia docker-compose config
├── services/lisp/        # Common Lisp daemon/http services
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

This is a Blockscout fork with Cyberia docker-compose configuration. The compose setup points backend JSON-RPC to `host.docker.internal:8545` and uses Cyberia `CHAIN_ID=49406`.

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
- Static landing change: inspect the HTML and, if possible, open it locally or run an HTML validator if available.
- Blockscout compose/proxy change: `cd services/blockscout/docker-compose && docker compose config`

If a command is unavailable or fails because of the existing repo state, say so plainly and include the command and high-signal error.
