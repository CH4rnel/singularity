# Cyberia compute agent

Cyberia's services as containers on other people's machines, paid for by the
second in native CYBER, by the service's own treasury.

Three pieces, one per directory:

| Piece | Where | What it decides |
|---|---|---|
| `CyberiaCompute` | `crypto/hardhat/contracts/compute/CyberiaCompute.sol` | how much a provider is owed, to the second, and that it is never more than was prepaid |
| `ServiceTreasury` | `crypto/hardhat/contracts/compute/ServiceTreasury.sol` | that a service pays for itself out of its own fees, within a policy its DAO voted once |
| this agent | `services/compute-agent/` | what runs on a provider's host, and the evidence of what it used |

## The money

A **lease** is a provider, a rate in wei per second, a prepaid balance and the
hash of a service manifest. The provider's share accrues every second out of
the balance; `settle` moves it into `claimable`, `withdraw` pays it out, and a
protocol fee (`feeBps`, ceiling 10%) is split off on the way to whoever the DAO
names — the node-rewards pool is the intended recipient. Nothing is invoiced
and nothing is signed per payment.

A **ServiceTreasury** is the payer. A service's fees are sent to it (it is an
ordinary address as far as a fee recipient is concerned), its owner is the
service DAO's timelock, and `refill()` — callable by anybody — tops every lease
up to `runwayTarget` seconds of runway, never more than `maxRefillPerDay` in a
UTC day and never below `reserve`. So the vote is about policy, once; paying
the bill is a keeper's call. The guardian (the service's operations key) can
pause, resume and close leases and roll out a new manifest, but cannot open a
lease, change a rate or move a wei anywhere but back to the treasury.

## Who cheats, and what stops them

| Failure | Answer |
|---|---|
| Payer stops paying | Impossible beyond the prepaid balance. The agent stops the container when the runway hits zero (`plan.mjs`), so a provider works at most one tick unpaid. |
| Provider takes the money and runs nothing | The payer's watcher (`--watch`) probes the service and `pause`s the lease after three failures; accrual stops that block. The most a lying provider earns is rate × detection window. |
| Watcher pauses on its own network blip | A paused container stays up for `pauseGraceSeconds` (15 min) so the watcher can see it healthy and `resume`. |
| Provider runs a different image | The lease commits to the manifest's keccak256, the manifest pins the image by `@sha256` digest, and both are public. Provable, so slashable: `slash()` takes the bond (governance, because no contract can see a container). |
| Provider reads the service's secrets | A manifest is `public` (holds nothing) or `trusted` (names secrets). The agent refuses `trusted` work unless the DAO marked the host trusted (`COMPUTE_TRUSTED=1`). Validators, hot wallets and LainOS are never leased (`catalog.json` → `neverLeased`). |
| Provider leaves | It can close a lease any time (accrued earnings kept, rest refunded) but its bond stays locked for `UNBOND_DELAY` (7 days) and until every lease is closed — long enough for a slashing vote. |
| Manifest host serves something else | The agent hashes what it fetched and refuses a lease whose manifest does not match (`reject`), rather than running anything. |

Usage (cpu-seconds, MB-seconds, bytes in/out) is measured from `docker stats`
and committed hourly with `reportUsage` as a hash — evidence for a dispute and
the input a usage-priced lease would bill from. Billing itself is the
reserved shape per second.

## Run a provider

```bash
cp .env.example .env    # COMPUTE_ADDRESS, COMPUTE_PROVIDER_KEY
docker compose up -d
```

The provider must first `registerProvider(endpoint)` with at least `minBond`
CYBER and be approved by the owner (`setProviderApproved`) — which is where the
node-operator rule (5% of CYBER.sol) is checked, off-chain, because this chain
cannot read Solana. The agent needs the docker socket, which is root on that
host: run it only where every container may be managed by it. It touches only
containers labelled `cyberia.lease`.

`COMPUTE_DRY_RUN=1 COMPUTE_PROVIDER_ADDRESS=0x…` prints every docker command
instead of running it.

## Run a watcher (payer side)

```bash
COMPUTE_PROVIDER_KEY=<guardian key> COMPUTE_WATCH="12=https://dns.cyberia.church/health" \
  node src/agent.mjs --watch
```

## Manifests

`catalog.json` is every Cyberia service as a lease would describe it: tier,
migration wave, which treasury pays, resources, ports, volumes, health. CI
(`.github/workflows/compute-images.yml`) builds the images that have a
Dockerfile here, pushes them to GHCR and renders the manifest against the
pushed digest:

```bash
node src/agent.mjs --manifest cyberia-dns sha256:<digest>   # manifest + spec hash
node src/agent.mjs --hash manifest.json                     # hash of an existing one
```

Pin the manifest to IPFS; its `ipfs://` URI and spec hash are what
`ServiceTreasury.openLease` / `updateSpec` take.

## Test

```bash
npm install && npm test
cd ../../crypto/hardhat && npx hardhat test test/CyberiaCompute.ts   # contract + agent arithmetic agree
```
