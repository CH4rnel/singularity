# Changelog

All notable project changes should be recorded here when a tagged release is cut.

This project uses date-based release tags:

```text
vYYYY.MM.DD
```

## Unreleased

### Added

- Tracker (`/tracker`): a BitTorrent tracker where every release is minted as an NFT. The token is the publication — the index reads the owner and the description off the chain, refuses to announce a swarm nobody minted, and hides rather than deletes. Publishing, downloading and seeding all happen from the wallet; the desktop app creates the torrent and seeds it, which a browser tab cannot.
- A video and music player in the wallet: plays a release's pinned sample anywhere, and its files straight out of the swarm in the desktop app, with a playlist, seeking and a plain sentence for the containers no browser decodes.
- Cyberia Wallet browser extension (`frontend/extension`): Manifest V3 wallet with its own encrypted vault and an EIP-1193 provider for dapps, built for both Chromium and Firefox, published with the apps and offered at `/download`.
- LainOS: autonomous AI agent framework with a Cyberia chain plugin (`services/lainos`).
- Wired: 3D on-chain Godot game whose NPCs think via LainOS (`game/wired`).
- Cyberia L1 second-node config: non-validating full/RPC follower, prepared but not deployed (`services/cyberia-node`).
- NFT generator and PixelBattle surfaces.
- CyberSolSwap: on-chain `CYBER.sol` ↔ `CYBER` converter.
- Lending/farming UI and a CRM surface.
- Telegram bot inline buttons.
- Token listings (Yenten, Karasique, Goal) and Cyberia chain ID for DEXScreener integration.

### Changed

- The operators' console room now tells LainOS the state of the project before it asks it anything: the queue, the machines, the chain (head, indexer lag, prices, the pool snapshot, the gas tank), the bridge ledger, the thirty-day numbers and the board — composed from the same caches the lenses render, dated, and with anything unreadable said rather than zeroed. The two backends are told different things about it: the daemon that it is a starting point for its own tools, the tool-less persona that it is the end of the line.
- Documentation now matches the actual tree: README repository map, "What Works Now" table, and architecture diagram cover `game/wired`, `services/lainos`, `services/telegram-bot`, and `services/cyberia-node`; `AGENTS.md` and `CLAUDE.md` document the second node. The gitignored `logs/` entry was dropped from the repository map.
- Refactored the Telegram bot and the analytics surface.

### Fixed

- The console's gas-tank row compared the station's figure, which is in wei, against a floor written in CYBER — so it could only ever fire once the tank was under sixty wei, which is to say never.
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
