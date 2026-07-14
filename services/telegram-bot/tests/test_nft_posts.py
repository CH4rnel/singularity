"""Dry-run smoke test for NFT-from-posts: a public-channel post must produce a
channel_post_nfts row (deduped), without touching Telegram, IPFS or the chain.

Env is pinned before bot.* imports because bot.config reads it at import time.
"""
import asyncio
import os
import tempfile
import unittest
from datetime import datetime, timezone
from unittest.mock import MagicMock

_TMP = tempfile.mkdtemp(prefix="nft_test_")
os.environ["DB_PATH"] = os.path.join(_TMP, "nft_test.sqlite")
os.environ["NFT_FROM_POSTS"] = "1"
os.environ["NFT_FROM_POSTS_DRYRUN"] = "1"
# Unreachable IPFS API: the handler must fall back to the bare post link.
os.environ["IPFS_API_URL"] = "http://127.0.0.1:1"

from sqlalchemy import text  # noqa: E402

from bot import config, nft  # noqa: E402
from bot.db import engine, ensure_chat_token_schema  # noqa: E402

# Another test module (e.g. test_ai) importing bot.config first freezes the
# real env before our pins above apply. Never run this suite against a live
# config — without DRYRUN a real DEPLOYER_PK would mint on-chain.
if not config.NFT_FROM_POSTS_DRYRUN or config.DB_PATH != os.environ["DB_PATH"]:
    raise unittest.SkipTest(
        "bot.config was imported before the dry-run env could be pinned; "
        "run standalone: python -m unittest tests.test_nft_posts"
    )


def _channel_update(message_id: int, body: str = "Hello Wired"):
    msg = MagicMock()
    msg.message_id = message_id
    msg.media_group_id = None
    msg.text = body
    msg.caption = None
    msg.photo = []
    msg.date = datetime.now(timezone.utc)

    chat = MagicMock()
    chat.id = -1001234567890
    chat.type = "channel"
    chat.username = "cyberia_test"
    chat.title = "Cyberia"

    update = MagicMock()
    update.channel_post = msg
    update.effective_message = msg
    update.effective_chat = chat
    return update


class ChannelPostDryRunTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        ensure_chat_token_schema()

    def _rows(self):
        with engine.connect() as conn:
            return conn.execute(
                text("SELECT chat_id, message_id, token_id, token_uri FROM channel_post_nfts")
            ).fetchall()

    def test_post_records_nft_row_and_dedups(self):
        update = _channel_update(42)
        context = MagicMock()

        asyncio.run(nft.channel_post_handler(update, context))

        rows = self._rows()
        self.assertEqual(len(rows), 1)
        self.assertEqual(rows[0].message_id, 42)
        self.assertIsNone(rows[0].token_id)  # dry run: nothing minted
        self.assertIn("cyberia_test/42", rows[0].token_uri)

        # Same post again — dedup keeps a single row.
        asyncio.run(nft.channel_post_handler(update, context))
        self.assertEqual(len(self._rows()), 1)

    def test_private_channels_and_empty_posts_are_skipped(self):
        context = MagicMock()

        private = _channel_update(43)
        private.effective_chat.username = None
        asyncio.run(nft.channel_post_handler(private, context))

        empty = _channel_update(44, body="")
        asyncio.run(nft.channel_post_handler(empty, context))

        self.assertEqual(
            [r.message_id for r in self._rows() if r.message_id in (43, 44)],
            [],
        )


if __name__ == "__main__":
    unittest.main()
