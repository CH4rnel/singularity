# Developer Guide

Singularity is the Cyberia monorepo. It combines the public site and bridge, a DEX, EVM and Solana contracts, native application shells, games, bots, AI agents, the Blockscout deployment, and operational tooling.

There is no single root application to start. Pick a component, follow its nested instructions, and run the narrowest relevant verification command.

## Start here

| Goal | Read | Main source |
| --- | --- | --- |
| Understand the system | [Architecture](architecture.md) | Repository-wide |
| Run a component locally | [Local development](local-development.md) | Component-specific |
| Run a full/RPC node | [Как поднять ноду Cyberia](running-a-node.md) | `services/cyberia-node/` |
| Integrate the chain | [Network reference](network-reference.md) | Cyberia EVM L1 |
| Build a user-facing page or API | Laravel [`AGENTS.md`](https://github.com/cyberia-temple/singularity/blob/master/backend/laravel/AGENTS.md) | `backend/laravel/` |
| Build or test EVM contracts | [`crypto/hardhat/README.md`](https://github.com/cyberia-temple/singularity/blob/master/crypto/hardhat/README.md) | `crypto/hardhat/` |
| Work on the DEX | [`frontend/ritual/README.md`](https://github.com/cyberia-temple/singularity/blob/master/frontend/ritual/README.md) | `frontend/ritual/` |
| Use Cyberia-hosted inference | [Inference API](../ai-api.md) | `/api/ai/v1` |
| Work on autonomous agents | [LainOS](https://github.com/cyberia-temple/singularity/blob/master/services/lainos/README.md) | `services/lainos/` |

## Repository rules

- Read the root `AGENTS.md`, then the nearest nested `AGENTS.md` before changing a subtree.
- Treat environment files, wallet JSON, cookies, tokens, private keys, and production service configuration as secrets.
- Do not edit dependency trees or generated build and contract outputs.
- Keep changes focused and preserve unrelated working-tree changes.
- Verify with the smallest useful test, type check, build, or configuration command.

For a complete directory map, see [Architecture](architecture.md#repository-map).
