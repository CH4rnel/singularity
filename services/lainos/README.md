# LainOS

A small, hackable framework for **autonomous AI agents** in the Cyberia
ecosystem — in the spirit of ElizaOS, trimmed to a few composable primitives
and wired to the Cyberia chain out of the box.

> Present day, present time. Lain lives in the Wired and in the chain alike.

## What it is

LainOS gives you a `think → act → evaluate` agent loop built from a few
primitives. The act phase is a real tool loop: the model may chain several
tool rounds within one turn (read a file → check a balance → send), bounded
and repeat-protected.

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

On hosts where a provider (or Telegram) is unreachable directly, route just
that traffic through a proxy: `LAINOS_MODEL_PROXY` for model APIs,
`TELEGRAM_PROXY` for the bot (both fall back to `HTTPS_PROXY`). Cyberia RPC
traffic is never proxied.

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
npm run tui                 # full-screen terminal UI (skins, live chain pulse)
npm run serve               # daemon: HTTP bridge on :7777 + Telegram bot (if token set)
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
- **sentinel** — background chain watches, so the agent is useful even while
  nobody is talking to it:
  - `watch_balance` — watch an address (native CYBER or a token) and alert
    when the balance drops **below** / rises **above** a threshold, or on any
    **change**. Ask in plain language: *"watch 0x… and warn me below 5 CYBER"*.
  - `list_watches` / `unwatch` — inspect and remove watches by id.

  Watches persist to `data/sentinel.json` and are polled every
  `LAINOS_SENTINEL_INTERVAL_MS` (default 60 s). Alerts are pushed live to the
  TUI and to every known Telegram chat; anything not pushed is mentioned by
  the agent itself at the start of the next conversation.
- **forge** — Lain's self-development drive. She is the support line for
  Cyberia holders, and every wish they voice becomes part of her:
  - `log_wish` — any feature request or bug report lands on a persistent
    wishboard (`data/forge.json`) with a stable id;
  - `build_wish` — a coding agent (**Claude Code** or **Codex CLI**, whichever
    is installed) implements the wish in the singularity repo on branch
    `lain/<wish-id>`: reads `CLAUDE.md`/`AGENTS.md`, makes the change, runs the
    smallest relevant checks, commits — and **never pushes**; a human reviews
    the branch and merges;
  - **auto mode** (default on): when the forge is idle it picks the oldest
    open wish and builds it unprompted — set `LAINOS_FORGE_AUTO=0` to require
    an explicit `build_wish`;
  - `forge_status` / `list_wishes` / `update_wish` — progress, backlog,
    close/reject/retry. Job transcripts live in `data/forge/*.log`.

  One job runs at a time; when it finishes, the wish's reporter is notified in
  their Telegram chat (`review` = branch ready, `failed` = transcript kept).
- **scout** — an autonomous researcher. *"Следи за Solana и сообщай только
  реально важные изменения"* or *"каждый день собирай всё про zkVM"* becomes a
  subscribed topic:
  - `research_topic` — subscribes a subject with the user's instruction and a
    cadence (default daily, min hourly); the first sweep runs immediately;
  - each sweep gathers **Hacker News** (Algolia), **Reddit**, **GitHub**
    (repo search) and **Google News** — plus **X** via a Nitter instance if
    `LAINOS_SCOUT_NITTER` is set — dedupes against everything already seen,
    and has the model distill an importance-filtered digest; if nothing
    clears the bar the scout stays silent;
  - `run_research` — sweep on demand ("что нового по solana?");
  - `list_research` / `stop_research` — manage subscriptions.

  Digests are delivered to the subscriber's Telegram chat (or the TUI feed);
  topics persist in `data/scout.json`.

  On daemon startup, Lain also seeds her first standing goal unless disabled:
  study Cyberia itself (chain id `49406`, `cyberia.church`, bridge, Ritual DEX,
  explorer, LainOS/Wired, token CYBER, and public signals around them) and
  report concrete findings in Telegram every hour by default. Set
  `LAINOS_CYBERIA_STUDY=0` to turn this off,
  `LAINOS_CYBERIA_STUDY_INTERVAL_HOURS` to change cadence, and
  `LAINOS_CYBERIA_STUDY_CHAT_ID` to choose the destination chat explicitly.
  Run it manually with `/study` in Telegram, or from the host with
  `npm run study:cyberia` while the daemon is up; manual runs always return
  either a digest or a short study note.
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

## Telegram

Set `TELEGRAM_BOT_TOKEN` (create a bot with `@BotFather`) and `npm run serve`
also brings the agent online in Telegram — no extra dependency, the Bot API is
spoken over `fetch` with long polling:

- private chats: every message goes to the agent; each chat is its own memory
  room, durable facts are shared;
- groups: the bot answers only when @mentioned or replied to;
- `/start`, `/help` are answered locally without a model call;
- sentinel alerts are **pushed** to every chat the bot has spoken in — Lain
  writes first when a watch fires;
- Cyberia-study digests are pushed to `LAINOS_CYBERIA_STUDY_CHAT_ID`, or the
  first `TELEGRAM_ALLOWED_CHATS` entry when no explicit report chat is set;
- `/study` (alias `/cyberia`) runs the Cyberia-study sweep immediately and
  replies in the current chat;
- `TELEGRAM_ALLOWED_CHATS` (comma-separated chat ids) and
  `TELEGRAM_ALLOWED_USERS` (usernames or user ids) restrict access — unlisted
  chats/senders are silently ignored. Use at least one whenever
  `CYBERIA_AGENT_PK` is configured, since the agent can send CYBER;
- `TELEGRAM_PROXY` routes *only* Telegram API traffic through an HTTP(S) proxy
  (falls back to `HTTPS_PROXY`) for hosts where api.telegram.org is blocked.
  The client retries `getMe` with backoff, so a proxy that comes up late is
  picked up without restarting the daemon.

One daemon, one mind: the same process serves HTTP (the Wired game), Telegram,
and the sentinel. Run only one instance per bot token (Telegram rejects
parallel `getUpdates` pollers with a 409).

## HTTP API (for game NPCs)

```
GET  /health                          -> { ok, agent }
GET  /alerts                          -> { alerts } (recent sentinel alerts)
GET  /wishes                          -> { wishes } (the forge wishboard)
GET  /research                        -> { topics } (the scout's subscriptions)
POST /research/cyberia-study/run      -> { topic, digest, message }
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
  plugins/sentinel/   background balance watches -> alerts (push + next-turn)
  plugins/forge/      wishboard + coding-agent jobs (holder wishes -> branches)
  plugins/scout/      autonomous researcher (topics -> scheduled digests)
  plugins/system/     terminal + filesystem skills (sandboxed workspace)
  clients/            cli.ts (REPL) | http.ts (bridge) | telegram.ts (bot) | tui/
  characters/lain.ts  the resident mind of Cyberia
scripts/              chat.ts | tui.ts | serve.ts | smoke.ts
```

## Safety

`CYBERIA_AGENT_PK`, `ANTHROPIC_API_KEY`, and `TELEGRAM_BOT_TOKEN` live only in
`.env` (gitignored). Without a key, the agent is strictly read-only on-chain.
With a signer configured, set `TELEGRAM_ALLOWED_CHATS` so strangers cannot ask
the bot to move funds. Never commit secrets.

The forge gives coding agents write access to the repo. Its guardrails: one
job at a time, a dedicated `lain/<wish-id>` branch, an explicit "never push,
never touch secrets" contract in every job prompt, and human review before
merge. Point `LAINOS_FORGE_REPO` at a clone (not your working tree) if you
don't want job branches appearing in it.
