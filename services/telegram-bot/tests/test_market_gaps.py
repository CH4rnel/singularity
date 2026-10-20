"""A small pool is not its reserve-ratio price, and a repeated gap is not news."""
import asyncio
import json
import os
import tempfile
import time
import unittest
from contextlib import ExitStack
from dataclasses import replace
from datetime import datetime, timedelta, timezone
from decimal import Decimal as D
from unittest.mock import AsyncMock, MagicMock, patch
from urllib.parse import parse_qs, urlsplit

os.environ.setdefault("DB_PATH", os.path.join(tempfile.mkdtemp(prefix="gaps_test_"), "bot.sqlite"))

from bot import market_gaps as gaps  # noqa: E402

USDC = "0xdc25597b19799010047f17e9591efe08efd40077"
BTC = "0x9332081f308bc978fe259237850fa253131b46fa"
USDT = "0x94845af24a3e431593a2b941b2b31836de45185d"


def graph_pair(first, second, reserve_first, reserve_second):
    return {first: {second: (reserve_first, reserve_second)},
            second: {first: (reserve_second, reserve_first)}}


def opportunity(side="buy", edge="10"):
    return gaps.Gap("BTC", side, D(90 if side == "buy" else 110),
                    gaps.Reference(D(100), "CoinGecko"), D(1), "USDC",
                    D(90 if side == "buy" else 110), D(edge), D(10),
                    (USDC, BTC) if side == "buy" else (BTC, USDC))


def result(*rows, observed=("BTC",)):
    return gaps.Scan(tuple(rows), frozenset(observed), 123, 1_700_000_000)


class TradeSizeTests(unittest.TestCase):
    def compare(self, graph, reference="100", stable="1"):
        with patch.object(gaps, "MARKET_GAP_SIZES_USD", "1,10,100"), \
             patch.object(gaps, "MARKET_GAP_MIN_USD", "0.25"), \
             patch.object(gaps, "MARKET_GAP_MIN_BPS", 300), \
             patch.object(gaps, "MARKET_GAP_SLIPPAGE_BPS", 50):
            return gaps._compare(
                {"USDC": USDC, "BTC": BTC}, {USDC: 6, BTC: 8},
                {"USDC": gaps.Reference(D(stable), "CoinGecko"),
                 "BTC": gaps.Reference(D(reference), "CoinGecko")}, graph,
            )

    def test_buy_uses_the_amount_received_after_fee_impact_and_slippage(self):
        rows = self.compare(graph_pair(USDC, BTC, 80_000 * 10**6, 1000 * 10**8))
        self.assertEqual([row.side for row in rows], ["buy"])
        row = rows[0]
        self.assertGreater(row.local, D(80))
        self.assertLess(row.advantage, D(25))  # reserve ratio alone promises $25
        self.assertEqual(row.stable_amount, D(100))
        self.assertGreater(row.amount, D(1))

    def test_premium_is_what_selling_receives_not_the_inverse_of_a_buy(self):
        rows = self.compare(graph_pair(USDC, BTC, 120_000 * 10**6, 1000 * 10**8))
        self.assertEqual([row.side for row in rows], ["sell"])
        self.assertEqual(rows[0].amount, D(1))
        self.assertLess(rows[0].local, D(120))
        self.assertGreater(rows[0].advantage, D(18))

    def test_a_paper_discount_in_a_tiny_pool_is_not_a_buy_signal(self):
        self.assertEqual(self.compare(graph_pair(USDC, BTC, 100_000, 20_000_000), reference="1"), ())

    def test_stablecoin_reference_is_not_assumed_to_be_one_dollar(self):
        graph = graph_pair(USDC, BTC, 100_000 * 10**6, 1000 * 10**8)
        self.assertEqual(self.compare(graph), ())
        self.assertEqual([row.side for row in self.compare(graph, stable="0.8")], ["buy"])

    def test_each_hop_takes_its_fee_and_floors_integer_amounts(self):
        graph = {"a": {"b": (1000, 2000)}, "b": {"c": (3000, 4000)}}
        first = 10 * 997 * 2000 // (1000 * 1000 + 10 * 997)
        second = first * 997 * 4000 // (3000 * 1000 + first * 997)
        with patch.object(gaps, "MARKET_GAP_SLIPPAGE_BPS", 50):
            self.assertEqual(gaps._quote(graph, ("a", "b", "c"), 10), second * 9950 // 10_000)
        self.assertEqual(list(gaps._paths(graph, "a", "c", {"b"})), [("a", "b", "c")])


class ReferenceTests(unittest.TestCase):
    def test_stale_missing_and_nonfinite_quotes_do_not_become_signals(self):
        now = time.time()
        data = {"bitcoin": {"usd": 100, "last_updated_at": now - 301},
                "ethereum": {"usd": "NaN", "last_updated_at": now},
                "solana": {"usd": 20, "last_updated_at": now}}
        with patch.object(gaps, "_http_json", return_value=data), \
             patch.object(gaps, "MARKET_GAP_MAX_AGE_SECONDS", 300):
            refs = gaps._references({"BTC": BTC, "ETH": "eth", "SOL": "sol"}, now)
        self.assertEqual(set(refs), {"SOL"})

    def test_solana_reference_requires_the_mint_depth_and_recent_trading(self):
        pair = {"chainId": "solana", "baseToken": {"address": gaps.CYBER_SOL_MINT},
                "quoteToken": {"address": gaps.WSOL_MINT}, "priceUsd": "0.0016",
                "liquidity": {"usd": 2000}, "txns": {"m5": {"buys": 1, "sells": 0}}}
        with patch.object(gaps, "_http_json", return_value={"pairs": [pair]}):
            self.assertIn("CYBER.sol", gaps._references({"CYBER.sol": "wrapper"}, time.time()))
            pair["baseToken"]["address"] = "different-mint-with-the-same-symbol"
            self.assertEqual(gaps._references({"CYBER.sol": "wrapper"}, time.time()), {})
            pair["baseToken"]["address"] = gaps.CYBER_SOL_MINT
            pair["txns"]["m5"]["buys"] = 0
            self.assertEqual(gaps._references({"CYBER.sol": "wrapper"}, time.time()), {})


class ChainReadTests(unittest.TestCase):
    def setUp(self):
        self.now = time.time()
        self.w3 = MagicMock()
        self.w3.eth.chain_id = 49406
        self.w3.eth.get_block.return_value = {"number": 123, "timestamp": int(self.now)}
        self.calls = []
        factory, pair = MagicMock(), MagicMock()
        pair.functions.getReserves.return_value.call.return_value = (1000 * 10**8, 80_000 * 10**6, 0)
        self.calls.append(pair.functions.getReserves.return_value.call)

        def get_pair(first, second):
            caller = MagicMock()
            caller.call.return_value = "0x" + ("aa" if {first.lower(), second.lower()} == {BTC, USDC} else "00") * 20
            self.calls.append(caller.call)
            return caller

        factory.functions.getPair.side_effect = get_pair

        def contract(address, abi):
            if address.lower() == gaps.RITUAL_V2_FACTORY.lower():
                return factory
            if address.lower() == "0x" + "aa" * 20:
                return pair
            token = MagicMock()
            token.functions.decimals.return_value.call.return_value = 8 if address.lower() == BTC else 6
            self.calls.append(token.functions.decimals.return_value.call)
            return token

        self.w3.eth.contract.side_effect = contract
        self.stack = ExitStack()
        self.addCleanup(self.stack.close)
        constructor = self.stack.enter_context(patch.object(gaps, "Web3", wraps=gaps.Web3))
        constructor.return_value = self.w3
        registry = MagicMock()
        registry.read_text.return_value = json.dumps({"chainId": 49406, "tokens": [
            {"symbol": symbol, "address": address} for symbol, address in (("BTC", BTC), ("USDC", USDC), ("USDT", USDT))
        ]})
        self.stack.enter_context(patch.object(gaps, "REGISTRY", registry))
        self.stack.enter_context(patch.object(gaps, "PRICE_RELAY_TOKENS", []))
        self.stack.enter_context(patch.object(gaps, "USD_ANCHORS", {USDC, USDT}))
        self.stack.enter_context(patch.object(gaps, "CYBER_CA_EVM", ""))
        self.stack.enter_context(patch.object(gaps, "_references", return_value={
            "BTC": gaps.Reference(D(100), "CoinGecko", self.now),
            "USDC": gaps.Reference(D(1), "CoinGecko", self.now),
            "USDT": gaps.Reference(D(1), "CoinGecko", self.now),
        }))

    def test_all_hops_and_decimals_are_read_from_the_same_block(self):
        scan = gaps.scan()
        self.assertIsNotNone(scan)
        self.assertEqual(scan.block, 123)
        self.assertEqual([gap.side for gap in scan.gaps], ["buy"])
        self.w3.middleware_onion.inject.assert_called_once_with(gaps.ExtraDataToPOAMiddleware, layer=0)
        for call in self.calls:
            call.assert_called_once_with(block_identifier=123)

    def test_a_stale_chain_or_wrong_network_is_unknown_not_no_opportunities(self):
        self.w3.eth.get_block.return_value["timestamp"] = int(self.now) - 3600
        self.assertIsNone(gaps.scan())
        self.w3.eth.chain_id = 1
        self.assertIsNone(gaps.scan())

    def test_unreadable_stablecoin_value_cannot_clear_previous_signals(self):
        gaps._references.return_value.pop("USDC")
        self.assertIsNone(gaps.scan())
        self.w3.eth.get_block.assert_not_called()


class PublicationTests(unittest.TestCase):
    def test_a_gap_is_news_once_and_the_clock_does_not_make_it_new(self):
        text, markup, state = gaps.prepare(result(opportunity()), None)
        self.assertIn("market offers", text)
        self.assertIn("Before network, bridge", text)
        self.assertNotIn("here", text)
        button = markup.inline_keyboard[0][0]
        self.assertEqual(button.text, "Buy BTC in Cyberia · 10% cheaper")
        query = parse_qs(urlsplit(button.url).query)
        self.assertEqual(query["chain"], ["49406"])
        self.assertEqual(query["in"], [USDC])
        self.assertEqual(query["out"], [BTC])
        repeated = replace(result(opportunity()), block=124, timestamp=1_700_021_600)
        self.assertIsNone(gaps.prepare(repeated, state)[0])

    def test_slow_drift_compares_to_the_last_published_baseline(self):
        with patch.object(gaps, "MARKET_GAP_CHANGE_BPS", 200):
            _, _, state = gaps.prepare(result(opportunity(edge="10")), None)
            text, _, unchanged = gaps.prepare(result(opportunity(edge="11")), state)
            self.assertIsNone(text)
            self.assertEqual(unchanged, state)
            self.assertIsNotNone(gaps.prepare(result(opportunity(edge="12")), unchanged)[0])

    def test_outage_preserves_state_but_measured_disappearance_resets_it(self):
        _, _, state = gaps.prepare(result(opportunity()), None)
        self.assertEqual(gaps.prepare(None, state), (None, None, None))
        self.assertEqual(gaps.prepare(result(observed=()), state), (None, None, state))
        _, _, cleared = gaps.prepare(result(), state)
        self.assertEqual(json.loads(cleared), {})
        self.assertIsNotNone(gaps.prepare(result(opportunity()), cleared)[0])

    def test_a_side_change_is_new_and_invalid_saved_state_recovers(self):
        _, _, state = gaps.prepare(result(opportunity()), "[]")
        _, markup, state = gaps.prepare(result(opportunity("sell")), state)
        button = markup.inline_keyboard[0][0]
        self.assertEqual(button.text, "Sell BTC in Cyberia · 10% higher")
        query = parse_qs(urlsplit(button.url).query)
        self.assertEqual(query["in"], [BTC])
        self.assertEqual(query["out"], [USDC])
        self.assertEqual(set(json.loads(state)), {"BTC:sell"})

    def test_failed_delivery_retries_then_the_same_gap_stays_silent(self):
        from bot import announcers
        from telegram.error import TelegramError
        now = datetime.now(timezone.utc).replace(microsecond=0)
        state = {announcers._KV_LAST_DIGEST_AT: (now - timedelta(hours=6)).strftime(announcers._SQLITE_TS)}
        clock = MagicMock(wraps=datetime)
        clock.now.return_value = now
        bot = AsyncMock()
        bot.send_message.side_effect = TelegramError("offline")
        with ExitStack() as stack:
            for name, value in (("datetime", clock), ("DIGEST_INTERVAL_SECONDS", 6 * 3600),
                                ("MARKET_GAPS_ENABLED", True), ("engine", MagicMock())):
                stack.enter_context(patch.object(announcers, name, value))
            stack.enter_context(patch.object(announcers, "_kv_get", side_effect=state.get))
            stack.enter_context(patch.object(announcers, "_kv_set", side_effect=state.__setitem__))
            stack.enter_context(patch.object(announcers, "_build_digest_text", return_value=None))
            stack.enter_context(patch.object(announcers, "_cyber_price_line", return_value=None))
            stack.enter_context(patch.object(gaps, "scan", return_value=result(opportunity())))
            asyncio.run(announcers._digest_tick(bot))
            self.assertNotIn(gaps.STATE_KEY, state)
            self.assertNotEqual(state[announcers._KV_LAST_DIGEST_AT], now.strftime(announcers._SQLITE_TS))
            for call in bot.send_message.call_args_list:
                self.assertIn("Buy BTC", call.kwargs["reply_markup"].inline_keyboard[0][0].text)
            bot.send_message.side_effect = None
            asyncio.run(announcers._digest_tick(bot))
            self.assertIn(gaps.STATE_KEY, state)
            self.assertIn("market offers", bot.send_message.call_args.kwargs["text"])
            sent = bot.send_message.await_count
            clock.now.return_value = now + timedelta(hours=6)
            asyncio.run(announcers._digest_tick(bot))
            self.assertEqual(bot.send_message.await_count, sent)


if __name__ == "__main__":
    unittest.main()
