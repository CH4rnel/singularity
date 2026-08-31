# Local Development

Singularity is a monorepo, not a single root application. Install and run only the component you are changing.

## Prerequisites

Depending on the component, you will need PHP 8.3+, Composer, Node.js 22+, npm, Docker, Rust/Anchor, or Godot 4. Start with the component README because native and contract toolchains have additional requirements.

## Laravel application

```bash
cd backend/laravel
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
composer run dev
```

Useful checks:

```bash
composer run ci:check
php artisan test --compact
vendor/bin/pint --dirty --format agent
npm run test:frontend
npm run build
```

The nested `backend/laravel/AGENTS.md` is authoritative for Laravel and Vue changes.

## Ritual DEX

```bash
cd frontend/ritual
npm install
npm start
```

Production verification must leave both `frontend/ritual/build/index.html` and `frontend/ritual/build/static/` present.

## EVM contracts

```bash
cd crypto/hardhat
npm install
npx hardhat test
```

The Hardhat configuration expects `DEPLOYER_PK`. Use a throwaway local key for compile and test work. Never place a funded deployment key in a command, tracked file, or test fixture.

## Solana programs

```bash
cd crypto/anchor
npm install
anchor build
anchor test
```

Contract outputs under `target/` and the local `test-ledger/` are generated and must not be edited manually.

## Documentation

```bash
cd docs
npm install
npm run dev
```

Before committing documentation changes:

```bash
cd docs
npm run build
```

The production output is generated under `docs/.vitepress/dist/` and is not committed.

## Blockscout deployment configuration

```bash
cd services/blockscout/docker-compose
docker compose config
```

Only run `docker compose up` when you intentionally want to start the explorer stack. Configuration validation is sufficient for ordinary documentation or deployment-file review.

## Working-tree safety

Check `git status --short` before and after edits. Existing modifications belong to the person working in the repository; do not overwrite or reformat unrelated files. Generated dependency, build, cache, runtime, and ledger directories are excluded from normal source work.
