# Good First Issues

These are starter tasks maintainers can copy into GitHub Issues and label `good first issue`.

## Add Contract Verification Links To README

Area: docs/contracts

Problem: README lists Cyberia contract addresses, but not every address has a direct explorer link.

Scope:

- Add explorer links for the bridge and wrapped token addresses already listed in `README.md`.
- Do not change addresses unless a deployment file proves the new value.
- Add a short note when an address is not yet verified.

Verification:

```bash
rg -n "explorer.cyberia.church|0x7DcDa19Cf984ca708E5fA228AC148e7d82D508BA|0xEf2c8E731006EEDD8F44f5Ea03A389635BB28f90" README.md
```

## Add A Bridge Transfer Status Test

Area: Laravel app

Problem: The bridge UI needs confidence that transfer status pages keep rendering as bridge data evolves.

Scope:

- Add or extend one feature test under `backend/laravel/tests/Feature`.
- Cover a public route or API response that does not need live RPC access.
- Avoid changing production bridge behavior.

Verification:

```bash
cd backend/laravel
php artisan test --compact
```

## Document Ritual DEX Build Drift

Area: Ritual DEX

Problem: `frontend/ritual` has known lint/type drift, and contributors need a clear first map before fixing it.

Scope:

- Update `frontend/ritual/README.md` with the current build/test commands.
- Add a short "Known issues" section with the exact failing command and high-level failure class.
- Do not silence lint or TypeScript errors.

Verification:

```bash
cd frontend/ritual
npm run build
```
