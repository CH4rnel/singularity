# Cyberia explorer — Blockscout docker-compose deployment

Deployment configuration for the Cyberia block explorer (`https://explorer.cyberia.church`).

This directory is **configuration only**. It runs the **official, pre-built**
Blockscout images — no Elixir source is vendored or built in this repository:

- `backend` / `nft_media_handler`: `ghcr.io/blockscout/blockscout:11.0.0` (pinned)
- `frontend`: `ghcr.io/blockscout/frontend`
- Rust microservices (`stats`, `visualizer`, `sig-provider`, `user-ops-indexer`)

To upgrade Blockscout, bump the image tag in `docker-compose.yml` (and migrations
run automatically on backend start via `create_and_migrate()`); there is nothing to
rebuild from source.

The shared production nginx also serves `https://docs.cyberia.church` from the
read-only `docs/.vitepress/dist/` mount. `scripts/deploy-prod.sh` builds that
artifact inside the Laravel container before any required nginx recreation.

## Prerequisites

- Docker v20.10+ and the Docker Compose v2 plugin
- A reachable Ethereum JSON-RPC endpoint (Cyberia node at `host.docker.internal:8545`)

## Usage

```bash
cd services/blockscout/docker-compose
docker compose config        # validate the merged config
docker compose pull          # fetch the pinned official images
docker compose up -d         # start the stack
docker compose down          # stop the stack
```

`docker-compose.yml` is the single Cyberia entrypoint. It wires the backend
JSON-RPC to `host.docker.internal:8545` and uses Cyberia `CHAIN_ID=49406`. Besides
the explorer it also brings up the project's adjacent prod services
(`cyberia_church` Laravel app, `polygon-edge` node, `ipfs`, `certbot`).

> The stock Blockscout client variants (`geth.yml`, `erigon.yml`, `anvil.yml`,
> `external-*.yml`, `no-services.yml`, …) and the from-source `docker/Dockerfile`
> are intentionally not kept here — Cyberia uses `polygon-edge` and the official
> images, so they were removed to keep this directory focused.

## Configuration

Environment variables live under `./envs/`:

- backend — `./envs/common-blockscout.env`
- frontend — `./envs/common-frontend.env`
- nft media handler — `./envs/common-nft-media-handler.env`
- stats — `./envs/common-stats.env`
- visualizer — `./envs/common-visualizer.env`
- user-ops-indexer — `./envs/common-user-ops-indexer.env`

ENV reference: [backend](https://docs.blockscout.com/setup/env-variables),
[frontend](https://github.com/blockscout/frontend/blob/main/docs/ENVS.md).

**Note:** some paths in `docker-compose.yml` are production-host-specific
(e.g. `/root/singularity/...`). Be careful when editing on other machines.
