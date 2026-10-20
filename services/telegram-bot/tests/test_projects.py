"""Per-group project identity, administrator setup and isolated buy delivery."""
import asyncio
import os
import tempfile
import unittest
from types import SimpleNamespace
from unittest.mock import AsyncMock, patch

os.environ.setdefault("DB_PATH", tempfile.mktemp(prefix="projects-test-", suffix=".sqlite"))

from sqlalchemy import create_engine, text
from telegram.error import TelegramError, TimedOut

from bot import announcers, buybot, config, db, handlers, projects, pumpfun

MINT_A = "E67WWiQY4s9SZbCyFVTh2CEjorEYbhuVJQUZb3Mbpump"
MINT_B = "EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v"


class ProjectTestCase(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix="project-db-")
        self.addCleanup(temporary.cleanup)
        self.engine = create_engine("sqlite:///" + os.path.join(temporary.name, "test.sqlite"),
                                    connect_args={"check_same_thread": False})
        with self.engine.begin() as conn:
            conn.execute(text("CREATE TABLE bot_kv (key TEXT PRIMARY KEY, value TEXT)"))
        self.addCleanup(self.engine.dispose)
        for module in (db, buybot):
            patcher = patch.object(module, "engine", self.engine)
            patcher.start()
            self.addCleanup(patcher.stop)
        self.chat = SimpleNamespace(id=-101, type="supergroup", username="project_a")
        self.message = SimpleNamespace(reply_text=AsyncMock())
        self.update = SimpleNamespace(effective_chat=self.chat,
                                      effective_user=SimpleNamespace(id=42),
                                      effective_message=self.message, message=self.message)
        self.bot = SimpleNamespace(
            get_chat_member=AsyncMock(return_value=SimpleNamespace(status="administrator")),
            send_photo=AsyncMock(), send_message=AsyncMock(),
        )
        self.context = SimpleNamespace(args=[], bot=self.bot)

    def command(self, *args):
        self.context.args = list(args)
        asyncio.run(projects.command(self.update, self.context))

    def configure(self, chat_id=-101, mint=MINT_A, symbol="ALPHA", **settings):
        project = dict(projects.solana_project(mint, symbol, symbol), **settings)
        projects.save(chat_id, project)
        return projects.saved(chat_id)

    def reply(self):
        return self.message.reply_text.call_args.args[0]


class ProjectTests(ProjectTestCase):
    def test_setup_persists_only_in_the_current_group_and_requires_opt_in(self):
        self.command("setup", "solana", MINT_B, "BETA", "Beta", "Token")

        project = projects.for_chat(self.chat)
        self.assertEqual(project["address"], MINT_B)
        self.assertEqual(project["name"], "Beta Token")
        self.assertFalse(project["enabled"])
        self.assertEqual(project["x"], "")
        self.assertEqual(project["photo"], "")
        self.assertIsNone(projects.saved(-102))
        self.assertIn("Alerts are disabled", self.reply())
        self.bot.send_message.assert_not_awaited()

    def test_contract_and_social_replies_follow_the_chat_for_commands_and_bare_text(self):
        self.configure(x="https://x.com/alpha")
        self.configure(-102, MINT_B, "BETA", x="https://x.com/beta")
        for chat_id, mint, symbol, social in (
            (-101, MINT_A, "ALPHA", "https://x.com/alpha"),
            (-102, MINT_B, "BETA", "https://x.com/beta"),
        ):
            with self.subTest(chat=chat_id):
                self.chat.id = chat_id
                asyncio.run(handlers.ca_command(self.update, self.context))
                command_reply = self.reply()
                self.assertIn(f"<code>{mint}</code>", command_reply)
                self.assertIn(symbol, command_reply)
                self.assertNotIn("Cyberia EVM", command_reply)
                self.message.text = "ca?"
                asyncio.run(handlers.quick_reply_handler(self.update, self.context))
                self.assertEqual(self.reply(), command_reply)
                self.assertEqual(self.message.reply_text.call_args.kwargs["parse_mode"], "HTML")
                asyncio.run(handlers.x_command(self.update, self.context))
                self.assertEqual(self.reply(), "𝕏 (Twitter): " + social)

    def test_unknown_chat_gets_setup_instructions_without_cyber_addresses(self):
        asyncio.run(handlers.ca_command(self.update, self.context))

        self.assertIn("No token is configured for this chat", self.reply())
        self.assertNotIn(MINT_A, self.reply())
        self.assertIn("&lt;mint&gt;", self.reply())
        self.assertNotIn("<mint>", self.reply())
        self.command("on")
        self.assertIsNone(projects.saved(self.chat.id))

    def test_existing_lain_subscription_uses_lain_identity(self):
        db._kv_set(buybot.CHAT_PREFIX + str(self.chat.id), "on")

        asyncio.run(handlers.ca_command(self.update, self.context))
        self.assertIn(buybot.TOKEN, self.reply())
        self.assertIn("Robinhood Chain", self.reply())
        self.assertNotIn(MINT_A, self.reply())
        asyncio.run(handlers.x_command(self.update, self.context))
        self.assertEqual(self.reply(), "𝕏 (Twitter): https://x.com/lain___os")
        for trigger in ("x", "twitter"):
            with self.subTest(trigger=trigger):
                self.message.text = trigger
                asyncio.run(handlers.quick_reply_handler(self.update, self.context))
                self.assertEqual(self.reply(), "𝕏 (Twitter): https://x.com/lain___os")

    def test_disabled_legacy_lain_subscription_keeps_its_token_identity(self):
        db._kv_set(buybot.CHAT_PREFIX + str(self.chat.id), "off")

        asyncio.run(handlers.ca_command(self.update, self.context))

        self.assertIn(buybot.TOKEN, self.reply())
        self.assertFalse(projects.for_chat(self.chat)["enabled"])

    def test_cyberia_defaults_are_limited_to_configured_destinations(self):
        with patch.object(config, "BRIDGE_ANNOUNCE_CHAT", "@project_a"):
            asyncio.run(handlers.ca_command(self.update, self.context))

        self.assertIn(config.CYBER_CA_SOLANA, self.reply())
        self.assertIn(config.CYBER_CA_EVM, self.reply())

    def test_editing_legacy_cyberia_settings_preserves_enabled_alerts(self):
        with patch.object(config, "PUMPFUN_ANNOUNCE_CHAT", "@project_a"):
            self.command("set", "x", "https://x.com/updated")

        self.assertTrue(projects.saved(self.chat.id)["enabled"])
        self.assertEqual(projects.saved(self.chat.id)["x"], "https://x.com/updated")

    def test_admin_can_change_and_remove_links_without_inheriting_global_links(self):
        self.configure()
        self.command("set", "website", "https://alpha.example")
        asyncio.run(handlers.website_command(self.update, self.context))
        self.assertEqual(self.reply(), "https://alpha.example")
        self.command("set", "x", "https://x.com/alpha")
        self.command("set", "x", "off")
        asyncio.run(handlers.x_command(self.update, self.context))
        self.assertEqual(self.reply(), "Social links are not configured for this chat.")
        self.assertNotIn("cyberia_chain", str(handlers._main_menu_kb(self.chat).to_dict()))

    def test_non_admin_and_private_chat_cannot_configure_or_enable_alerts(self):
        for chat_type, status in (("supergroup", "member"), ("private", "creator")):
            for args in (("setup", "lain"), ("on",), ("off",),
                         ("set", "x", "https://x.com/attacker"), ("reset",), ("preview",)):
                with self.subTest(chat_type=chat_type, args=args):
                    self.chat.type = chat_type
                    self.bot.get_chat_member.return_value.status = status
                    self.command(*args)
                    self.assertIsNone(projects.saved(self.chat.id))
                    self.assertIsNone(db._kv_get(buybot.CHAT_PREFIX + str(self.chat.id)))
                    self.bot.send_photo.assert_not_awaited()

    def test_failed_admin_lookup_cannot_change_settings(self):
        self.bot.get_chat_member.side_effect = TelegramError("unavailable")

        self.command("setup", "lain")

        self.assertIsNone(projects.saved(self.chat.id))
        self.assertIn("Could not verify", self.reply())

    def test_invalid_settings_leave_the_project_unchanged(self):
        original = self.configure()
        for field, value in (("min_usd", "NaN"), ("min_usd", "inf"), ("min_usd", "-1"),
                             ("emoji_usd", "0"), ("website", "javascript:alert(1)"),
                             ("website", "http://alpha.example"), ("website", "https://user:pass@alpha.example"),
                             ("website", "https://alpha.example\nnext"), ("symbol", "A" * 17),
                             ("name", "line\nbreak"), ("enabled", "true")):
            with self.subTest(field=field, value=value):
                self.command("set", field, value)
                self.assertEqual(projects.saved(self.chat.id), original)
                self.assertNotIn("Updated", self.reply())

    def test_invalid_mint_and_unsupported_network_do_not_configure_a_token(self):
        for args in (("solana", "not-a-mint", "BAD"),
                     ("solana", "1" * 44, "BAD"), ("ethereum", "0x1234", "BAD")):
            with self.subTest(args=args):
                self.command("setup", *args)
                self.assertIsNone(projects.saved(self.chat.id))

    def test_changing_token_disables_legacy_subscription_and_alerts(self):
        db._kv_set(buybot.CHAT_PREFIX + str(self.chat.id), "on")

        self.command("setup", "solana", MINT_B, "BETA")

        self.assertEqual(db._kv_get(buybot.CHAT_PREFIX + str(self.chat.id)), "off")
        self.assertFalse(projects.saved(self.chat.id)["enabled"])
        self.assertEqual(projects.for_chat(self.chat)["address"], MINT_B)

    def test_enabling_starts_at_head_repeated_on_keeps_cursor_and_reset_restarts(self):
        self.configure()
        with patch.object(pumpfun, "market_snapshot", return_value={"pool": "pool-a"}), \
                patch.object(pumpfun, "head_cursor", return_value=(100, 3)) as head:
            self.command("on")
            first = projects.saved(self.chat.id)
            key = pumpfun.project_cursor_key(self.chat.id, first)
            self.assertTrue(first["enabled"])
            self.assertEqual(db._get_block_cursor(key), (100, 3))
            self.command("on")
            head.assert_called_once()
            self.command("reset")
            self.assertNotEqual(projects.saved(self.chat.id)["epoch"], first["epoch"])
            self.assertEqual(head.call_count, 2)
            self.command("off")
            self.assertFalse(projects.saved(self.chat.id)["enabled"])
        self.bot.send_message.assert_not_awaited()

    def test_unavailable_pool_does_not_enable_alerts(self):
        original = self.configure()
        with patch.object(pumpfun, "market_snapshot", return_value=None):
            self.command("on")

        self.assertEqual(projects.saved(self.chat.id), original)
        self.assertIn("Settings were not changed", self.reply())

    def test_lain_preset_routes_to_the_existing_verified_scanner(self):
        self.command("setup", "lain")
        reader = SimpleNamespace(head=lambda: 100, block=lambda number: {"hash": "0xabc"})
        with patch.object(buybot, "Reader", return_value=reader):
            self.command("on")

        self.assertTrue(projects.saved(self.chat.id)["enabled"])
        self.assertEqual(buybot._subscriptions(), [self.chat.id])
        self.command("off")
        self.assertEqual(buybot._subscriptions(), [])

    def test_custom_token_stats_never_read_the_cyberia_digest(self):
        self.configure(-101, MINT_B, "BETA")
        with patch.object(handlers, "_build_digest_text", side_effect=AssertionError("wrong project")), \
                patch.object(pumpfun, "market_snapshot", return_value={"price_usd": 0.25}) as market:
            asyncio.run(handlers.stats_command(self.update, self.context))

        market.assert_called_once_with(MINT_B)
        self.assertIn("BETA", self.reply())
        self.assertIn("Price: $0.25", self.reply())
        self.assertIn("FDV: unavailable", self.reply())

    def test_legacy_cyber_buys_remain_in_the_feed_without_posting_to_another_project(self):
        self.configure()
        db._set_block_cursor("last_announced_pumpfun_cursor", 100, 0)
        buy = {"sol_amount": 1, "token_amount": 10, "signature": "tx", "buyer": "buyer",
               "new_holder": True, "slot": 101, "tx_index": 0}
        with patch.object(announcers, "PUMPFUN_ANNOUNCE_CHAT", str(self.chat.id)), \
                patch.object(announcers, "PUMPFUN_POOL_ADDRESS", ""), \
                patch.object(announcers, "_record_activity") as record, \
                patch.object(pumpfun, "market_snapshot", return_value={"pool": "pool", "sol_usd": 100}), \
                patch.object(pumpfun, "token_supply", return_value=None), \
                patch.object(pumpfun, "collect_buys", return_value=([buy], (101, 0))):
            asyncio.run(announcers._announce_pumpfun_tick(self.bot))

        record.assert_called_once()
        self.assertEqual(record.call_args.kwargs["kind"], "pumpfun_buy")
        self.assertEqual(db._get_block_cursor("last_announced_pumpfun_cursor"), (101, 0))
        self.bot.send_message.assert_not_awaited()
        self.bot.send_photo.assert_not_awaited()

    def test_global_ecosystem_broadcasts_are_suppressed_for_another_project(self):
        self.configure()

        asyncio.run(announcers._send_cyber_message(self.bot, chat_id=self.chat.id, text="Cyberia digest"))

        self.bot.send_message.assert_not_awaited()

    def test_legacy_lain_groups_do_not_receive_cyberia_broadcasts(self):
        db._kv_set(buybot.CHAT_PREFIX + str(self.chat.id), "on")

        asyncio.run(announcers._send_cyber_message(self.bot, chat_id=self.chat.id, text="Cyberia digest"))

        self.bot.send_message.assert_not_awaited()

    def test_public_destination_is_resolved_and_cyberia_projects_keep_broadcasts(self):
        self.configure()
        self.bot.get_chat = AsyncMock(return_value=self.chat)
        asyncio.run(announcers._send_cyber_message(self.bot, chat_id="@project_a", text="Cyberia digest"))
        self.bot.send_message.assert_not_awaited()
        projects.save(self.chat.id, projects.cyber_project())

        asyncio.run(announcers._send_cyber_message(self.bot, chat_id="@project_a", text="Cyberia digest"))

        self.bot.send_message.assert_awaited_once_with(chat_id=-101, text="Cyberia digest")

    def test_preview_uses_only_the_current_chats_verified_token_purchase(self):
        self.configure(-101, MINT_B, "BETA")
        buy = {"sol_amount": 1, "token_amount": 10, "signature": "tx", "buyer": "buyer"}
        with patch.object(pumpfun, "market_snapshot", return_value={"pool": "pool-b", "sol_usd": 100}), \
                patch.object(pumpfun, "_signatures", return_value=[{"signature": "tx"}]), \
                patch.object(pumpfun, "_get_transaction", return_value={"slot": 100}), \
                patch.object(pumpfun, "parse_buy", return_value=buy) as parser:
            self.command("preview")

        parser.assert_called_once_with({"slot": 100}, "pool-b", MINT_B)
        self.assertIn("PREVIEW", self.bot.send_message.call_args.kwargs["text"])
        self.assertIn("BETA", self.bot.send_message.call_args.kwargs["text"])
        self.assertFalse(projects.saved(self.chat.id)["enabled"])


class ProjectDeliveryTests(ProjectTestCase):
    def subscribe(self, chat_id=-101, mint=MINT_A, symbol="ALPHA", **settings):
        project = self.configure(chat_id, mint, symbol, enabled=True, epoch="run-1", pool="pool-" + symbol,
                                 **settings)
        db._set_block_cursor(pumpfun.project_cursor_key(chat_id, project), 100, 0)
        return project

    def tick(self, **overrides):
        buy = {
            "sol_amount": 1.0, "token_amount": 10.0, "signature": "tx-a", "buyer": "buyer-a",
            "slot": 101, "tx_index": 0, "block_time": 1000, "new_holder": True,
        }
        responses = {
            "market_snapshot": lambda mint: {"pool": "pool-ALPHA" if mint == MINT_A else "pool-BETA", "sol_usd": 100.0},
            "collect_buys": lambda pool, mint, cursor: ([buy] if cursor < (101, 0) else [], (101, 0)),
            "token_supply": lambda mint: None,
            **overrides,
        }
        patchers = [patch.object(pumpfun, name, side_effect=value) for name, value in responses.items()]
        with patch.object(pumpfun.time, "time", return_value=1001):
            for patcher in patchers:
                patcher.start()
            try:
                asyncio.run(pumpfun.project_tick(self.bot))
            finally:
                for patcher in patchers:
                    patcher.stop()

    def test_each_chat_receives_its_own_token_card_once(self):
        self.subscribe(x="https://x.com/alpha")
        self.subscribe(-102, MINT_B, "BETA", x="https://x.com/beta")

        self.tick()
        self.tick()

        calls = self.bot.send_message.call_args_list
        self.assertEqual(len(calls), 2)
        for call, chat_id, symbol, social in ((calls[0], -101, "ALPHA", "https://x.com/alpha"),
                                             (calls[1], -102, "BETA", "https://x.com/beta")):
            self.assertEqual(call.kwargs["chat_id"], chat_id)
            self.assertIn(symbol, call.kwargs["text"])
            self.assertIn(social, str(call.kwargs["reply_markup"].to_dict()))
            self.assertNotIn("CYBER.sol", call.kwargs["text"])
            self.assertNotIn("cyberia.church", str(call.kwargs["reply_markup"].to_dict()))

    def test_no_subscriptions_means_no_market_or_chain_requests(self):
        with patch.object(pumpfun, "market_snapshot") as market, \
                patch.object(pumpfun, "collect_buys") as scan:
            asyncio.run(pumpfun.project_tick(self.bot))

        market.assert_not_called()
        scan.assert_not_called()

    def test_one_failed_send_retries_without_repeating_another_chats_purchase(self):
        first = self.subscribe()
        second = self.subscribe(-102, MINT_B, "BETA")
        self.bot.send_message.side_effect = [TimedOut(), None]

        self.tick()

        self.assertEqual(db._get_block_cursor(pumpfun.project_cursor_key(-101, first)), (100, 0))
        self.assertEqual(db._get_block_cursor(pumpfun.project_cursor_key(-102, second)), (101, 0))
        self.bot.send_message.side_effect = None
        self.tick()
        self.assertEqual(self.bot.send_message.await_count, 3)
        self.assertEqual(self.bot.send_message.call_args.kwargs["chat_id"], -101)

    def test_floor_and_disabled_state_are_independent_per_chat(self):
        self.subscribe(min_usd=200)
        self.subscribe(-102, MINT_B, "BETA", min_usd=0)

        self.tick()

        self.bot.send_message.assert_awaited_once()
        self.assertEqual(self.bot.send_message.call_args.kwargs["chat_id"], -102)
        self.command("off")
        self.bot.send_message.reset_mock()
        self.tick(market_snapshot=lambda mint: (_ for _ in ()).throw(RuntimeError("unavailable")))
        self.bot.send_message.assert_not_awaited()

    def test_disabled_during_scan_cannot_receive_a_purchase(self):
        self.subscribe()

        def scan(pool, mint, cursor):
            project = projects.saved(-101)
            projects.save(-101, dict(project, enabled=False))
            return [{"sol_amount": 1}], (101, 0)

        self.tick(collect_buys=scan)

        self.bot.send_message.assert_not_awaited()

    def test_old_purchase_is_not_replayed(self):
        self.subscribe()
        with patch.object(pumpfun, "PUMPFUN_MAX_AGE_SECONDS", 0):
            self.tick()

        self.bot.send_message.assert_not_awaited()

    def test_pool_migration_bootstraps_instead_of_replaying_history(self):
        self.subscribe()
        with patch.object(pumpfun, "head_cursor", return_value=(200, 1)):
            self.tick(market_snapshot=lambda mint: {"pool": "new-pool", "sol_usd": 100})

        project = projects.saved(-101)
        self.assertEqual(project["pool"], "new-pool")
        self.assertEqual(db._get_block_cursor(pumpfun.project_cursor_key(-101, project)), (200, 1))
        self.bot.send_message.assert_not_awaited()

    def test_missing_quote_holds_the_cursor(self):
        project = self.subscribe()

        self.tick(market_snapshot=lambda mint: None)

        self.assertEqual(db._get_block_cursor(pumpfun.project_cursor_key(-101, project)), (100, 0))
        self.bot.send_message.assert_not_awaited()


class MarketIsolationTests(unittest.TestCase):
    def test_quotes_and_fallbacks_never_cross_token_boundaries(self):
        pair = {"chainId": "solana", "dexId": "pumpswap", "baseToken": {"address": MINT_A},
                "quoteToken": {"address": pumpfun.WSOL_MINT}, "pairAddress": "pool-a",
                "priceUsd": "1", "priceNative": "0.01"}
        with patch.object(pumpfun, "_market_cache", {}), \
                patch.object(pumpfun, "_http_json", side_effect=[{"pairs": [pair]}, RuntimeError("offline")]) as feed:
            first = pumpfun.market_snapshot(MINT_A)
            second = pumpfun.market_snapshot(MINT_B)
            cached = pumpfun.market_snapshot(MINT_A)

        self.assertEqual(first["pool"], "pool-a")
        self.assertIsNone(second)
        self.assertEqual(cached, first)
        self.assertEqual(feed.call_count, 2)

    def test_unsupported_venues_cannot_replace_the_supported_pool(self):
        supported = {"chainId": "solana", "dexId": "pumpswap", "baseToken": {"address": MINT_A},
                     "quoteToken": {"address": pumpfun.WSOL_MINT}, "pairAddress": "supported-pool",
                     "priceUsd": "1", "priceNative": "0.01", "liquidity": {"usd": 100}}
        unsupported = dict(supported, dexId="raydium", pairAddress="unsupported-pool", liquidity={"usd": 1000})
        with patch.object(pumpfun, "_market_cache", {}), \
                patch.object(pumpfun, "_http_json", return_value={"pairs": [unsupported, supported]}):
            market = pumpfun.market_snapshot(MINT_A)

        self.assertEqual(market["pool"], "supported-pool")

    def test_project_text_is_escaped_in_html_cards_and_contract_replies(self):
        project = dict(projects.solana_project(MINT_B, "<BETA>", "<b>Beta</b>"), emoji="<")
        buy = {"sol_amount": 1, "token_amount": 10, "signature": "tx", "buyer": "buyer"}

        caption = pumpfun.format_buy(buy, 5, None, project)
        contract = projects.ca_reply(project)

        self.assertIn("&lt;b&gt;Beta&lt;/b&gt;", caption)
        self.assertIn("&lt;BETA&gt;", contract)
        self.assertNotIn("<b>Beta</b>", caption)
        self.assertIn("\n&lt;\n", caption)

    def test_small_emoji_step_still_caps_the_row(self):
        project = dict(projects.solana_project(MINT_B, "BETA", "Beta"), emoji_usd=1e-300)

        self.assertEqual(pumpfun._emoji_row(1e20, project), "🎲" * 40)


if __name__ == "__main__":
    unittest.main()
