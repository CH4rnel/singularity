# Contributing to Singularity

Thank you for your interest in contributing to this project.

---

## Project Structure

```
singularity/
├── backend/laravel/   # Laravel 13 + Vue 3 + Inertia
├── frontend/jekyll/   # Jekyll static site (Cyberia Blog)
├── hardhat/           # Solidity contracts
├── linux/             # Cyberia OS build config
├── services/          # Daemon services (Lisp)
└── scripts/           # Deployment & maintenance scripts
```

---

## Ways to Contribute

- Report bugs and open issues
- Participate in feature discussions
- Implement API adapters in `backend/laravel/app/Services/`
- Add or improve tests
- Fix bugs
- Improve documentation

---

## Development Setup

### Requirements

- PHP 8.3+
- Composer 2
- Node.js 22+
- npm or pnpm
- SQLite (default, PostgreSQL/MySQL supported)

### Quick Start

```bash
cd backend/laravel
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate:fresh --seed
npm install
npm run build

# Run dev server
composer run dev
```

---

## Code Style

All style checks are enforced in CI.

### PHP

```bash
composer run lint       # fix
composer run lint:check # check only
```

Uses Laravel Pint with `laravel` preset.

### JavaScript / TypeScript

```bash
npm run lint        # ESLint fix
npm run lint:check  # ESLint check
npm run format      # Prettier fix
npm run format:check
npm run types:check
```

### Run all checks

```bash
composer run ci:check
```

---

## Testing

Every code change must be accompanied by a test.

```bash
php artisan test --compact
./vendor/bin/pest
```

Tests: `backend/laravel/tests/Feature/` and `backend/laravel/tests/Unit/`.

```bash
php artisan make:test --pest TestName
```

---

## Commit Messages

```
Add <description> #<issue-number>
Fix <description> #<issue-number>
Update <description> #<issue-number>
```

Examples:
```
Add CATAAS API service #14
Fix response parsing in YesNoApiService #17
```

---

## Pull Requests

- Target branch: `main`
- Reference the issue in your PR
- Ensure CI passes before requesting review
- Title follows commit message format

---

## License

By contributing, you agree that your contributions will be licensed under [GPL-3.0](./LICENSE).