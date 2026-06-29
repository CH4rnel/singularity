# Changelog

All notable project changes should be recorded here when a tagged release is cut.

This project uses date-based release tags:

```text
vYYYY.MM.DD
```

## Unreleased

### Added

- LainOS: autonomous AI agent framework with a Cyberia chain plugin (`services/lainos`).
- Wired: 3D on-chain Godot game whose NPCs think via LainOS (`game/wired`).
- Cyberia L1 second-node config: non-validating full/RPC follower, prepared but not deployed (`services/cyberia-node`).
- NFT generator and PixelBattle surfaces.
- CyberSolSwap: on-chain `CYBER.sol` ↔ `CYBER` converter.
- Lending/farming UI and a CRM surface.
- Telegram bot inline buttons.
- Token listings (Yenten, Karasique, Goal) and Cyberia chain ID for DEXScreener integration.

### Changed

- Documentation now matches the actual tree: README repository map, "What Works Now" table, and architecture diagram cover `game/wired`, `services/lainos`, `services/telegram-bot`, and `services/cyberia-node`; `AGENTS.md` and `CLAUDE.md` document the second node. The gitignored `logs/` entry was dropped from the repository map.
- Refactored the Telegram bot and the analytics surface.

### Fixed

- Analytics and DCA bot fixes.

### Removed

- Untracked `frontend/ritual/.env.production` (now covered by `.gitignore`; its keys are templated in `.env.example`).
- Removed the stray root `key` public-key file and a stale `.gitmodules` that referenced nonexistent `frontend/hugo` and `frontend/blog` submodules.

## v2026.06.11 - 2026-06-11

### Added

- Public Laravel analytics surface at `/analytics`.
- Repository hygiene rules for local AI assistant state.
- Initial GitHub Actions CI for Laravel and EVM contracts.
- Issue templates for bugs, features, and first-time contributors.
- Ritual DEX environment template without secret values.

### Changed

- README now explains what Singularity is, how Cyberia/CYBER/CYBER.sol relate, where each component lives, and why the repository appears Elixir-heavy.
- Local `.env` files are ignored, and the tracked Ritual DEX `.env` was removed from git.

## Release Process

1. Move completed entries from `Unreleased` into a dated release section.
2. Tag the commit with `vYYYY.MM.DD`.
3. Publish a GitHub release using the changelog section as release notes.
4. Link important deployed surfaces, contract addresses, and user-visible changes.
