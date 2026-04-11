# AGENTS.md

Guidelines for AI agents working on Singularity.

---

## Project Components

```
singularity/
├── backend/laravel/   # Laravel 13 + Vue 3 + Inertia
├── frontend/jekyll/   # Jekyll static site (Cyberia Blog)
├── hardhat/           # Solidity contracts
├── linux/             # Cyberia OS build config
├── services/          # Lisp daemon services
└── scripts/           # Deployment & maintenance
```

---

## Laravel Backend

See [`backend/laravel/AGENTS.md`](backend/laravel/AGENTS.md) for Laravel-specific guidelines.

### Key Stack

- PHP 8.3+
- Laravel 13
- Vue 3 + Inertia
- Pest for testing
- Laravel Pint for formatting
- TailwindCSS v4

### Commands

```bash
cd backend/laravel
composer run dev       # Laravel + queue + Vite
composer run ci:check  # lint + types
php artisan test --compact
```

---

## Hardhat (Solidity)

When working with Solidity contracts:

- Use Hardhat for local development
- Run tests: `npx hardhat test`
- Format: `npx prettier --write`

---

## Lisp Services

Services in `services/lisp/` are Common Lisp daemon processes.

- Run via SBCL: `sbcl --noinform --load services/lisp/daemon.lisp`
- Logs: `logs/` directory
- Managed via systemd (see README.md)

---

## General Rules

- Always verify changes with tests
- Run lint/format checks before committing
- Keep changes focused and atomic
- Reference issues in commits and PRs