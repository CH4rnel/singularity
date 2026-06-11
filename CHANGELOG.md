# Changelog

All notable project changes should be recorded here when a tagged release is cut.

This project uses date-based release tags:

```text
vYYYY.MM.DD
```

## Unreleased

### Added

- Public Laravel analytics surface at `/analytics`.
- Repository hygiene rules for local AI assistant state.
- Initial GitHub Actions CI for Laravel and EVM contracts.
- Issue templates for bugs, features, and first-time contributors.

### Changed

- README now explains what Singularity is, how Cyberia/CYBER/CYBER.sol relate, where each component lives, and why the repository appears Elixir-heavy.

## Release Process

1. Move completed entries from `Unreleased` into a dated release section.
2. Tag the commit with `vYYYY.MM.DD`.
3. Publish a GitHub release using the changelog section as release notes.
4. Link important deployed surfaces, contract addresses, and user-visible changes.
