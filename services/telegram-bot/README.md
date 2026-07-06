# Cyberia Telegram airdrop bot

The community Telegram bot for Cyberia: wallet linking and hourly TG/chat-token
rewards, per-chat reward tokens, the CYBER.sol "whales" gate, and on-chain
announcers (bridge, Ritual DEX swaps/liquidity, lending, CYBER.sol→CYBER
conversions) plus a periodic activity digest and the market snapshot that backs
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
  announcers.py  background loops (bridge/swap/liquidity/lending/convert/
                 digest/snapshot/whale) + run_snapshot_once
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

### AI assistant

Set `AI_API_KEY` to enable the assistant. `AI_API_URL` defaults to OpenAI's chat
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
