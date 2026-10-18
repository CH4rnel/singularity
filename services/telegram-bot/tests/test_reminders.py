"""Daily reminders delivered from the outbox Laravel fills.

Pinned: nobody who didn't opt in is written to, a reminder opens the wallet's
Daily screen inside Telegram, one that bounces is recorded and not retried, and
the off switch works from the message itself.
"""
import asyncio
import unittest
from unittest.mock import AsyncMock, MagicMock, patch

from sqlalchemy import create_engine, text
from sqlalchemy.pool import StaticPool
from telegram.error import Forbidden

from bot import reminders
from bot.config import WALLET_MINI_APP_URL


def _engine():
    return create_engine("sqlite://", connect_args={"check_same_thread": False}, poolclass=StaticPool)


class ReminderTests(unittest.TestCase):
    def setUp(self):
        self.engine = _engine()
        self._patch = patch.object(reminders, "engine", self.engine)
        self._patch.start()
        reminders.ensure_reminder_schema()

    def tearDown(self):
        self._patch.stop()

    def _queue(self, tg_user_id, body="Серия из 4 дней прервётся сегодня"):
        with self.engine.begin() as conn:
            conn.execute(
                text("INSERT INTO tg_outbox (tg_user_id, kind, text, url) VALUES (:u, 'progress.streak_at_risk', :b, '/wallet?section=daily')"),
                {"u": tg_user_id, "b": body},
            )

    def _row(self):
        with self.engine.connect() as conn:
            return conn.execute(text("SELECT sent_at, error FROM tg_outbox")).fetchone()

    def test_an_opted_in_user_gets_the_nudge_with_a_daily_button(self):
        reminders.set_optin(42, True)
        self._queue(42)
        bot = MagicMock()
        bot.send_message = AsyncMock()

        sent = asyncio.run(reminders.deliver_pending(bot))

        self.assertEqual(sent, 1)
        kwargs = bot.send_message.call_args.kwargs
        self.assertEqual(kwargs["chat_id"], 42)
        button = kwargs["reply_markup"].inline_keyboard[0][0]
        self.assertEqual(button.web_app.url, f"{WALLET_MINI_APP_URL}?section=daily")
        self.assertEqual(kwargs["reply_markup"].inline_keyboard[1][0].callback_data, reminders.OFF_CALLBACK)
        self.assertIsNotNone(self._row()[0])

    def test_nobody_who_switched_off_is_written_to(self):
        reminders.set_optin(42, False)
        self._queue(42)
        bot = MagicMock()
        bot.send_message = AsyncMock()

        asyncio.run(reminders.deliver_pending(bot))

        bot.send_message.assert_not_called()
        self.assertEqual(self._row()[1], "opted_out")

    def test_a_bounce_is_recorded_and_not_retried(self):
        reminders.set_optin(42, True)
        self._queue(42)
        bot = MagicMock()
        bot.send_message = AsyncMock(side_effect=Forbidden("bot was blocked by the user"))

        asyncio.run(reminders.deliver_pending(bot))
        asyncio.run(reminders.deliver_pending(bot))

        self.assertEqual(bot.send_message.await_count, 1)
        self.assertEqual(self._row()[1], "Forbidden")

    def test_the_off_button_switches_reminders_off(self):
        reminders.set_optin(42, True)
        update = MagicMock()
        update.callback_query.from_user.id = 42
        update.callback_query.answer = AsyncMock()

        asyncio.run(reminders.remind_off_callback(update, MagicMock()))

        self.assertFalse(reminders.is_opted_in(42))


if __name__ == "__main__":
    unittest.main()
