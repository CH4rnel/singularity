# LainOS

A small, hackable framework for **autonomous AI agents** in the Cyberia
ecosystem — in the spirit of ElizaOS, trimmed to a few composable primitives
and wired to the Cyberia chain out of the box.

> Present day, present time. Lain lives in the Wired and in the chain alike.

## What it is

LainOS gives you a `think → act → evaluate` agent loop built from:

| Primitive | Role |
|-----------|------|
| **Character** | identity, voice, lore, model tier, requested plugins |
| **MemoryStore** | conversation + durable learned facts; keyword/recency retrieval, or semantic (embedding cosine) when an `EmbeddingProvider` is set |
| **EmbeddingProvider** | optional vector backend for semantic memory recall (OpenAI-compatible endpoint, or an offline hashing fallback) |
| **Provider** | injects live context into the prompt (time, chain state, …) |
| **Action** | something the agent can *do*, exposed to the model as a tool |
| **Evaluator** | runs after a reply to learn/extract facts |
| **Plugin** | a bundle of actions + providers + evaluators + services |
| **ModelProvider** | the LLM backend — Claude by default, mock when offline |
| **AgentRuntime** | wires it together and drives one turn per message |

### Model providers

LainOS speaks to three backends through one `ModelProvider` interface, selected
from the environment:

1. `LAINOS_MODEL_PROVIDER` if set (`openrouter` | `anthropic` | `mock`)
2. else `OPENROUTER_API_KEY` present → **OpenRouter**
3. else `ANTHROPIC_API_KEY` present → **Anthropic** (direct)
4. else → **offline mock** (deterministic; the whole pipeline still runs — this
   is what the smoke test exercises)

Model tiers map to the latest Claude family:

| Tier | OpenRouter slug | Anthropic snapshot |
|------|-----------------|--------------------|
| `SMALL` | `anthropic/claude-haiku-4.5` | `claude-haiku-4-5-20251001` |
| `MEDIUM` | `anthropic/claude-sonnet-4.6` | `claude-sonnet-4-6` |
| `LARGE` | `anthropic/claude-opus-4.8` | `claude-opus-4-8` |

**OpenRouter** is the easiest path — one key, OpenAI-compatible, and you can
point any tier at any OpenRouter model via `OPENROUTER_MODEL_SMALL/MEDIUM/LARGE`
(e.g. a free model for `SMALL`). Get a key at <https://openrouter.ai/keys>:

```bash
cp .env.example .env        # already done for you
# put your key on the OPENROUTER_API_KEY= line, then:
npm run chat
```

## Quick start

```bash
npm install
cp .env.example .env        # optional: add ANTHROPIC_API_KEY + CYBERIA_AGENT_PK
npm run smoke               # end-to-end check (uses a real Cyberia chain read)
npm run chat                # interactive REPL with Lain
npm run serve               # HTTP bridge on :7777 (consumed by the Wired game)
```

## systemd daemon

The checked-in user unit runs the HTTP bridge as a persistent daemon. It builds
TypeScript before each start, restarts after failures, and relies on
`services/lainos/.env` through the service working directory.

```bash
mkdir -p ~/.config/systemd/user workspace
ln -sfn "$PWD/deploy/lainos.service" ~/.config/systemd/user/lainos.service
systemctl --user daemon-reload
systemctl --user enable --now lainos.service
curl --fail http://127.0.0.1:7777/health
```

Inspect it with `systemctl --user status lainos.service` and
`journalctl --user -u lainos.service -f`. User lingering must be enabled for
startup during boot without an interactive login (`loginctl show-user "$USER"
-p Linger`); it is already enabled on the current Cyberia host.

## Built-in plugins

- **bootstrap** — a `time` provider, a heuristic fact extractor, and long-term
  memory skills:
  - `remember` — persist a durable fact (survives restarts, across rooms)
  - `recall` — search durable facts + this room's history
- **cyberia** — reads/writes the Cyberia chain (id `49406`):
  - `check_balance` — native CYBER balance of an address
  - `token_balance` — ERC20 balance (symbol like `USDC`/`BTC` or a `0x` address)
  - `send_cyber` — transfer native CYBER (requires `CYBERIA_AGENT_PK`)

  Token registry (USDC, USDT, BTC, LTC, SOL, RUB, SILVER) uses the real
  on-chain deployments; extend `CYBERIA_TOKENS` to add more.
- **system** — a terminal and filesystem, confined to a workspace
  (`LAINOS_WORKSPACE`, default `./workspace`):
  - `run_shell` — run a shell command (cwd = workspace, hard timeout, clipped output)
  - `read_file` / `write_file` / `list_dir` — files within the workspace

  Powerful and dual-use: paths that escape the workspace are refused. It loads
  only for characters that list `"system"` in their `plugins` (Lain does); drop
  it from a character's plugins to take the capability away.

## Semantic memory

Memory persists to `data/memory.json` (episodic, per-room) plus durable learned
facts, and survives restarts. Retrieval is keyword + recency by default. Point
LainOS at an embedding model and recall becomes *semantic* — past turns are
ranked by meaning (embedding cosine), not just shared words:

```bash
# OpenAI
LAINOS_EMBED_API_KEY=sk-...           LAINOS_EMBED_MODEL=text-embedding-3-small
# or a local, fully-offline server (Ollama)
LAINOS_EMBED_BASE_URL=http://localhost:11434/v1   LAINOS_EMBED_MODEL=nomic-embed-text
# or a zero-config, dependency-free offline fallback (lexical vectors)
LAINOS_EMBED_PROVIDER=hash
```

Vectors are cached in `data/embeddings.json` and backfilled for pre-existing
memories on first search. No embedding config means the keyword path — no
network, no behaviour change. Changing the embedding model invalidates cached
vectors: delete `data/embeddings.json` to re-embed.

## Build your own agent

```ts
import { createAgent, lain } from "lainos";

const agent = await createAgent({ character: lain });
const { text } = await agent.handleMessage({
  roomId: "demo",
  userId: "me",
  text: "what's the USDC balance of 0xdc25597B19799010047F17e9591EFE08EFd40077?",
});
console.log(text);
```

Write a new `Character`, or a new `Plugin` (actions/providers/evaluators), and
pass it to `createAgent`. The `Plugin` interface is the whole extension surface.

## HTTP API (for game NPCs)

```
GET  /health                          -> { ok, agent }
POST /chat { roomId, userId, text }   -> { text, actions }
```

The **Wired** Godot game (`game/wired/`) drives its Lain NPC through this
endpoint — the same agent, one mind across the terminal and the 3D world.

## Layout

```
src/
  types.ts            core interfaces (the contract)
  runtime.ts          AgentRuntime — the think→act→evaluate loop
  memory/store.ts     file-backed long-term memory + retrieval
  memory/embeddings.ts  embedding providers (OpenAI-compatible | offline hash)
  models/             anthropic.ts (Claude) | mock.ts | index.ts (factory)
  plugins/bootstrap/  time provider + fact extractor + remember/recall
  plugins/cyberia/    chain service + balance/transfer actions
  plugins/system/     terminal + filesystem skills (sandboxed workspace)
  clients/            cli.ts (REPL) | http.ts (bridge)
  characters/lain.ts  the resident mind of Cyberia
scripts/              chat.ts | serve.ts | smoke.ts
```

## Safety

`CYBERIA_AGENT_PK` and `ANTHROPIC_API_KEY` live only in `.env` (gitignored).
Without a key, the agent is strictly read-only on-chain. Never commit secrets.
