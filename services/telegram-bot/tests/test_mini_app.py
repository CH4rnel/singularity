"""The Mini App entry points.

What is worth pinning here is not the copy but the two rules Telegram enforces
and one this project enforces: a `web_app` button is legal only in a private
chat, the URL is the site's own wallet page rather than anything bundled, and
the bot never claims to hold a key it cannot hold.
"""
import asyncio
import unittest
from unittest.mock import AsyncMock, MagicMock

from bot.config import WALLET_MINI_APP_URL
from bot.handlers import mini_app_markup, open_command


def _update(chat_type: str = "private") -> MagicMock:
    update = MagicMock()
    update.effective_chat.type = chat_type
    update.message.reply_text = AsyncMock()

    return update


class MiniAppMarkupTests(unittest.TestCase):
    def test_private_chat_gets_a_real_mini_app_button(self):
        button = mini_app_markup(True).inline_keyboard[0][0]

        self.assertIsNotNone(button.web_app)
        self.assertEqual(button.web_app.url, WALLET_MINI_APP_URL)
        self.assertIsNone(button.url)

    def test_group_chat_falls_back_to_a_link(self):
        # Telegram rejects a message carrying a web_app button outside a
        # private chat, so a group gets the same page as an ordinary link.
        button = mini_app_markup(False).inline_keyboard[0][0]

        self.assertIsNone(button.web_app)
        self.assertEqual(button.url, WALLET_MINI_APP_URL)

    def test_the_mini_app_is_the_site_wallet_over_https(self):
        self.assertTrue(WALLET_MINI_APP_URL.startswith("https://"))
        self.assertTrue(WALLET_MINI_APP_URL.endswith("/wallet"))


class OpenCommandTests(unittest.TestCase):
    def test_reply_says_what_telegram_never_receives(self):
        update = _update()
        asyncio.run(open_command(update, MagicMock()))

        text, kwargs = update.message.reply_text.call_args
        answer = text[0]

        self.assertIn("recovery phrase", answer)
        self.assertIn("your device", answer)
        # The one rule the frame itself enforces, said before anyone taps.
        self.assertIn("not shown inside Telegram", answer)
        self.assertIsNotNone(kwargs["reply_markup"].inline_keyboard[0][0].web_app)

    def test_group_reply_carries_a_link_instead(self):
        update = _update("supergroup")
        asyncio.run(open_command(update, MagicMock()))

        _, kwargs = update.message.reply_text.call_args
        button = kwargs["reply_markup"].inline_keyboard[0][0]

        self.assertIsNone(button.web_app)
        self.assertEqual(button.url, WALLET_MINI_APP_URL)


if __name__ == "__main__":
    unittest.main()
