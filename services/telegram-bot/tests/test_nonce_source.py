"""The nonce must come from `latest`, and nothing may quietly switch it back.

On 2026-09-14 the shared relayer key stopped landing transactions for forty
minutes because this chain's node answers a *pending* count as `latest` plus the
number of the account's transactions in its pool, whatever nonces those carry.
One unexecutable transaction raises every later answer by one; the gap reached
512, and a /claim waiting on a receipt that could never arrive froze the whole
bot, whose dispatcher is sequential.
"""
import unittest
from types import SimpleNamespace

from bot.chain import next_nonce

ADDRESS = "0xfa41267c5e2390e941a12b0b8e566448539a5179"


class NonceSourceTest(unittest.TestCase):
    def _w3(self, answers):
        asked = []

        def get_transaction_count(address, block):
            asked.append((address, block))
            return answers[block]

        return SimpleNamespace(eth=SimpleNamespace(get_transaction_count=get_transaction_count)), asked

    def test_it_asks_for_latest_and_never_for_pending(self):
        w3, asked = self._w3({"latest": 130465, "pending": 130977})
        self.assertEqual(next_nonce(w3, ADDRESS), 130465)
        self.assertEqual([block for _, block in asked], ["latest"])

    def test_the_address_is_checksummed_for_the_node(self):
        w3, asked = self._w3({"latest": 1})
        next_nonce(w3, ADDRESS)
        self.assertEqual(asked[0][0], "0xfA41267C5E2390E941A12b0b8e566448539A5179")

    def test_a_pool_full_of_unexecutable_transactions_cannot_move_it(self):
        """The failure itself: 512 stuck txs inflate `pending` and must not
        reach the signature."""
        for inflated in (130466, 130977, 999_999):
            w3, _ = self._w3({"latest": 130465, "pending": inflated})
            self.assertEqual(next_nonce(w3, ADDRESS), 130465)


if __name__ == "__main__":
    unittest.main()
