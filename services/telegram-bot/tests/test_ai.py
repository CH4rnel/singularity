"""Focused tests for the AI assistant's pure routing helpers."""
import unittest

from bot.ai import _split_telegram_text, _strip_bot_mention


class StripBotMentionTests(unittest.TestCase):
    def test_extracts_question_case_insensitively(self):
        self.assertEqual(
            _strip_bot_mention("@CyberiaBot Как добавить сеть?", "cyberiabot"),
            "Как добавить сеть?",
        )

    def test_handles_mention_after_question(self):
        self.assertEqual(
            _strip_bot_mention("Как получить CYBER, @cyberiabot?", "cyberiabot"),
            "Как получить CYBER?",
        )

    def test_ignores_other_users(self):
        self.assertIsNone(_strip_bot_mention("Привет, @otherbot", "cyberiabot"))


class SplitTelegramTextTests(unittest.TestCase):
    def test_preserves_short_answer(self):
        self.assertEqual(_split_telegram_text("short answer"), ["short answer"])

    def test_splits_long_answer_under_limit(self):
        source = "word " * 100
        chunks = _split_telegram_text(source, limit=80)
        self.assertGreater(len(chunks), 1)
        self.assertTrue(all(len(chunk) <= 80 for chunk in chunks))
        self.assertEqual(" ".join(chunks), source.strip())

    def test_empty_answer_has_no_chunks(self):
        self.assertEqual(_split_telegram_text("   "), [])


if __name__ == "__main__":
    unittest.main()
