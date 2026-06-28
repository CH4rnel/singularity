# LainOS + Wired

Two new, deliberately interconnected components in the Cyberia monorepo:

- **`services/lainos/`** — LainOS, a framework for autonomous AI agents
  (ElizaOS in spirit), with first-class Cyberia chain access.
- **`game/wired/`** — Wired, a 3D Godot game whose NPCs *are* LainOS agents and
  whose world reacts to on-chain state.

They share one chain (`49406`) and one mind: the Lain you chat with in a
terminal is the Lain standing in the 3D world.

```
 ┌──────────────┐   HTTP /chat    ┌──────────────────────┐
 │ Wired (Godot)│ ───────────────▶│ LainOS HTTP service  │
 │  NPC "Lain"  │ ◀─────────────  │  AgentRuntime + Claude│
 └──────┬───────┘   {text}        └──────────┬───────────┘
        │ eth_getBalance                      │ viem reads/writes
        ▼                                     ▼
 ┌────────────────────────── Cyberia chain (49406) ──────────────────────────┐
 │  native CYBER · ERC20s (USDC/USDT/BTC/…) · rpc.cyberia.church              │
 └───────────────────────────────────────────────────────────────────────────┘
```

## Status

| Phase | Scope | State |
|-------|-------|-------|
| 1 | LainOS core (runtime, memory, model providers, CLI) | ✅ done, typechecks, smoke test passes |
| 2 | Cyberia plugin (balances/tokens/transfer) + HTTP bridge | ✅ done, verified vs live RPC |
| 3 | Wired Godot project (world, player, chain HUD, gate) | ✅ done (needs Godot 4.3+ to run) |
| 4 | NPC ↔ LainOS integration over HTTP | ✅ done |
| 5 | Docs + monorepo wiring (AGENTS/CLAUDE) | ✅ done |
| 6 | Browser build + in-game wallet (EIP-1193) + sign ritual + Lain avatar | ✅ done (needs Godot 4.4 to export) |
| 7 | NFT game: forge/mint CyberiaNFT from fragments, NFT-gated gate, on-chain gallery (real `CyberiaNFT` contract) | ✅ done (needs Godot 4.4 + wallet to play) |
| 8 | Anti-cheat backbone `WiredForge` (B: server-signed EIP-712 entry tickets + C: on-chain turn-based duel; artifact minted only on a won duel) | ✅ contract written, 6/6 tests pass, **deployed to Cyberia mainnet** |

### Deployed (Cyberia mainnet, chainId 49406)

- **WiredForge**: `0x2daa4A79EC2224AD02D9D4eBf937924b76F669Fa`
  ([explorer](https://explorer.cyberia.church/address/0x2daa4A79EC2224AD02D9D4eBf937924b76F669Fa))
  — signer currently the deployer (`0xfA41…5179`); change to the LainOS server
  address via `setSigner` once the ticket-signing endpoint is live.
  Deployment record: `crypto/hardhat/deployments/cyberia-wiredforge.json`.

| 9 | LainOS game-auth server (model B): `/wired/session/start` + `/wired/session/ticket` sign EIP-712 tickets with `CYBERIA_AGENT_PK` | ✅ done — `src/wired/`, typecheck clean, unit + live-HTTP tests pass (`npm run wired:smoke`, `npm run serve:wired`) |

**Operational note:** the server's signer address (from `CYBERIA_AGENT_PK`) must
equal `WiredForge.signer()` on-chain — currently the deployer `0xfA41…5179`. Set
`CYBERIA_AGENT_PK` to that key, or use a dedicated signer key and call
`setSigner(serverAddress)` (owner-only).

| 10 | Godot duel client: `WiredAuth` (ticket handshake) + `Forge` (startRun/act, run + ICE reads) + in-world duel UI; gate & gallery repointed to `WiredForge` | ✅ done — compiles clean in Godot 4.4, web build re-exported |

The B↔C loop is now closed end to end in the build:

```
collect fragments → [M] at the Forge → WiredAuth signs an entry ticket (B)
→ approve startRun in wallet → on-chain duel: [1] strike [2] guard [3] overload,
   each move a tx the contract validates (C), ICE move shown via previewIceMove
→ win → WiredForge mints the artifact → gate opens, it appears in your gallery
```

To play it live: run `npm run serve:wired` with `CYBERIA_AGENT_PK` whose address
equals `WiredForge.signer()` on-chain (the deployer), and keep a little CYBER for
gas (startRun + each move is a transaction).

### Verification done

- `services/lainos`: `npm run typecheck` clean; `npm run smoke` passes all three
  assertions, including a **real** `eth_getBalance` of the null address (0 CYBER).
- `game/wired`: built without a local Godot binary; GDScript reviewed against the
  Godot 4.3 static analyzer (typed nodes via `class_name`, explicit input casts,
  procedural scene so the `.tscn` is a single node).

## Next phases (not yet built)

These are the natural continuations if the work continues:

1. **Real LLM pass** — set `OPENROUTER_API_KEY` (or `ANTHROPIC_API_KEY`) and tune
   Lain's prompt/temperature; add a model-backed fact extractor (replacing the
   heuristic in `plugins/bootstrap`). The OpenRouter provider is wired and its
   request shape is verified; it just needs a key.
2. **Vector memory** — swap `FileMemoryStore` for an embedding-backed store
   behind the same `MemoryStore` interface.
3. **More clients** — wire LainOS into the existing Telegram bot
   (`services/telegram-bot`) and a Discord client.
4. **On-chain quests in Wired** — the native-CYBER gate + collectible orbs +
   `personal_sign` ritual exist; next is ERC20-specific gating (USDC, BTC…) via
   `token_balance`, and minting a proof-of-completion NFT through the existing
   `crypto/hardhat` launchpad/NFT contracts (signed by the player's wallet).
5. **Signed actions** — let Lain (with `CYBERIA_AGENT_PK`) reward players in
   CYBER for in-game achievements, reusing `send_cyber`.
6. **Tests/CI** — add a `lainos` job to CI (`npm run typecheck && npm run smoke`).

## Quick start

```bash
# LainOS
cd services/lainos && npm install
npm run smoke          # offline + live-RPC check
npm run chat           # talk to Lain in the terminal
npm run serve          # HTTP bridge on :7777 for the game

# Wired (separate terminal; requires Godot 4.3+)
cd game/wired && godot4 --path .
```
