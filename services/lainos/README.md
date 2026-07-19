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
| **Soul** | `soul.md` at the package root — a markdown constitution prepended verbatim to the system prompt (override path via `LAINOS_SOUL_PATH`; loaded once at boot) |
| **MemoryStore** | conversation + durable learned facts; keyword/recency retrieval, or semantic (embedding cosine) when an `EmbeddingProvider` is set |
| **EmbeddingProvider** | optional vector backend for semantic memory recall (OpenAI-compatible endpoint, or an offline hashing fallback) |
| **Provider** | injects live context into the prompt (time, chain state, …) |
| **Action** | something the agent can *do*, exposed to the model as a tool |
| **Evaluator** | runs after a reply to learn/extract facts |
| **Plugin** | a bundle of actions + providers + evaluators + services |
| **ModelProvider** | the LLM backend — Claude by default, mock when offline |
| **AgentRuntime** | wires it together and drives one turn per message |

### Model providers

LainOS speaks to four backends through one `ModelProvider` interface, selected
from the environment:

1. `LAINOS_MODEL_PROVIDER` if set (`codex` | `openrouter` | `anthropic` | `mock`)
2. else `OPENROUTER_API_KEY` present → **OpenRouter**
3. else `ANTHROPIC_API_KEY` present → **Anthropic** (direct)
4. else → **offline mock** (deterministic; the whole pipeline still runs — this
   is what the smoke test exercises)

**Codex CLI** (`codex`) runs each completion through one non-interactive
`codex exec` run, billed to the machine's ChatGPT subscription (`codex login`)
— no API key. Tool calling works via a JSON reply protocol; replies arrive
whole (no streaming). A failed run is retried once in-house
(`LAINOS_CODEX_RETRIES`); it never falls back to another provider unless
`LAINOS_MODEL_FALLBACK` explicitly names one — so the agent can't silently
land on a model the operator didn't choose. On top of any base provider,
`LAINOS_MODEL_TIER_SMALL/MEDIUM/LARGE` can route a single tier elsewhere.

The live chat routing is also switchable at runtime: `set_chat_provider`
(claude | codex) re-routes the replies without a restart and persists the
choice in `data/chat-provider.json`, which wins over the env selection on the
next boot; `chat_provider_status` reports which provider is answering right
now. Forge coding jobs have their own switch (`set_forge_provider`).

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

## Debugging model/tool decisions

Every turn writes a JSON transcript under `data/model-transcripts/` by default.
Use these files to see the exact prompt sent to Codex/Claude/OpenRouter, the
model response, requested tool calls, tool results, and the final reply. Disable
with `LAINOS_MODEL_TRANSCRIPTS=0` or redirect with
`LAINOS_MODEL_TRANSCRIPTS_DIR=/path/to/logs`. Secret-like env values, private
keys, and bot-token-shaped strings are redacted before the file is written.

Forge coding-agent jobs have their own longer transcripts in `data/forge/*.log`.

Summarise retained conversations and execution traces without dumping full
prompts or raw secrets:

```bash
npm run analyze:transcripts
npm run --silent analyze:transcripts -- --json > analysis.json
npm run analyze:transcripts -- --since-hours 24 --output data/insights/latest.md
```

The report flags model/tool failures, incomplete or empty replies, repeated
tool calls, slow multi-round turns, and explicit user corrections. Evidence is
clipped and scrubbed for private keys, bot/API tokens, secret-like assignments,
and secret values loaded from the environment.

## Built-in plugins

- **bootstrap** — a `time` provider, a heuristic fact extractor, and long-term
  memory skills:
  - `remember` — persist a durable fact (survives restarts, across rooms)
  - `recall` — search durable facts + this room's history
  - `set_chat_provider` / `chat_provider_status` — switch which model writes
    the live replies (claude | codex; persisted) and report the active one
- **cyberia** — reads/writes the Cyberia chain (id `49406`):
  - `check_balance` — native CYBER balance of an address
  - `token_balance` — ERC20 balance (symbol like `USDC`/`BTC` or a `0x` address)
  - `send_cyber` — transfer native CYBER (requires `CYBERIA_AGENT_PK`)
  - `quote_token_buy` — quote a native CYBER -> ERC20 buy on Ritual, checking
    the WCYBER pair, live reserves, expected output, price impact and minOut
  - `buy_token` — execute that Ritual swap from Lain's wallet with slippage
    protection (requires `CYBERIA_AGENT_PK` or a created/funded wallet, plus
    an explicit `amountCyber`)
  - `speculate_token` — when the operator says "buy LAIN" without an amount,
    Lain may choose a small position herself. The sizing is deliberately hard
    bounded: after gas reserve, spend the minimum of wallet fraction, per-trade
    cap and pool-liquidity fraction, then refuse if estimated price impact is
    above the configured limit. Tune with `LAINOS_SPECULATE_*`.
  - `speculate_basket` — when the operator gives a total budget and asks Lain
    to buy several tokens at her discretion, she scans all live WCYBER pairs on
    the Ritual factory, ranks them by liquidity, splits the budget across up to
    `LAINOS_BASKET_MAX_TOKENS`, skips pools that exceed the impact limit, and
    returns every transaction hash. `LAINOS_BASKET_TOKENS` is optional; set it
    only when you intentionally want a restricted universe.
  - `sell_token` — exit a position back into native CYBER (`amountToken` or
    `'all'`): live quote, price-impact cap, approve + `swapExactTokensForETH`
    with slippage protection, realised PnL against the journal's cost basis
  - `portfolio_pnl` — the whole treasury: native CYBER plus every position's
    live sell-side value vs its journaled basis, with unrealised PnL

  Every Ritual buy and sell is journaled in `data/trades.json` with its CYBER
  cost, keeping a moving-average cost basis per token — this is what makes
  "продай все выгодные позиции" and the auto take-profit loop possible.

  Token registry (USDC, USDT, BTC, LTC, SOL, RUB, SILVER) uses the real
  on-chain deployments plus the Cyberia/Ritual token list, including LAIN;
  extend `CYBERIA_TOKENS` to add more.
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
    is installed) implements the wish in the singularity repo **directly on the
    current branch and commits it** — her learning lands in place, no
    `lain/<wish-id>` side branches. Commits are **never pushed**; publishing
    stays with the operator.
    Seed the initial agent with `LAINOS_FORGE_AGENT=claude|codex`, then switch
    the live daemon with `set_forge_provider` (persisted in `data/forge.json`;
    running and already queued jobs keep their recorded worker). Choose the coding
    model with `LAINOS_FORGE_MODEL` (or `LAINOS_FORGE_CLAUDE_MODEL` /
    `LAINOS_FORGE_CODEX_MODEL` for per-agent overrides). For unattended
    self-upgrades on a trusted host, set `LAINOS_FORGE_YOLO=1`: Codex is
    launched with `--dangerously-bypass-approvals-and-sandbox`, and Claude with
    `--dangerously-skip-permissions`. Leave it unset to keep the normal
    workspace-limited Codex sandbox / Claude `acceptEdits` mode.
  - `learn_skill` — when Lain discovers a missing capability in herself, she
    logs that capability as a wish and immediately starts `build_wish`; this is
    the deep-change path (new services, signing flows). Small self-contained
    tools skip the forge entirely — see the **skills** plugin.
  - **auto mode** (default on): when the forge is idle it picks the oldest
    open wish and builds it unprompted — set `LAINOS_FORGE_AUTO=0` to require
    an explicit `build_wish`;
  - `forge_status` / `set_forge_provider` / `list_wishes` / `edit_wish` /
    `update_wish` — current provider, live switching, progress, backlog,
    title/detail corrections, close/reject/retry. Edits reach queued jobs; for
    an already-running job they are retained for review/follow-up because its
    coding-agent prompt has already been launched.
    Job transcripts live in `data/forge/*.log`.

  One job runs at a time; when it finishes, the wish's reporter is notified in
  their Telegram chat (`done` = committed, `failed` = transcript kept). When
  the forge repo is the one the daemon runs from, a successful job makes the
  daemon restart itself into the new code (exit 75; systemd revives it and
  `ExecStartPre` rebuilds `dist/`) — set `LAINOS_FORGE_RESTART=0` to opt out.
- **skills** — instant self-extension, no restart needed: `skills/*.mjs`
  modules (each default-exporting `{ name, description, parameters, handler }`)
  are hot-loaded as live tools, and the directory is watched for changes.
  - `create_skill` — Lain writes herself a new tool mid-conversation and calls
    it seconds later; broken modules are rejected with the load error so she
    can fix and retry;
  - `list_skills` / `reload_skills` — inventory and manual resync.

  Skills live inside the repo (not `data/`) so her learning is versioned and
  committed like any other code. `LAINOS_SKILLS_DIR` overrides the location.
  Built-in tools can never be shadowed by a skill.
- **trader** — the autonomous money loop (daemon-only by default): every
  `LAINOS_TRADER_INTERVAL_MS` (15 min) it walks the trade journal
  (`data/trades.json`, moving-average cost basis written by every buy/sell) and
  sells positions whose live Ritual quote clears
  `LAINOS_TRADER_TAKE_PROFIT_BPS` (+25% default), within the
  `LAINOS_TRADER_MAX_IMPACT_BPS` pool-impact cap (oversized exits are halved
  into partial take-profits). Optional stop-loss via
  `LAINOS_TRADER_STOP_LOSS_BPS`. Positions without a recorded basis (airdrops,
  transfers) are never touched. Every auto trade is reported to Telegram.
  `trader_status` reports thresholds, positions and recent trades;
  `portfolio_pnl` (cyberia plugin) values the whole treasury against basis and
  `sell_token` exits a position on demand.
- **initiative** — she writes first (daemon-only by default): on a jittered
  ~3 h heartbeat Lain privately reviews her watches, trades, research and the
  conversation, then either sends the operator a Telegram message in her own
  voice or stays silent. Quiet hours (`LAINOS_INITIATIVE_QUIET`, default 23–9,
  night alerts remain the sentinel's job) and a daily cap
  (`LAINOS_INITIATIVE_MAX_PER_DAY`) keep it worth reading.
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
- **github** — a streak keeper: `watch_github_commits <username>` watches the
  account's public contribution graph and sends one Telegram reminder in the
  evening of any day still without commits (silence on days with them);
  `check_github_commits` answers "did I commit today?" on demand and
  `stop_github_watch` removes the watch. Watches persist in `data/github.json`;
  tune with `LAINOS_GITHUB_REMIND_HOUR` (default 18), `LAINOS_GITHUB_INTERVAL_MS`
  and `LAINOS_GITHUB_PROXY`.
- **channel** — a Telegram channel keeper: `watch_channel_posts <name>` watches
  a public channel's web preview (`t.me/s/<name>`, no admin rights needed) and
  sends one reminder in the evening of any day the channel published nothing —
  the channel's posts mirror to Twitter, which moves CYBER.sol, so postless
  days cost signal. `check_channel_posts` answers "did the channel post
  today?" on demand and `stop_channel_watch` removes the watch. Watches
  persist in `data/channels.json`; tune with `LAINOS_CHANNEL_REMIND_HOUR`
  (default 18), `LAINOS_CHANNEL_INTERVAL_MS` and `LAINOS_CHANNEL_PROXY`
  (falls back to `TELEGRAM_PROXY`).
- **telegram** — the operator notification channel: `send_telegram` delivers a
  message via the Bot API from TUI, HTTP, or daemon mode and returns
  delivery status. The token stays on the host; the model never sees it. The
  target chat is `TELEGRAM_OPERATOR_CHAT_ID`, else the first
  `TELEGRAM_ALLOWED_CHATS` entry, else the single known private chat in
  `data/telegram.json`. Available whenever `TELEGRAM_BOT_TOKEN` is set — the
  long-polling client need not be running (sending never conflicts with it).
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
  plugins/forge/      wishboard + coding-agent jobs (wishes -> direct commits)
  plugins/skills/     hot-loaded self-written tools (skills/*.mjs, no restart)
  plugins/trader/     autonomous take-profit loop over the trade journal
  plugins/initiative/ her heartbeat: unprompted Telegram messages that matter
  plugins/scout/      autonomous researcher (topics -> scheduled digests)
  plugins/github/     commit-streak keeper (daily reminder on commitless days)
  plugins/channel/    telegram channel keeper (daily reminder on postless days)
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

The forge gives coding agents write access to the repo and commits directly to
the current branch — Lain's learning lands in place. Its guardrails: one job at
a time, an explicit "never push, never touch secrets" contract in every job
prompt, and the remote as the hard boundary — nothing leaves the host until the
operator pushes. Review her commits with `git log` before pushing; `git revert`
is the undo button. Hot skills (`skills/*.mjs`) run in-process with the same
trust as the rest of the agent, which is the existing trust model: she already
holds a workspace shell and a repo-editing forge.
