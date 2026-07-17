"""Tests for reply-based chat-token rewards triggered by thank-you messages."""
import asyncio
import unittest
from types import SimpleNamespace
from unittest.mock import AsyncMock

from sqlalchemy import create_engine, text

from bot import handlers


def _make_engine():
    engine = create_engine("sqlite:///:memory:")
    with engine.begin() as conn:
        conn.execute(text("""
            CREATE TABLE chat_tokens (
                chat_id           INTEGER PRIMARY KEY,
                name              TEXT NOT NULL,
                symbol            TEXT NOT NULL,
                token_address     TEXT,
                rewards_interval  INTEGER NOT NULL,
                reward_amount     TEXT NOT NULL DEFAULT '1000000000000000000',
                created_by        INTEGER NOT NULL,
                created_at        TEXT DEFAULT (datetime('now')),
                last_payout_at    TEXT
            )
        """))
        conn.execute(text("""
            CREATE TABLE chat_members (
                chat_id    INTEGER NOT NULL,
                user_id    INTEGER NOT NULL,
                first_seen TEXT DEFAULT (datetime('now')),
                last_seen  TEXT DEFAULT (datetime('now')),
                PRIMARY KEY (chat_id, user_id)
            )
        """))
        conn.execute(text("""
            CREATE TABLE tg_wallets (
                user_id    INTEGER PRIMARY KEY,
                address    TEXT NOT NULL,
                created_at TEXT DEFAULT (datetime('now'))
            )
        """))
        conn.execute(text("""
            CREATE TABLE pending_rewards (
                chat_id    INTEGER NOT NULL,
                user_id    INTEGER NOT NULL,
                amount     TEXT NOT NULL DEFAULT '0',
                updated_at TEXT DEFAULT (datetime('now')),
                PRIMARY KEY (chat_id, user_id)
            )
        """))
    return engine


def _group_update(text_value: str, sender_id: int = 1, recipient_id: int = 2):
    sender = SimpleNamespace(id=sender_id, is_bot=False, full_name="Sender", username="sender")
    recipient = SimpleNamespace(
        id=recipient_id, is_bot=False, full_name="Recipient", username="recipient"
    )
    replied = SimpleNamespace(from_user=recipient)
    message = SimpleNamespace(
        message_id=77,
        text=text_value,
        reply_to_message=replied,
        reply_text=AsyncMock(),
    )
    chat = SimpleNamespace(id=-10042, type="supergroup")
    return SimpleNamespace(
        effective_chat=chat,
        effective_message=message,
        effective_user=sender,
        message=message,
    )


class ThankYouTextTests(unittest.TestCase):
    def test_detects_thank_you_triggers(self):
        self.assertTrue(handlers._is_thank_you_text("Thanks!"))
        self.assertTrue(handlers._is_thank_you_text("thank you"))
        self.assertTrue(handlers._is_thank_you_text("Thank    you"))
        self.assertTrue(handlers._is_thank_you_text("Спасибо!"))
        self.assertTrue(handlers._is_thank_you_text("огромное спасибо тебе"))

    def test_ignores_non_trigger_text(self):
        self.assertFalse(handlers._is_thank_you_text("неспасибо"))
        self.assertFalse(handlers._is_thank_you_text("thanksgiving"))
        self.assertFalse(handlers._is_thank_you_text(None))


class ThankYouRewardHandlerTests(unittest.TestCase):
    def setUp(self):
        self.original_engine = handlers.engine
        self.engine = _make_engine()
        handlers.engine = self.engine
        with self.engine.begin() as conn:
            conn.execute(
                text("""
                    INSERT INTO chat_tokens
                        (chat_id, name, symbol, token_address, rewards_interval,
                         reward_amount, created_by)
                    VALUES
                        (:chat_id, 'Cyberia Chat', 'CCHAT',
                         '0x0000000000000000000000000000000000000001',
                         3600, '1000000000000000000', 1)
                """),
                {"chat_id": -10042},
            )

    def tearDown(self):
        handlers.engine = self.original_engine

    def test_reply_spasibo_queues_pending_reward_when_recipient_has_no_wallet(self):
        update = _group_update("thanks!")
        context = SimpleNamespace(user_data={})

        asyncio.run(handlers.thank_you_reward_handler(update, context))

        with self.engine.connect() as conn:
            pending = conn.execute(
                text("""
                    SELECT amount FROM pending_rewards
                    WHERE chat_id = :c AND user_id = :u
                """),
                {"c": -10042, "u": 2},
            ).scalar()
            members = conn.execute(
                text("SELECT user_id FROM chat_members ORDER BY user_id")
            ).fetchall()

        self.assertEqual(pending, "1000000000000000000")
        self.assertEqual([row[0] for row in members], [1, 2])
        update.message.reply_text.assert_awaited_once()
        self.assertEqual(context.user_data["ai_skip_message_id"], 77)

    def test_non_reply_spasibo_is_ignored(self):
        update = _group_update("спасибо")
        update.message.reply_to_message = None
        context = SimpleNamespace(user_data={})

        asyncio.run(handlers.thank_you_reward_handler(update, context))

        with self.engine.connect() as conn:
            count = conn.execute(text("SELECT COUNT(*) FROM pending_rewards")).scalar()

        self.assertEqual(count, 0)
        update.message.reply_text.assert_not_awaited()


if __name__ == "__main__":
    unittest.main()
