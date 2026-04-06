# Contributing to Singularity

Thank you for your interest in contributing to this project.

---

## Table of Contents

- [Project Structure](#project-structure)
- [Ways to Contribute](#ways-to-contribute)
- [Development Setup](#development-setup)
- [Workflow](#workflow)
- [Code Style](#code-style)
- [Testing](#testing)
- [Commit Messages](#commit-messages)
- [Pull Requests](#pull-requests)

---

## Project Structure

This is a monorepo with three components:

```
singularity/
├── backend/laravel/   # Laravel 13 + Vue 3 backend
├── frontend/hugo/     # Hugo static site (Cyberia Blog)
└── linux/             # Cyberia OS build config
```

Most active development happens in `backend/laravel/`.

---

## Ways to Contribute

- Participate in issue discussions
- Propose new public API integrations
- Implement API adapters in `backend/laravel/app/Services/`
- Add or improve tests
- Fix bugs
- Improve documentation

If you are new, start by reading open issues to understand the current direction before writing code.

---

## Development Setup

### Requirements

- PHP 8.3+
- Composer 2
- Node.js 22
- npm

No external database is required — SQLite is used by default.

### Backend (Laravel)

```bash
cd backend/laravel
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install
npm run build
```

### Start the development server

```bash
# From repo root
./cli.sh serve

# Or from backend/laravel (starts Laravel + queue + Vite concurrently)
composer run dev
```

### Frontend (Hugo)

```bash
cd frontend/hugo
hugo server
```

---

## Workflow

1. Fork the repository from `https://github.com/cyberia-temple/singularity`
2. Create a branch in your fork for your change
3. Make your changes
4. Ensure all checks pass (see [Testing](#testing) and [Code Style](#code-style))
5. Open a pull request to `master` on the upstream repository
6. Reference the relevant issue in your PR description

---

## Code Style

All style checks are enforced in CI. Run them locally before opening a PR.

### PHP

```bash
# Fix style
composer run lint

# Check only (no changes)
composer run lint:check
```

The project uses [Laravel Pint](https://laravel.com/docs/pint) with the `laravel` preset.

### JavaScript / TypeScript

```bash
# ESLint
npm run lint          # fix
npm run lint:check    # check only

# Prettier
npm run format        # fix
npm run format:check  # check only

# TypeScript type checking
npm run types:check
```

### Run all checks at once

```bash
composer run ci:check
```

---

## Testing

Every code change must be accompanied by a test.

```bash
# Run all tests
./vendor/bin/pest

# Run via Artisan (compact output)
php artisan test --compact

# Run a specific test
php artisan test --compact --filter=TestName
```

Tests live in `backend/laravel/tests/`. Feature tests go in `tests/Feature/`, unit tests in `tests/Unit/`.

To create a new test:

```bash
php artisan make:test --pest NameOfTest
```

---

## Commit Messages

Use the imperative form and reference the related issue:

```
Add <description> #<issue-number>
Fix <description> #<issue-number>
Update <description> #<issue-number>
```

Examples:

```
Add cataas.com API service #14
Fix response parsing in YesNoApiService #17
```

Keep the subject line short. Add a body if the change needs more context.

---

## Pull Requests

- Target branch: `master`
- Title should follow the same format as commit messages
- Reference the issue your PR addresses
- Ensure CI passes (tests + lint) before requesting review

---

## License

By contributing, you agree that your contributions will be licensed under [GPL-3.0](./LICENSE).
