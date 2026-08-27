# Testing and Verification

Verification in Singularity follows component boundaries. Run the narrowest command that proves your change, then expand only when the change crosses a boundary.

## Command matrix

| Component | Focused checks | Build or artifact check |
| --- | --- | --- |
| Laravel and Vue | `php artisan test --compact`, `npm run test:frontend`, `npm run types:check` | `npm run build` |
| Laravel full CI | `composer run ci:check` | Included in the component workflow |
| Laravel formatting | `vendor/bin/pint --dirty --format agent`, `npm run format:check` | Review only touched files |
| Ritual DEX | `npm test -- --watchAll=false` | `npm run build` |
| EVM contracts | `npx hardhat test` | `npx hardhat compile` |
| Solana programs | `anchor test` | `anchor build` |
| Anchor scripts | `npm run lint` | Covered by the Anchor build where applicable |
| Desktop | `npm test` | `npm run pack` or a target-specific `dist:*` command |
| Mobile | `npm test`, `npm run doctor` | `npm run sync:android` or `npm run sync:ios` |
| Browser extension | `npm test` | `npm run build` |
| LainOS | `npm run typecheck` or a focused smoke script | `npm run build` |
| Documentation | Link-aware VitePress build | `npm run build` |

Run commands from the component directory shown in [Component guide](components.md).

## Pick checks by change type

### Documentation-only change

```bash
cd docs
npm run build
```

The build validates the VitePress configuration and internal links. Preview pages locally when a change adds a wide table, nested navigation, custom HTML, or a new callout.

### Laravel backend change

1. Run the smallest Pest file or filter that covers the feature.
2. Run `vendor/bin/pint --dirty --format agent` for touched PHP.
3. Run `php artisan test --compact` when shared services or routes changed.
4. Use `composer run ci:check` for a release-ready Laravel verification.

### Vue or browser-library change

1. Run the matching file under `tests/Frontend/` through `npm run test:frontend`.
2. Run `npm run types:check` and identify whether any reported older errors are outside the changed surface.
3. Run `npm run build` when imports, routes, bundling, or production assets changed.

### Contract or amount-handling change

1. Add tests for the exact integer units, decimals, and boundary values.
2. Run the contract suite in its own package.
3. Run frontend or backend consumers that mirror the contract behavior.
4. Compare any public address or ABI update with the deployment record and explorer.

### Cross-component change

Build a short verification chain. For example, a bridge-token change may require:

1. contract tests;
2. Laravel feature tests;
3. frontend amount/locale tests;
4. the Laravel production asset build;
5. the documentation build.

## Artifacts to confirm

- Ritual production builds contain `frontend/ritual/build/index.html` and `frontend/ritual/build/static/`.
- Documentation builds are generated under `docs/.vitepress/dist/` and are not source files.
- Desktop artifacts are generated under `frontend/desktop/dist/`.
- Mobile web and Android build outputs remain under their generated directories.
- Extension builds are generated under `frontend/extension/dist/`.
- Hardhat and Anchor output directories are generated and must not be edited manually.

## Report a verification result

Record the exact command and outcome. If a broader command reports an existing issue outside the changed surface, identify the file or test and still report the focused check that proves the new behavior.
