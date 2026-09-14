"""Escrow for chat-token games, funded by accrued, unclaimed rewards.

Two games sit on top of this — `bot.rps` and `bot.slots` — and there is exactly
one place that can move a balance, because two would be two ways to pay a stake
twice. Amounts are raw integers in the token's 18 decimals and never floats: a
stake is money, and `0.1 + 0.2` is not what a player typed.

`transaction()` opens SQLite's `BEGIN IMMEDIATE` so two players joining the same
game in the same instant serialise against each other rather than both paying.
"""
import re
from contextlib import contextmanager

from sqlalchemy import text

from bot.db import engine

UNIT = 10**18  # TelegramChatToken uses ERC20's fixed 18 decimals.


@contextmanager
def transaction():
    with engine.connect() as conn:
        conn.exec_driver_sql("BEGIN IMMEDIATE")
        try:
            yield conn
            conn.commit()
        except BaseException:
            conn.rollback()
            raise


def amount(raw):
    whole, fraction = divmod(int(raw), UNIT)
    return str(whole) + ("." + str(fraction).zfill(18).rstrip("0") if fraction else "")


def parse_stake(value):
    if not re.fullmatch(r"[0-9]{1,60}(?:[.,][0-9]{1,18})?", value):
        raise ValueError("Ставка — положительное число, не более 18 знаков после запятой.")
    whole, _, fraction = value.replace(",", ".").partition(".")
    raw = int(whole) * UNIT + int(fraction.ljust(18, "0"))
    if not 0 < raw <= (2**256 - 1) // 2:
        raise ValueError("Ставка слишком большая или равна нулю.")
    return raw


def credit(conn, chat, user, delta):
    args = {"c": chat, "u": user}
    row = conn.execute(text("SELECT amount FROM pending_rewards WHERE chat_id=:c AND user_id=:u"), args).first()
    balance = int(row[0]) if row else 0
    if balance + delta < 0:
        raise ValueError(f"Недостаточно токенов на внутреннем балансе: {amount(balance)}. "
                         "Проверьте /balance; новые награды начисляются за участие в чате.")
    conn.execute(text("""
        INSERT INTO pending_rewards(chat_id,user_id,amount,updated_at)
        VALUES(:c,:u,:a,datetime('now'))
        ON CONFLICT(chat_id,user_id) DO UPDATE SET amount=excluded.amount,updated_at=excluded.updated_at
    """), {**args, "a": str(balance + delta)})


def seen(conn, chat, user):
    """A player is a chat member by the act of staking in it."""
    conn.execute(text("""INSERT INTO chat_members(chat_id,user_id) VALUES(:c,:u)
        ON CONFLICT(chat_id,user_id) DO UPDATE SET last_seen=datetime('now')"""), {"c": chat, "u": user})


def chat_token(conn, chat):
    """The chat's reward token symbol, or a refusal naming how to get one."""
    row = conn.execute(text(
        "SELECT symbol FROM chat_tokens WHERE chat_id=:c AND token_address IS NOT NULL"
    ), {"c": chat}).first()
    if not row:
        raise ValueError("У этого чата ещё нет токена. Администратор может создать его: /create_token.")
    return row[0]
