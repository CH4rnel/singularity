"""Tell each chat that its members have rewards waiting.

Rewards now accrue off-chain and reach the chain only when somebody runs
/claim, which is the right shape — but a balance nobody knows about is the
same as no balance. This is how people find out.

It posts to the **chats**, not to 804 private messages, and that is a
constraint rather than a preference: a Telegram bot cannot open a conversation
with somebody who has never written to it, so most of those DMs would fail
with "bot can't initiate conversation with a user". The chat is also where the
token means something.

One message per chat, and only where somebody actually has something waiting.
Dry-run by default, because this writes to rooms with real people in them.

    python announce_claims.py            # show what would be posted
    python announce_claims.py --send     # post it
"""

import argparse
import asyncio
import logging
import os
from pathlib import Path

from dotenv import load_dotenv
from sqlalchemy import create_engine, text
from telegram import Bot
from telegram.error import TelegramError

load_dotenv(Path(__file__).parent / ".env")

logging.basicConfig(
    format="%(asctime)s - %(levelname)s - %(message)s", level=logging.INFO
)
logger = logging.getLogger(__name__)

DB_PATH = os.environ.get(
    "DB_PATH", "/home/lain/random/singularity/backend/laravel/database/database.sqlite"
)
TELEGRAM_TOKEN = os.environ.get("TELEGRAM_TOKEN") or os.environ.get("TELEGRAM_BOT_TOKEN")
WALLET_URL = os.environ.get("WALLET_MINI_APP_URL", "https://cyberia.church/wallet")

engine = create_engine(f"sqlite:///{DB_PATH}", connect_args={"check_same_thread": False})


def _waiting():
    """(chat_id, symbol, people, total_whole_tokens) per chat with balances."""
    with engine.connect() as conn:
        rows = conn.execute(
            text("""
                SELECT p.chat_id, t.symbol, p.user_id, p.amount
                FROM pending_rewards p
                JOIN chat_tokens t ON t.chat_id = p.chat_id
                WHERE p.amount NOT IN ('', '0')
                  AND t.token_address IS NOT NULL
            """)
        ).fetchall()

    per_chat: dict = {}
    for chat_id, symbol, _user_id, amount in rows:
        try:
            value = int(amount)
        except (TypeError, ValueError):
            continue
        if value <= 0:
            continue
        entry = per_chat.setdefault(chat_id, {"symbol": symbol, "people": 0, "total": 0})
        entry["people"] += 1
        entry["total"] += value

    return per_chat


def _message(symbol: str, people: int, total: int) -> str:
    """Russian, because these rooms are.

    Every other message this bot sends is English, and it has cost nothing so
    far because they are all answers to a command somebody typed. This one is
    the opposite: it is the message that has to persuade 742 people who never
    asked for it, and it is the only one whose language decides whether it
    works.
    """
    whole = total // 10**18
    return (
        f"У {people} из вас накоплен {symbol} — всего {whole} {symbol}.\n\n"
        f"Он копился, пока вы тут общались. В блокчейн за него ничего не "
        f"отправлялось и не отправится, пока вы сами не попросите:\n\n"
        f"1. /claim — покажу, сколько у вас.\n"
        f"2. /set_wallet <адрес> — если кошелёк ещё не привязан.\n"
        f"3. Нет кошелька? Он делается за минуту: {WALLET_URL}\n\n"
        f"Баланс растёт в любом случае. Claim просто забирает его себе."
    )


async def main(send: bool) -> None:
    per_chat = _waiting()

    if not per_chat:
        logger.info("Nothing waiting anywhere — nothing to announce.")
        return

    bot = Bot(TELEGRAM_TOKEN) if send else None

    for chat_id, entry in sorted(per_chat.items(), key=lambda kv: -kv[1]["people"]):
        text_body = _message(entry["symbol"], entry["people"], entry["total"])
        logger.info(
            "chat %s (%s): %d people, %d whole tokens",
            chat_id, entry["symbol"], entry["people"], entry["total"] // 10**18,
        )

        if not send:
            print(f"\n--- would post to {chat_id} ---\n{text_body}")
            continue

        try:
            await bot.send_message(chat_id=chat_id, text=text_body, disable_web_page_preview=True)
            logger.info("chat %s: posted", chat_id)
        except TelegramError as e:
            # A chat the bot was removed from, or one it can no longer write in.
            logger.error("chat %s: refused: %s", chat_id, e)


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--send", action="store_true", help="actually post to the chats")
    args = parser.parse_args()

    if args.send and not TELEGRAM_TOKEN:
        raise SystemExit("TELEGRAM_TOKEN is not set")

    asyncio.run(main(args.send))
