# Cyberia Telegram airdrop bot

The community Telegram bot for Cyberia: wallet linking and hourly TG/chat-token
rewards, per-chat reward tokens, the CYBER.sol "whales" gate, and on-chain
announcers (bridge, Ritual DEX swaps/liquidity, lending, CYBER.sol→CYBER
conversions, solo-pool staking, pump.fun buys) plus a periodic activity digest and the market snapshot that backs
the Laravel `/analytics` page. It also includes an optional Cyberia-scoped AI
assistant backed by any OpenAI-compatible chat completions API.

Previously a single 3.6k-line `scripts/python/telegram_airdrop_bot.py`; now a
small package. That old path still exists as a thin shim so the existing prod
launch keeps working unchanged.

## Layout

```
bot/
  config.py      env vars, contract ABIs/topics, .env discovery, logging
  utils.py       pure format/parse helpers
  db.py          SQLite engine, schema, key/value store, activity log, cursors
  chain.py       web3 client, log decoders, on-chain USD price walker
  handlers.py    Telegram command/message handlers
  ai.py          Cyberia AI prompt, provider client, and message handlers
  cyberia_knowledge.md  operator-approved facts supplied to the model
  pumpfun.py     pump.fun buy detection (Solana RPC + market feed) and the post
  announcers.py  background loops (bridge/swap/liquidity/lending/convert/
                 staking/pumpfun/digest/snapshot/whale) + run_snapshot_once
  app.py         build app, register handlers, schedule loops, main()
  __main__.py    `python -m bot`
```

## Run

```bash
cd services/telegram-bot
python -m venv .venv && . .venv/bin/activate
pip install -r requirements.txt
cp .env.example .env        # then fill in TELEGRAM_BOT_TOKEN, DEPLOYER_PK, DB_PATH
python -m bot
```

Refresh only the analytics market snapshot (no Telegram token needed):

```bash
python -m bot --snapshot-once
```

## Configuration

All settings come from the environment; see `.env.example`. `.env` is discovered
from, in order: `$BOT_ENV_FILE`, `services/telegram-bot/.env`,
`services/telegram-bot/bot/.env`, then the legacy
`scripts/python/.env` (so the prod shim keeps working). `DEPLOYER_PK` is never
logged.

### Wallet Mini App

The bot opens the Cyberia wallet inside Telegram. There is nothing to build
here and nothing to host: the Mini App is `WALLET_MINI_APP_URL`
(default `https://cyberia.church/wallet`), the same page a browser gets, so a
site deploy updates it. The bot hands out a URL and learns nothing about what
happens inside — the keys are made and encrypted by the page, in the web view's
own storage.

Three ways in, none of which needs BotFather:

- the **☰ menu button** beside the input box, set at startup (`WALLET_MINI_APP_MENU=0` turns it off);
- **`/open`**, which explains what Telegram cannot see and then offers the button;
- **`/app`**, which lists the installable builds and offers the Mini App underneath.

A `web_app` button is legal only in a private chat, so in groups the same page
is offered as an ordinary link — `mini_app_markup()` makes that choice and
`tests/test_mini_app.py` pins it. Configuring "Mini App" in BotFather is needed
only for a `t.me/<bot>/<app>` direct link, which nothing here depends on.

A new recovery phrase is never generated inside the frame: the wallet offers
import there and sends you to the site or the installed app to create one.

### pump.fun buy bot

Every CYBER.sol buy worth at least `PUMPFUN_MIN_BUY_USD` (default $5) gets its
own post in `PUMPFUN_ANNOUNCE_CHAT` — amount in SOL and USD, amount in
CYBER.sol, buyer and transaction on Solscan, a "New Holder!" line when the buyer
held none before, and the market cap. Leave `PUMPFUN_ANNOUNCE_CHAT` empty to
turn it off.

CYBER.sol has graduated off the pump.fun bonding curve, so buys settle on the
PumpSwap AMM pool its pump.fun page trades against. `bot/pumpfun.py` reads them
off that pool's **balance deltas** — the coin side leaving while the SOL side
arrives — instead of decoding pump.fun or router instructions, so a buy routed
through an aggregator reads like a direct one, and a liquidity deposit (both
sides moving in) is never mistaken for a buy. The buyer is whoever the coin
landed with, which stays correct when someone else paid the fee.

USD value, market cap and — unless `PUMPFUN_POOL_ADDRESS` pins one — the pool
address come from the DexScreener pair feed. Without a readable SOL price the
tick is deferred rather than posting a buy it cannot size, so nothing is lost.
On a fresh install the cursor starts at the pool's current head: history is
never replayed into the chat.

The loop polls Solana every `PUMPFUN_POLL_SECONDS` (default 30) and makes one
`getTransaction` call per new pool transaction. The default public RPC is enough
at current volume; point `SOLANA_RPC_URL` at a dedicated endpoint if posts start
arriving late.

The periodic activity digest can be muted with `DIGEST_INTERVAL_SECONDS=0`.
Small price changes are hidden by default (`DIGEST_PRICE_CHANGE_MIN_BPS=100`),
and the digest includes a compact `Prices` line from the bot's `token_prices`
snapshot so the chat can see which on-chain token prices are being used.

### AI assistant

The assistant is **off by default**: with `AI_ENABLED` unset (or falsy) the bot
registers no AI handlers at all — no `/ask`, no DM/mention answers, and no
"not configured" replies. Set `AI_ENABLED=1` to turn the feature on.

Then set `AI_API_KEY` to enable the assistant. `AI_API_URL` defaults to OpenAI's chat
completions endpoint and `AI_MODEL` defaults to `gpt-4.1-mini`; both can be
changed for another OpenAI-compatible provider. When no direct AI/OpenAI key is
set, a monorepo checkout automatically reuses `services/lainos/.env`'s
`OPENROUTER_API_KEY` and `OPENROUTER_MODEL_SMALL`, with OpenRouter's chat
completions endpoint. The key remains in one file and is never logged.

Users can call `/ask <question>` anywhere. Normal text is treated as a question
in private chats; in groups the bot responds only when mentioned or replied to,
so it does not consume every conversation. Short per-chat history is held in
Telegram application memory and is not written to the database. Edit
`bot/cyberia_knowledge.md` to update operator-approved project facts.

## Production runbook

The bot runs on prod from a long-lived process. The historical launch
(`cd /root/singularity/scripts/python` then running
`telegram_airdrop_bot.py` / `python -m scripts.python.telegram_airdrop_bot`)
**keeps working as-is** via the shim — after `git pull`, just restart the bot's
supervisor.

Recommended clean cutover:

1. `cd /root/singularity/services/telegram-bot`
2. Reuse the existing venv or create one and `pip install -r requirements.txt`.
3. Point the bot at its config and the live DB:
   - `.env` at `scripts/python/.env` is still picked up automatically; or set
     `BOT_ENV_FILE=/root/singularity/scripts/python/.env`.
   - `DB_PATH=/root/singularity/backend/laravel/database/database.sqlite`
   - optionally `BOT_LOG_FILE=/root/singularity/scripts/python/bot.log` to keep
     logs at the path ops already watch.
4. Launch with `python -m bot` and restart under the same supervisor.

The DB-coupled cron scripts (`distribute_tg.py`, `distribute_chat_tokens.py`)
were intentionally left in `scripts/python/` and are unaffected.
