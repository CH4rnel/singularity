"""Rewards accrue off-chain and reach the chain only when somebody asks.

The old design minted to every wallet-holding member on every tick — across
eight tokens on an hourly interval, hundreds of transactions a day that nobody
requested, to people who were not interested enough to sell what arrived. The
off-chain half already existed; it was just reserved for the people who were
not yet users. These tests pin the inversion, and the refusals that keep
/claim from writing to the chain for no reason.
"""
import asyncio
import unittest
from types import SimpleNamespace
from unittest.mock import AsyncMock, patch

from sqlalchemy import create_engine, text

from bot import handlers


def _engine():
    engine = create_engine("sqlite:///:memory:")
    with engine.begin() as conn:
        conn.execute(text("""
            CREATE TABLE chat_tokens (
                chat_id INTEGER PRIMARY KEY, name TEXT NOT NULL, symbol TEXT NOT NULL,
                token_address TEXT, rewards_interval INTEGER NOT NULL DEFAULT 3600,
                reward_amount TEXT NOT NULL DEFAULT '1000000000000000000',
                created_by INTEGER, created_at TEXT, last_payout_at TEXT)
        """))
        conn.execute(text("""
            CREATE TABLE chat_members (
                chat_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
                first_seen TEXT, last_seen TEXT, PRIMARY KEY (chat_id, user_id))
        """))
        conn.execute(text("""
            CREATE TABLE tg_wallets (
                user_id INTEGER PRIMARY KEY, address TEXT NOT NULL, created_at TEXT)
        """))
        conn.execute(text("""
            CREATE TABLE pending_rewards (
                chat_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
                amount TEXT NOT NULL DEFAULT '0', updated_at TEXT,
                PRIMARY KEY (chat_id, user_id))
        """))
        conn.execute(text(
            "INSERT INTO chat_tokens (chat_id, name, symbol, token_address) "
            "VALUES (-100, 'Room', 'ROOM', '0x000000000000000000000000000000000000dEaD')"
        ))
    return engine


def _update(user_id=7, chat_type="private"):
    message = SimpleNamespace(reply_text=AsyncMock(), text="/claim")
    return SimpleNamespace(
        effective_user=SimpleNamespace(id=user_id, is_bot=False, username="u"),
        effective_chat=SimpleNamespace(id=-100, type=chat_type),
        effective_message=message,
    )


class ClaimTests(unittest.TestCase):
    def setUp(self):
        self.engine = _engine()
        self._patch = patch.object(handlers, "engine", self.engine)
        self._patch.start()
        self.addCleanup(self._patch.stop)
        self.context = SimpleNamespace(bot=SimpleNamespace(send_message=AsyncMock()))

    def _owe(self, user_id, amount="5000000000000000000"):
        with self.engine.begin() as conn:
            conn.execute(
                text("INSERT INTO pending_rewards (chat_id, user_id, amount) VALUES (-100, :u, :a)"),
                {"u": user_id, "a": amount},
            )

    def _wallet(self, user_id, address="0x" + "1" * 40):
        with self.engine.begin() as conn:
            conn.execute(
                text("INSERT INTO tg_wallets (user_id, address) VALUES (:u, :a)"),
                {"u": user_id, "a": address},
            )

    def test_nothing_owing_writes_nothing_to_the_chain(self):
        update = _update()
        with patch.object(handlers, "_claim_pending_rewards") as mint:
            asyncio.run(handlers.claim_command(update, self.context))
        mint.assert_not_called()
        self.assertIn("Nothing to claim", update.effective_message.reply_text.call_args[0][0])

    def test_owed_without_a_wallet_asks_for_one_instead_of_minting(self):
        self._owe(7)
        update = _update()
        with patch.object(handlers, "_claim_pending_rewards") as mint:
            asyncio.run(handlers.claim_command(update, self.context))
        mint.assert_not_called()
        said = update.effective_message.reply_text.call_args[0][0]
        self.assertIn("set_wallet", said)
        self.assertIn("ROOM", said)

    def test_a_claim_mints_once_and_answers_privately(self):
        self._owe(7)
        self._wallet(7)
        update = _update()
        with patch.object(handlers, "_claim_pending_rewards", return_value=(1, 0, {"ROOM": 5 * 10**18})) as mint:
            asyncio.run(handlers.claim_command(update, self.context))
        mint.assert_called_once()
        self.assertEqual(mint.call_args[0][0], 7)
        self.context.bot.send_message.assert_awaited_once()
        self.assertIn("Claimed", self.context.bot.send_message.call_args.kwargs["text"])

    def test_a_group_claim_does_not_publish_the_address(self):
        self._owe(7)
        self._wallet(7, "0x" + "ab" * 20)
        update = _update(chat_type="supergroup")
        with patch.object(handlers, "_claim_pending_rewards", return_value=(1, 0, {"ROOM": 10**18})):
            asyncio.run(handlers.claim_command(update, self.context))
        in_group = update.effective_message.reply_text.call_args[0][0]
        self.assertNotIn("0xab", in_group)
        self.assertIn("privately", in_group)

    def test_a_failed_mint_says_the_balance_is_safe(self):
        self._owe(7)
        self._wallet(7)
        update = _update()
        with patch.object(handlers, "_claim_pending_rewards", return_value=(0, 1, {})):
            asyncio.run(handlers.claim_command(update, self.context))
        self.assertIn("safe", self.context.bot.send_message.call_args.kwargs["text"])

    def test_asking_in_a_group_records_the_asker_as_a_member(self):
        update = _update(user_id=42, chat_type="supergroup")
        asyncio.run(handlers.claim_command(update, self.context))
        with self.engine.connect() as conn:
            seen = conn.execute(
                text("SELECT COUNT(*) FROM chat_members WHERE user_id = 42"),
            ).scalar()
        self.assertEqual(seen, 1)

    def test_a_balance_too_large_for_a_signed_integer_is_still_claimable(self):
        # 2861 tokens in wei overflows SQLite's signed 64-bit CAST, which is why
        # the filter compares the stored text instead.
        self._owe(7, "2861000000000000000000")
        self.assertEqual(handlers._pending_for(7), [("ROOM", 2861 * 10**18)])

    def test_a_zero_balance_is_not_something_to_claim(self):
        self._owe(7, "0")
        self.assertEqual(handlers._pending_for(7), [])


if __name__ == "__main__":
    unittest.main()
