"""Personal notifications: what happened to *your* wallet, in a private message.

The announcers post what happens on Cyberia to the public chats; nobody there
is told that it was their money. This module writes to the owner instead:

- a bridge delivered — to the recipient, and to the sender if it's someone else;
- coins or a priced token arrived on Cyberia.

An owner is a Telegram account in `tg_wallets` — linked with `/set_wallet`, or
in one tap from the wallet: it signs its address to Laravel, gets a one-time
`start=link_…` deep link (`tg_wallet_links`), and opening it here links the
account. Every message carries the off switch reminders use; switched off means
nothing personal is sent.

Only value is reported. A token with no price is skipped — airdropped dust and
bait named after real coins are the usual reason a token has none — and so are
mints, which is how the bot's own hourly rewards arrive.
"""
import asyncio
import logging
from datetime import datetime, timezone

from sqlalchemy import text
from telegram import InlineKeyboardButton, InlineKeyboardMarkup, WebAppInfo
from telegram.error import TelegramError
from telegram.ext import Application
from web3 import Web3

from bot.announcers import _direction_label
from bot.chain import (
    _address_topic_hex, _decode_topic_address, _event_topic, _get_token_meta,
    _get_token_usd_price, _hex_no_prefix,
)
from bot.config import EVENT_WATCH_POLL_SECONDS, RPC_URL, WALLET_MINI_APP_URL
from bot.db import _kv_get, _kv_set, engine
from bot.reminders import OFF_CALLBACK, is_opted_in
from bot.utils import _fmt_amount, _fmt_usd, _format_decimal_amount, _short_addr
from bot.watchers import _cyber_usd

logger = logging.getLogger(__name__)

TRANSFER_TOPIC = _event_topic("Transfer(address,address,uint256)")
ZERO_ADDRESS = "0x" + "0" * 40
MIN_NOTIFY_USD = 0.01
# Native coin moves are found by reading whole blocks; the cap keeps one tick
# cheap after a restart, and the cursor catches up over the next ones.
MAX_BLOCKS_PER_TICK = 200

BRIDGE_CURSOR = "personal_bridge_id"
BLOCK_CURSOR = "personal_block"

LINKED_TEXT = (
    "Готово: кошелёк {addr} связан с этим Telegram.\n\n"
    "Буду писать сюда, когда на него что-то придёт, когда мост доставит перевод, "
    "и раз в день — если серия заданий под угрозой. Выключить — /remind off."
)

LINK_EXPIRED_TEXT = (
    "Ссылка устарела или уже использована. Откройте кошелёк и нажмите "
    "«Уведомления в Telegram» ещё раз."
)


def ensure_personal_schema() -> None:
    """The table Laravel's migration creates too, whichever side starts first."""
    with engine.begin() as conn:
        conn.execute(text("""
            CREATE TABLE IF NOT EXISTS tg_wallet_links (
                token      TEXT PRIMARY KEY,
                address    TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                used_at    TEXT,
                created_at TEXT
            )
        """))


def _now() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")


def consume_link(token: str, tg_user_id: int) -> str | None:
    """The address a one-time link names, or None if it's unknown, used or old.

    Marked used in the same transaction it's read in, so a link opened twice
    links once.
    """
    now = _now()
    with engine.begin() as conn:
        row = conn.execute(
            text("""
                SELECT address FROM tg_wallet_links
                WHERE token = :t AND used_at IS NULL AND expires_at >= :now
            """),
            {"t": token, "now": now},
        ).fetchone()
        if row is None:
            return None
        conn.execute(
            text("UPDATE tg_wallet_links SET used_at = :now WHERE token = :t"),
            {"t": token, "now": now},
        )
    logger.info(f"personal: linked tg user {tg_user_id} by one-time link")
    return row[0]


def wallet_markup() -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup([
        [InlineKeyboardButton("Открыть кошелёк", web_app=WebAppInfo(url=WALLET_MINI_APP_URL))],
        [InlineKeyboardButton("Выключить уведомления", callback_data=OFF_CALLBACK)],
    ])


def linked_owners() -> dict[str, list[int]]:
    """Lowercased address → the Telegram accounts linked to it."""
    owners: dict[str, list[int]] = {}
    with engine.connect() as conn:
        for user_id, address in conn.execute(text("SELECT user_id, address FROM tg_wallets")):
            if address:
                owners.setdefault(address.lower(), []).append(int(user_id))
    return owners


async def notify(bot, tg_user_id: int, body: str) -> bool:
    if not is_opted_in(tg_user_id):
        return False
    try:
        await bot.send_message(
            chat_id=tg_user_id, text=body,
            reply_markup=wallet_markup(), disable_web_page_preview=True,
        )
    except TelegramError as exc:
        # Never started the bot, blocked it, left Telegram: not retried.
        logger.info(f"personal: not delivered to {tg_user_id}: {type(exc).__name__}")
        return False
    return True


# ------------------------------------------------------------------ bridge --

def bridge_messages(rows, owners: dict[str, list[int]]) -> list[tuple[int, str]]:
    """(tg user, text) for completed bridge requests that touch a linked wallet."""
    out: list[tuple[int, str]] = []
    for _id, direction, token, sender, recipient, amount in rows:
        what = f"{_format_decimal_amount(amount)} {token}"
        route = _direction_label(direction)
        to_owner = owners.get((recipient or "").lower(), [])
        for tg in to_owner:
            out.append((tg, f"Мост доставил {what} ({route}) на {_short_addr(recipient)}."))
        for tg in owners.get((sender or "").lower(), []):
            if tg not in to_owner:
                out.append((tg, f"Ваш перевод через мост дошёл: {what} ({route}) на {_short_addr(recipient)}."))
    return out


def bridge_tick() -> list[tuple[int, str]]:
    last = _kv_get(BRIDGE_CURSOR)
    with engine.connect() as conn:
        if last is None:
            # History isn't news: start after whatever is already done.
            top = conn.execute(text(
                "SELECT COALESCE(MAX(id), 0) FROM bridge_requests WHERE status = 'completed'"
            )).scalar() or 0
            _kv_set(BRIDGE_CURSOR, str(top))
            return []
        rows = conn.execute(
            text("""
                SELECT id, direction, token, sender_address, recipient_address, amount
                FROM bridge_requests
                WHERE status = 'completed' AND id > :last
                ORDER BY id ASC LIMIT 100
            """),
            {"last": int(last)},
        ).fetchall()
    if not rows:
        return []
    _kv_set(BRIDGE_CURSOR, str(rows[-1][0]))
    owners = linked_owners()
    return bridge_messages(rows, owners) if owners else []


# --------------------------------------------------------------- transfers --

def _arrived(amount: str, symbol: str, usd: float | None, sender: str) -> str:
    worth = f" (≈ {_fmt_usd(usd)})" if usd is not None else ""
    return f"Пришло {amount} {symbol}{worth} от {_short_addr(sender)}."


def token_messages(w3: Web3, logs, owners: dict[str, list[int]]) -> list[tuple[int, str]]:
    out: list[tuple[int, str]] = []
    for log in logs:
        topics = log["topics"]
        if len(topics) < 3:
            continue
        sender = _decode_topic_address(topics[1])
        recipient = _decode_topic_address(topics[2]).lower()
        if sender.lower() == ZERO_ADDRESS or sender.lower() == recipient or recipient not in owners:
            continue
        data = _hex_no_prefix(log["data"])
        if not data:
            continue
        value = int(data[:64], 16)
        token = log["address"]
        price = _get_token_usd_price(w3, token)
        if price is None or value == 0:
            continue
        symbol, decimals = _get_token_meta(w3, token)
        amount = value / 10**decimals
        usd = amount * price
        if usd < MIN_NOTIFY_USD:
            continue
        for tg in owners[recipient]:
            out.append((tg, _arrived(_fmt_amount(amount), symbol, usd, sender)))
    return out


def native_messages(w3: Web3, blocks, owners: dict[str, list[int]]) -> list[tuple[int, str]]:
    out: list[tuple[int, str]] = []
    for block in blocks:
        for tx in block["transactions"]:
            recipient = (tx.get("to") or "").lower()
            if recipient not in owners or not tx.get("value"):
                continue
            sender = tx.get("from") or ""
            if sender.lower() == recipient:
                continue
            usd = _cyber_usd(w3, int(tx["value"]))
            if usd is not None and usd < MIN_NOTIFY_USD:
                continue
            for tg in owners[recipient]:
                out.append((tg, _arrived(_fmt_amount(int(tx["value"]) / 10**18), "CYBER", usd, sender)))
    return out


def transfer_tick(w3: Web3) -> list[tuple[int, str]]:
    head = w3.eth.block_number
    last = _kv_get(BLOCK_CURSOR)
    if last is None:
        _kv_set(BLOCK_CURSOR, str(head))
        return []
    start = int(last) + 1
    end = min(head, start + MAX_BLOCKS_PER_TICK - 1)
    if end < start:
        return []

    owners = linked_owners()
    out: list[tuple[int, str]] = []
    if owners:
        logs = w3.eth.get_logs({
            "fromBlock": start, "toBlock": end,
            "topics": [TRANSFER_TOPIC, None, [_address_topic_hex(a) for a in owners]],
        })
        out += token_messages(w3, logs, owners)
        blocks = [w3.eth.get_block(n, full_transactions=True) for n in range(start, end + 1)]
        out += native_messages(w3, blocks, owners)
    _kv_set(BLOCK_CURSOR, str(end))
    return out


async def personal_loop(application: Application) -> None:
    w3 = Web3(Web3.HTTPProvider(RPC_URL))
    while True:
        for name, tick in (("bridge", bridge_tick), ("transfers", lambda: transfer_tick(w3))):
            try:
                messages = await asyncio.to_thread(tick)
            except Exception as exc:  # pragma: no cover - keep the loop alive
                logger.warning(f"personal {name}: {exc}")
                continue
            for tg_user_id, body in messages:
                await notify(application.bot, tg_user_id, body)
        await asyncio.sleep(EVENT_WATCH_POLL_SECONDS)
