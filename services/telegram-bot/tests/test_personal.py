"""Personal notifications and the one-tap link from the wallet.

Pinned: a link links once and not after it expires; a bridge delivery reaches
the recipient (and a different sender); funds arriving are reported only when
they're worth something and aren't a mint; switched-off accounts get nothing.

Env is pinned before bot.* imports because bot.config reads it at import time.
"""
import asyncio
import os
import tempfile
import unittest
from unittest.mock import AsyncMock, MagicMock, patch

_TMP = tempfile.mkdtemp(prefix="personal_test_")
os.environ["DB_PATH"] = os.path.join(_TMP, "personal_test.sqlite")
os.environ.pop("DEPLOYER_PK", None)

from sqlalchemy import text  # noqa: E402

from bot import config, personal, reminders  # noqa: E402
from bot.db import engine  # noqa: E402

if config.DB_PATH != os.environ["DB_PATH"]:
    raise unittest.SkipTest(
        "bot.config was imported before the test DB could be pinned; "
        "run standalone: python -m unittest tests.test_personal"
    )

OWNER = "0x" + "ab" * 20
OTHER = "0x" + "cd" * 20
TOKEN = "0x" + "22" * 20


def topic(address: str) -> str:
    return "0x" + address[2:].rjust(64, "0")


def transfer(sender: str, recipient: str, value: int) -> dict:
    return {
        "address": TOKEN,
        "topics": [personal.TRANSFER_TOPIC, topic(sender), topic(recipient)],
        "data": "0x" + format(value, "064x"),
    }


class PersonalTests(unittest.TestCase):
    def setUp(self):
        personal.ensure_personal_schema()
        reminders.ensure_reminder_schema()
        with engine.begin() as conn:
            conn.execute(text("DELETE FROM tg_wallet_links"))
            conn.execute(text("DELETE FROM tg_reminder_optins"))

    def _link(self, token: str, expires_at: str = "2999-01-01 00:00:00"):
        with engine.begin() as conn:
            conn.execute(
                text("INSERT INTO tg_wallet_links (token, address, expires_at) VALUES (:t, :a, :e)"),
                {"t": token, "a": OWNER, "e": expires_at},
            )

    def test_a_link_links_once(self):
        self._link("abc")
        self.assertEqual(personal.consume_link("abc", 42), OWNER)
        self.assertIsNone(personal.consume_link("abc", 42))

    def test_an_expired_or_unknown_link_links_nothing(self):
        self._link("old", expires_at="2000-01-01 00:00:00")
        self.assertIsNone(personal.consume_link("old", 42))
        self.assertIsNone(personal.consume_link("nope", 42))

    def test_a_bridge_delivery_reaches_the_recipient_and_a_different_sender(self):
        owners = {OWNER: [1], OTHER: [2]}
        rows = [(7, "sol_to_evm", "USDC", OTHER, OWNER, "150.000000")]
        messages = personal.bridge_messages(rows, owners)
        self.assertEqual([tg for tg, _ in messages], [1, 2])
        self.assertIn("150 USDC", messages[0][1])

    def test_only_priced_non_mint_transfers_are_reported(self):
        owners = {OWNER: [1]}
        logs = [
            transfer(OTHER, OWNER, 5 * 10**18),                 # priced: reported
            transfer(personal.ZERO_ADDRESS, OWNER, 10**18),     # a mint: not
            transfer(OTHER, OTHER, 10**18),                     # not ours: not
        ]
        with patch.object(personal, "_get_token_usd_price", return_value=2.0), \
             patch.object(personal, "_get_token_meta", return_value=("ASH", 18)):
            messages = personal.token_messages(None, logs, owners)
        self.assertEqual(len(messages), 1)
        self.assertIn("5 ASH", messages[0][1])

    def test_an_unpriced_token_is_never_reported(self):
        with patch.object(personal, "_get_token_usd_price", return_value=None), \
             patch.object(personal, "_get_token_meta", return_value=("USDC", 6)):
            messages = personal.token_messages(None, [transfer(OTHER, OWNER, 10**9)], {OWNER: [1]})
        self.assertEqual(messages, [])

    def test_native_coin_arriving_is_reported(self):
        block = {"transactions": [{"from": OTHER, "to": OWNER, "value": 3 * 10**18}]}
        with patch.object(personal, "_cyber_usd", return_value=0.15):
            messages = personal.native_messages(None, [block], {OWNER: [1]})
        self.assertEqual(len(messages), 1)
        self.assertIn("3 CYBER", messages[0][1])

    def test_a_new_token_is_marked_not_reported(self):
        moved, updates = personal.price_moves([(TOKEN, "ASH", 2.0)], {}, now=1000.0)
        self.assertEqual(moved, [])
        self.assertEqual(updates[TOKEN], (2.0, 1000.0))

    def test_a_ten_percent_move_is_reported_and_resets_the_mark(self):
        marks = {TOKEN: (2.0, 0.0)}
        moved, updates = personal.price_moves([(TOKEN, "ASH", 2.25)], marks, now=personal.PRICE_QUIET_SECONDS)
        self.assertEqual(moved, [(TOKEN, "ASH", 2.0, 2.25)])
        self.assertEqual(updates[TOKEN][0], 2.25)

    def test_small_moves_and_recent_reports_stay_quiet(self):
        small, _ = personal.price_moves([(TOKEN, "ASH", 2.1)], {TOKEN: (2.0, 0.0)}, now=10**9)
        recent, _ = personal.price_moves([(TOKEN, "ASH", 3.0)], {TOKEN: (2.0, 100.0)}, now=200.0)
        self.assertEqual((small, recent), ([], []))

    def test_a_move_says_which_way_and_what_it_means_for_you(self):
        self.assertIn("упал на 20%", personal._move_text("CYBER", 0.05, 0.04, 1000))
        self.assertIn("1,000 CYBER ≈ $40", personal._move_text("CYBER", 0.05, 0.04, 1000))

    def test_nothing_is_sent_to_someone_who_switched_off(self):
        reminders.set_optin(1, False)
        bot = MagicMock()
        bot.send_message = AsyncMock()
        self.assertFalse(asyncio.run(personal.notify(bot, 1, "hi")))
        bot.send_message.assert_not_called()


if __name__ == "__main__":
    unittest.main()
