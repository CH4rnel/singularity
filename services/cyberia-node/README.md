# Cyberia — second chain node (`213.135.146.117`)

A **second node for the Cyberia chain** (chainID `49406`, polygon-edge / IBFT
PoA). It is a **non-validating full / RPC node**: it syncs every block from the
production validator and can serve JSON-RPC, but it **never seals**. That is the
whole point — it adds a redundant copy of the chain and a backup RPC endpoint
**without any risk to the live network**.

> Status: **prepared, not deployed.** Nothing here is running yet, and the
> production validator has not been touched. Bring-up is a deliberate manual
> step (see below).

The complete operator runbook—including firewall/RPC hardening, sync acceptance
criteria, monitoring, upgrades, recovery, and troubleshooting—is in
[`docs/developers/running-a-node.md`](../../docs/developers/running-a-node.md).

## Why a follower, not a second validator

The live chain runs on **one** validator (IBFT PoA, BLS). With a single
validator, quorum is 1. If you add a *second validator*, IBFT quorum becomes 2,
which means **both** nodes must be online for the chain to produce blocks — a
single outage would **halt the whole chain**. That is the exact opposite of
"don't break the one that works." So this node is a follower. Promoting it to a
validator later is possible but is a separate, deliberate governance action
(see the appendix) — not part of this setup.

## Key facts (discovered from prod)

| | |
|---|---|
| Chain | `cyberia`, chainID `49406`, IBFT PoA, BLS validators, 1s blocks |
| Production validator IP | `2.26.24.177` (port `1337` p2p, `8545` RPC) |
| Production validator node ID | `16Uiu2HAmGYqgBskF5GLAgMbYaB1rDyYbVnEWN6qapYZGesUTm9go` |
| Bootnode this node dials | `/ip4/2.26.24.177/tcp/1337/p2p/16Uiu2HAmGYqgBskF5GLAgMbYaB1rDyYbVnEWN6qapYZGesUTm9go` |
| This node IP | `213.135.146.117` |

### ⚠️ The genesis bootnode IP on prod is stale

The production genesis (`/root/id/docker/polygon-edge/genesis.json`) lists the
bootnode as `195.166.164.94`, but the validator's real IP is now `2.26.24.177`
(the node ID is unchanged). The prod chain doesn't care — it's a lone sealer
with no peers — but a new node using the prod genesis verbatim would dial a dead
address and never sync. **The `genesis.json` in this directory has the corrected
bootnode IP.** Everything else is byte-identical to prod, and `bootnodes` is not
part of the genesis block hash, so it's the same chain.

(Optionally, prod's genesis bootnode IP could be corrected too, but that needs a
validator restart — skip it unless you have a reason; it's not required here.)

## Files

| File | Purpose |
|---|---|
| `genesis.json` | Prod genesis, bootnode IP corrected to `2.26.24.177` |
| `docker-compose.yml` | The second node, `--seal=false`, `--nat=213.135.146.117` |
| `setup.sh` | One-time prep: checks + generates this node's own keys (does **not** start it) |

## Deploy steps (run on `213.135.146.117`)

Prerequisites: Docker + Docker Compose installed; outbound to `2.26.24.177:1337`
allowed; inbound `1337/tcp` open on this host. Plan for enough disk for the full
chain and its continued growth; use the operator runbook's current sizing and
disk-monitoring guidance rather than the chain height captured in this file.

```bash
# 1. copy this directory onto the host, e.g. /root/cyberia-node, then:
cd /root/cyberia-node

# 2. one-time prep: sanity checks + generate THIS node's unique keys
./setup.sh

# 3. when ready, start syncing (from block 0)
docker compose up -d
docker compose logs -f cyberia-node
```

### Verify it's syncing

```bash
# this node's height (should climb, then catch up to prod):
curl -s -X POST -H 'content-type: application/json' \
  --data '{"jsonrpc":"2.0","method":"eth_blockNumber","params":[],"id":1}' \
  http://127.0.0.1:8545

# production height, for comparison:
curl -s -X POST -H 'content-type: application/json' \
  --data '{"jsonrpc":"2.0","method":"eth_blockNumber","params":[],"id":1}' \
  http://2.26.24.177:8545

# peer count (should be >= 1, the validator):
docker exec cyberia-node polygon-edge peers list
```

## Safety — do NOT

- **Do not** run this node with `--seal` / as a validator (see top). `--seal`
  defaults to `true` in polygon-edge, which is why the compose pins
  `--seal=false`.
- **Do not** copy the production validator's data dir
  (`/root/polygon-edge-validators`) or its keys onto this host. Reusing the
  libp2p key collides the node ID; reusing the validator/BLS key risks
  double-signing. `setup.sh` generates fresh, unique keys for this node.
- **Do not** touch the running validator (no restart, no genesis edit) to set
  this up. Nothing here requires it.

## Appendix — promoting to a real second validator (later, optional, risky)

Only if you explicitly decide you want a 2-of-2 validator set and accept that
both nodes must then stay online:

1. Get this node fully synced first.
2. On the **existing** validator, propose adding this node's validator address
   (from `secrets output`) via IBFT voting:
   `polygon-edge ibft propose --addr <new-validator-addr> --vote auth ...`
3. Wait for the next epoch; confirm the validator set with `ibft snapshot`.
4. Only then start this node with `--seal=true`.

Treat that as a separate change with its own review — it changes consensus
liveness guarantees for the whole chain.
