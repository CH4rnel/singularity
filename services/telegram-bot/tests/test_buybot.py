"""LAIN v4 buys: real receipt, hook fees, attribution, opt-in and delivery.

The fixture is the operator's actual Robinhood transaction, reduced to the
pool Swap and LAIN Transfer logs. No RPC, Telegram or signing runs in tests.
"""
import asyncio
import copy
import json
import os
import re
import tempfile
import unittest
from decimal import Decimal
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import AsyncMock, patch

os.environ.setdefault("DB_PATH", tempfile.mktemp(prefix="buybot-test-", suffix=".sqlite"))

from sqlalchemy import create_engine, text
from telegram.error import BadRequest, TimedOut

from bot import buybot

RECEIPT = json.loads((Path(__file__).parent / "fixtures/lain_buy_receipt.json").read_text())
BLOCK = int(RECEIPT["blockNumber"], 16)
HASH = RECEIPT["blockHash"]
SWAP = next(log for log in RECEIPT["logs"] if buybot.is_pool_swap(log))


def words(values):
    return "0x" + "".join((n % 2**256).to_bytes(32, "big").hex() for n in values)


class BuyParserTests(unittest.TestCase):
    def test_real_buy_includes_hook_fee_and_actual_recipient(self):
        buy = buybot.parse_buy(RECEIPT)
        self.assertEqual(buy["eth"], Decimal("0.021086084872485602"))
        self.assertEqual(buy["tokens"], Decimal("1322154.959151140762295940"))
        self.assertEqual(buy["received"], Decimal("1282490.310376606539427063"))
        self.assertEqual(buy["receiver"], "0x8b5021c7d7e60b07c4a6a14ea1a29ccf3e62fb64")
        self.assertEqual(buy["sender"], "0x4381045063b19a615a272733fa6b5f169b622646")
        self.assertEqual(buy["tx"], buybot.SAMPLE_TX)
        self.assertGreater(buy["price_eth"], 0)

    def test_sells_and_round_trips_are_not_buys(self):
        for amounts in [(100, -200), (-100, -200), (0, 200)]:
            receipt = copy.deepcopy(RECEIPT)
            swap = next(log for log in receipt["logs"] if buybot.is_pool_swap(log))
            swap["data"] = words([*amounts, 1, 1, 0, 0])
            self.assertIsNone(buybot.parse_buy(receipt))
        receipt = copy.deepcopy(RECEIPT)
        reverse = dict(SWAP, data=words([10**18, -2 * 10**24, 1, 1, 0, 0]), logIndex="0xff")
        receipt["logs"].append(reverse)
        self.assertIsNone(buybot.parse_buy(receipt))

    def test_failed_tx_wrong_pool_or_removed_log_are_not_buys(self):
        failed = dict(RECEIPT, status="0x0")
        self.assertIsNone(buybot.parse_buy(failed))
        for changed in (dict(SWAP, address="0x" + "1" * 40),
                        dict(SWAP, removed=True),
                        dict(SWAP, topics=[buybot.SWAP_TOPIC, "0x" + "2" * 64, SWAP["topics"][2]])):
            self.assertIsNone(buybot.parse_buy(dict(RECEIPT, logs=[changed])))

    def test_multiple_pool_swaps_are_one_net_purchase(self):
        receipt = copy.deepcopy(RECEIPT)
        receipt["logs"].append(dict(SWAP, data=words([-10**15, 10**18, 1, 1, 0, 0]), logIndex="0xff"))
        buy = buybot.parse_buy(receipt)
        self.assertEqual(buy["eth"], Decimal("0.022086084872485602"))
        self.assertEqual(buy["index"], 255)

    def test_uncertain_recipient_never_gets_invented(self):
        buy = buybot.parse_buy(dict(RECEIPT, logs=[SWAP]))
        self.assertIsNone(buy["receiver"])
        self.assertIsNone(buy["received"])
        self.assertIn("1,322,154.96 LAIN", buybot.format_buy(buy))
        self.assertNotIn("Received:", buybot.format_buy(buy))


class CardTests(unittest.TestCase):
    def test_buttons_inherit_telegram_theme_and_link_to_lain_trading_and_project_pages(self):
        rows = buybot.keyboard().to_dict()["inline_keyboard"]
        self.assertTrue(all("style" not in b for row in rows for b in row))
        self.assertEqual(rows[0][0]["url"],
                         "https://ponsfamily.com/launchpad/0x2b79A071a75dd40f1aca68146d7978204eFD156A")
        self.assertEqual(rows[0][1]["url"], "https://x.com/lain___os")
        self.assertEqual(rows[1][0]["url"], "https://lain-os.com/roadmap")
        self.assertEqual(rows[1][1]["url"], "https://cyberia.church/wallet")
        self.assertEqual(rows[1][1]["text"], "🎲 Cyberia Wallet")
        self.assertTrue(all("web_app" not in b for row in rows for b in row))
        self.assertNotIn("Buy", rows[1][1]["text"])  # wallet is not a Pons trading route

    def test_caption_fits_photo_and_never_invents_holder_or_market_cap(self):
        caption = buybot.format_buy(buybot.parse_buy(RECEIPT), Decimal("2358.8"), Decimal(10**9))
        self.assertLessEqual(len(caption.encode("utf-16-le")) // 2, 1024)
        self.assertNotIn("1,282,490.31", caption)
        self.assertIn("1,322,154.96 LAIN", caption)
        self.assertNotIn("Received:", caption)
        self.assertNotIn("fees", caption)
        self.assertIsNone(re.search(r"[А-Яа-яЁё]", caption))
        self.assertIn("FDV", caption)
        self.assertNotIn("Market Cap", caption)
        self.assertNotIn("New Holder", caption)
        self.assertIn("latest ETH quote", caption)
        self.assertIn("💵 <b>0.021086 ETH</b> (≈ $49.74)", caption)
        self.assertNotIn("Pool input:", caption)

    def test_missing_prices_leave_only_verified_amounts(self):
        caption = buybot.format_buy(buybot.parse_buy(RECEIPT))
        self.assertNotIn("$", caption)
        self.assertNotIn("FDV", caption)
        self.assertIn("0.021086 ETH", caption)

    def test_preview_is_explicitly_marked_as_historical(self):
        caption = buybot.format_buy(buybot.parse_buy(RECEIPT), preview=True)
        self.assertTrue(caption.startswith("<b>PREVIEW · historical transaction</b>"))

    def test_photo_rejection_falls_back_but_timeout_does_not_double_send(self):
        for error, fallback in ((BadRequest("image rejected"), True), (TimedOut(), False)):
            bot = SimpleNamespace(send_photo=AsyncMock(side_effect=error), send_message=AsyncMock())
            if fallback:
                asyncio.run(buybot.send_buy(bot, -1, buybot.parse_buy(RECEIPT)))
                bot.send_message.assert_awaited_once()
                self.assertIn("reply_markup", bot.send_message.call_args.kwargs)
            else:
                with self.assertRaises(TimedOut):
                    asyncio.run(buybot.send_buy(bot, -1, buybot.parse_buy(RECEIPT)))
                bot.send_message.assert_not_awaited()


class FakeReader:
    def __init__(self):
        self.head_value = BLOCK + 1
        self.events = [SWAP, dict(SWAP)]  # duplicate provider result
        self.timestamp = 1000

    def head(self):
        return self.head_value

    def block(self, number):
        return {"hash": HASH, "timestamp": self.timestamp}

    def receipt(self, tx):
        return RECEIPT

    def logs(self, start, end):
        return self.events if start <= BLOCK <= end else []

    def supply(self, block):
        return Decimal(10**9)


class DeliveryTests(unittest.TestCase):
    def setUp(self):
        self.engine = create_engine("sqlite:///:memory:")
        with self.engine.begin() as conn:
            conn.execute(text("CREATE TABLE bot_kv (key TEXT PRIMARY KEY, value TEXT)"))
        from bot import db
        self.patchers = [patch.object(buybot, "engine", self.engine),
                         patch.object(db, "engine", self.engine),
                         patch.object(buybot, "eth_quote", return_value=None)]
        for p in self.patchers:
            p.start()
            self.addCleanup(p.stop)
        self.reader = FakeReader()
        self.bot = SimpleNamespace(send_photo=AsyncMock(), send_message=AsyncMock())

    def subscribe(self, chat=-1, block=BLOCK - 1):
        buybot._kv_set(buybot.CHAT_PREFIX + str(chat), "on")
        if block is not None:
            buybot._save(chat, block, buybot.END_OF_BLOCK, HASH)

    def tick(self):
        asyncio.run(buybot.tick(self.bot, self.reader, now=1001))

    def test_disabled_never_queries_rpc(self):
        with patch.object(self.reader, "head", side_effect=AssertionError("RPC should be idle")):
            self.tick()
        self.bot.send_photo.assert_not_awaited()

    def test_first_run_bootstraps_without_historical_posts(self):
        self.subscribe(block=None)
        self.tick()
        self.bot.send_photo.assert_not_awaited()
        state = json.loads(buybot._kv_get(buybot._state_key(-1)))
        self.assertEqual(state["block"], self.reader.head_value)

    def test_buy_delivered_once_and_repeated_poll_is_silent(self):
        self.subscribe()
        self.tick()
        self.tick()
        self.bot.send_photo.assert_awaited_once()

    def test_send_failure_holds_cursor_and_next_tick_retries(self):
        self.subscribe()
        self.bot.send_photo.side_effect = TimedOut()
        self.tick()
        state = json.loads(buybot._kv_get(buybot._state_key(-1)))
        self.assertEqual(state["block"], BLOCK - 1)
        self.bot.send_photo.side_effect = None
        self.tick()
        self.assertEqual(self.bot.send_photo.await_count, 2)
        self.tick()
        self.assertEqual(self.bot.send_photo.await_count, 2)

    def test_one_forbidden_chat_does_not_block_other_chats(self):
        self.subscribe(-1)
        self.subscribe(-2)
        self.bot.send_photo.side_effect = [TimedOut(), None]
        self.tick()
        self.assertEqual(self.bot.send_photo.await_count, 2)
        self.assertEqual(json.loads(buybot._kv_get(buybot._state_key(-1)))["block"], BLOCK - 1)
        self.assertEqual(json.loads(buybot._kv_get(buybot._state_key(-2)))["block"], BLOCK + 1)

    def test_old_purchases_are_skipped_without_replaying_history(self):
        self.subscribe()
        self.reader.timestamp = 1
        with patch.object(buybot, "LAIN_BUYBOT_MAX_AGE_SECONDS", 60):
            self.tick()
        self.bot.send_photo.assert_not_awaited()
        self.assertEqual(json.loads(buybot._kv_get(buybot._state_key(-1)))["block"], BLOCK + 1)

    def test_reorganization_stops_delivery_and_does_not_advance_cursor(self):
        self.subscribe()
        with patch.object(self.reader, "block", return_value={"hash": "0x" + "bb" * 32, "timestamp": 1000}):
            self.tick()
        self.bot.send_photo.assert_not_awaited()
        self.assertEqual(json.loads(buybot._kv_get(buybot._state_key(-1)))["block"], BLOCK - 1)

    def test_unconfirmed_blocks_are_not_read(self):
        self.subscribe()
        self.reader.head_value = BLOCK - 1
        self.tick()
        self.bot.send_photo.assert_not_awaited()

    def test_below_eth_floor_is_skipped_even_without_usd_feed(self):
        self.subscribe()
        with patch.object(buybot, "MIN_ETH", Decimal("1")):
            self.tick()
        self.bot.send_photo.assert_not_awaited()
        self.assertEqual(json.loads(buybot._kv_get(buybot._state_key(-1)))["block"], BLOCK + 1)

    def test_repeated_on_does_not_reset_pending_purchase_cursor(self):
        self.subscribe()
        message = SimpleNamespace(reply_text=AsyncMock())
        update = SimpleNamespace(effective_chat=SimpleNamespace(id=-1, type="supergroup"),
                                 effective_user=SimpleNamespace(id=42), effective_message=message)
        self.bot.get_chat_member = AsyncMock(return_value=SimpleNamespace(status="administrator"))
        with patch.object(buybot, "Reader", side_effect=AssertionError("no reset")):
            asyncio.run(buybot.command(update, SimpleNamespace(args=["on"], bot=self.bot)))
        self.assertEqual(json.loads(buybot._kv_get(buybot._state_key(-1)))["block"], BLOCK - 1)

    def test_non_admin_cannot_subscribe_and_private_chat_is_not_a_destination(self):
        for chat_type, status in (("supergroup", "member"), ("private", "creator")):
            message = SimpleNamespace(reply_text=AsyncMock())
            update = SimpleNamespace(effective_chat=SimpleNamespace(id=-1, type=chat_type),
                                     effective_user=SimpleNamespace(id=42), effective_message=message)
            context = SimpleNamespace(args=["on"], bot=SimpleNamespace(
                get_chat_member=AsyncMock(return_value=SimpleNamespace(status=status))))
            asyncio.run(buybot.command(update, context))
            self.assertEqual(buybot._subscriptions(), [])
            message.reply_text.assert_awaited_once()
            self.assertIsNone(re.search(r"[А-Яа-яЁё]", message.reply_text.call_args.args[0]))

    def test_admin_can_opt_in_preview_and_disable(self):
        message = SimpleNamespace(reply_text=AsyncMock())
        update = SimpleNamespace(effective_chat=SimpleNamespace(id=-1, type="supergroup"),
                                 effective_user=SimpleNamespace(id=42), effective_message=message)
        self.bot.get_chat_member = AsyncMock(return_value=SimpleNamespace(status="administrator"))
        context = SimpleNamespace(args=["on"], bot=self.bot)
        with patch.object(buybot, "Reader", return_value=self.reader):
            asyncio.run(buybot.command(update, context))
            self.assertEqual(buybot._subscriptions(), [-1])
            self.assertIn("enabled", message.reply_text.call_args.args[0])
            self.assertIsNone(re.search(r"[А-Яа-яЁё]", message.reply_text.call_args.args[0]))
            self.bot.send_photo.assert_not_awaited()
            context.args = ["preview"]
            asyncio.run(buybot.command(update, context))
            self.assertIn("PREVIEW", self.bot.send_photo.call_args.kwargs["caption"])
            context.args = ["off"]
            asyncio.run(buybot.command(update, context))
            self.assertEqual(buybot._subscriptions(), [])
            self.assertIn("disabled", message.reply_text.call_args.args[0])


class RpcTests(unittest.TestCase):
    def test_wrong_network_is_never_used_and_rpc_failure_falls_back(self):
        wrong = SimpleNamespace(eth=SimpleNamespace(chain_id=1, block_number=100))
        right = SimpleNamespace(eth=SimpleNamespace(chain_id=4663, block_number=100))
        reader = buybot.Reader()
        reader.clients = [wrong, right]
        with patch.object(buybot, "LAIN_BUYBOT_CONFIRMATIONS", 64):
            self.assertEqual(reader.head(), 36)
        reader.clients = [wrong]
        with self.assertRaisesRegex(RuntimeError, "no verified Robinhood RPC"):
            reader.head()

    def test_market_quote_is_optional_and_nan_is_rejected(self):
        data = {"data": {"attributes": {"address": buybot.POOL_ID, "quote_token_price_usd": "NaN"}}}
        with patch.object(buybot, "_quote_cache", (0, None)), \
                patch.object(buybot, "_http_json", return_value=data):
            self.assertIsNone(buybot.eth_quote())


if __name__ == "__main__":
    unittest.main()
