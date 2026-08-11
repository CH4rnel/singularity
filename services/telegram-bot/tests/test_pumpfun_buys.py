"""pump.fun buy bot: parsing and post formatting, pinned to a real trade.

The fixture is the balance section of a real CYBER.sol buy on the PumpSwap pool
(signature rrVsUwS6…UnuN4, slot 438515078), reduced to the fields the parser
reads. It arrived through a Jupiter route, which is the point: the parser never
looks at instructions, so an aggregated buy reads like a direct one.

Env is pinned before bot.* imports because bot.config reads it at import time.
"""
import os
import tempfile
import unittest

_TMP = tempfile.mkdtemp(prefix="pumpfun_test_")
os.environ.setdefault("DB_PATH", os.path.join(_TMP, "pumpfun_test.sqlite"))

from bot import pumpfun  # noqa: E402

POOL = "Dk19ZrUAJLW2e6Ra1qkaZk1pwb48y5cwQu6xfcFXYtEg"
MINT = "E67WWiQY4s9SZbCyFVTh2CEjorEYbhuVJQUZb3Mbpump"
WSOL = "So11111111111111111111111111111111111111112"
BUYER = "Dox7A7DgiPQJaNLTyPPQUyptYy3iyB7AgvzbwRBHhSUJ"
SIGNATURE = (
    "rrVsUwS6Ab4y1DLpcDrVQAUfZwkAc7cB8tXdZpu1paRxVzWq1wDQdPmnh2raQj6je8ZTymd4fdL5Dh1HYuUnuN4"
)


def _balance(index, mint, owner, amount, decimals):
    return {
        "accountIndex": index,
        "mint": mint,
        "owner": owner,
        "uiTokenAmount": {"amount": str(amount), "decimals": decimals},
    }


def _tx(pre, post, err=None, signature=SIGNATURE, fee_payer=BUYER):
    return {
        "slot": 438515078,
        "blockTime": 1786412817,
        "meta": {"err": err, "preTokenBalances": pre, "postTokenBalances": post},
        "transaction": {
            "signatures": [signature],
            "message": {"accountKeys": [{"pubkey": fee_payer}]},
        },
    }


# The real trade: 3 133 447.177223 CYBER.sol out of the pool for 0.981430814 SOL.
POOL_COIN_PRE = 261811967311653
POOL_COIN_POST = 258678520134430
POOL_SOL_PRE = 81004813894
POOL_SOL_POST = 81986244708

REAL_BUY = _tx(
    pre=[
        _balance(19, MINT, POOL, POOL_COIN_PRE, 6),
        _balance(20, WSOL, POOL, POOL_SOL_PRE, 9),
        # Unrelated wrapped-SOL accounts moving in the same transaction.
        _balance(5, WSOL, "JCRGumoE9Qi5BBgULTgdgTLjSgkCMSbF62ZZfGs84JeU", 1076423471766, 9),
        _balance(21, WSOL, "S9eH5natbUZjE2qPxswFK3Y6947vs98r37NpMwj7P9j", 111061363, 9),
    ],
    post=[
        _balance(4, MINT, BUYER, 3133447177223, 6),
        _balance(19, MINT, POOL, POOL_COIN_POST, 6),
        _balance(20, WSOL, POOL, POOL_SOL_POST, 9),
        _balance(5, WSOL, "JCRGumoE9Qi5BBgULTgdgTLjSgkCMSbF62ZZfGs84JeU", 1076428034507, 9),
        _balance(21, WSOL, "S9eH5natbUZjE2qPxswFK3Y6947vs98r37NpMwj7P9j", 114005067, 9),
    ],
)


class ParseBuyTest(unittest.TestCase):
    def test_reads_the_real_trade(self):
        buy = pumpfun.parse_buy(REAL_BUY, POOL, MINT)
        self.assertIsNotNone(buy)
        self.assertEqual(buy["buyer"], BUYER)
        self.assertEqual(buy["signature"], SIGNATURE)
        self.assertAlmostEqual(buy["token_amount"], 3133447.177223, places=6)
        self.assertAlmostEqual(buy["sol_amount"], 0.981430814, places=9)
        self.assertEqual(buy["token_decimals"], 6)
        self.assertEqual(buy["slot"], 438515078)

    def test_first_ever_balance_is_a_new_holder(self):
        self.assertTrue(pumpfun.parse_buy(REAL_BUY, POOL, MINT)["new_holder"])

    def test_topping_up_an_existing_bag_is_not(self):
        tx = _tx(
            pre=REAL_BUY["meta"]["preTokenBalances"] + [_balance(4, MINT, BUYER, 1_000_000, 6)],
            post=REAL_BUY["meta"]["postTokenBalances"],
        )
        self.assertFalse(pumpfun.parse_buy(tx, POOL, MINT)["new_holder"])

    def test_sell_is_not_a_buy(self):
        """Same pool, both deltas reversed."""
        tx = _tx(
            pre=[
                _balance(19, MINT, POOL, POOL_COIN_POST, 6),
                _balance(20, WSOL, POOL, POOL_SOL_POST, 9),
                _balance(4, MINT, BUYER, 3133447177223, 6),
            ],
            post=[
                _balance(19, MINT, POOL, POOL_COIN_PRE, 6),
                _balance(20, WSOL, POOL, POOL_SOL_PRE, 9),
                _balance(4, MINT, BUYER, 0, 6),
            ],
        )
        self.assertIsNone(pumpfun.parse_buy(tx, POOL, MINT))

    def test_liquidity_deposit_is_not_a_buy(self):
        """Both sides into the pool — the case a naive 'coin left the pool'
        check would still get right, and its withdrawal twin would not."""
        tx = _tx(
            pre=[
                _balance(19, MINT, POOL, POOL_COIN_PRE, 6),
                _balance(20, WSOL, POOL, POOL_SOL_PRE, 9),
            ],
            post=[
                _balance(19, MINT, POOL, POOL_COIN_PRE + 10_000_000, 6),
                _balance(20, WSOL, POOL, POOL_SOL_PRE + 3_000_000, 9),
            ],
        )
        self.assertIsNone(pumpfun.parse_buy(tx, POOL, MINT))

    def test_liquidity_withdrawal_is_not_a_buy(self):
        tx = _tx(
            pre=[
                _balance(19, MINT, POOL, POOL_COIN_PRE, 6),
                _balance(20, WSOL, POOL, POOL_SOL_PRE, 9),
            ],
            post=[
                _balance(19, MINT, POOL, POOL_COIN_PRE - 10_000_000, 6),
                _balance(20, WSOL, POOL, POOL_SOL_PRE - 3_000_000, 9),
            ],
        )
        self.assertIsNone(pumpfun.parse_buy(tx, POOL, MINT))

    def test_another_pool_is_ignored(self):
        self.assertIsNone(pumpfun.parse_buy(REAL_BUY, "SomeOtherPool1111111111111111", MINT))

    def test_failed_transaction_is_ignored(self):
        tx = _tx(
            pre=REAL_BUY["meta"]["preTokenBalances"],
            post=REAL_BUY["meta"]["postTokenBalances"],
            err={"InstructionError": [3, {"Custom": 6002}]},
        )
        self.assertIsNone(pumpfun.parse_buy(tx, POOL, MINT))

    def test_buyer_is_the_recipient_not_the_signer(self):
        """An aggregator or a relayer may pay the fee for someone else."""
        tx = _tx(
            pre=REAL_BUY["meta"]["preTokenBalances"],
            post=REAL_BUY["meta"]["postTokenBalances"],
            fee_payer="Rel4yer11111111111111111111111111111111111",
        )
        self.assertEqual(pumpfun.parse_buy(tx, POOL, MINT)["buyer"], BUYER)

    def test_flat_arbitrage_falls_back_to_the_fee_payer(self):
        """Bought here and sold on elsewhere in the same transaction: nobody
        ends up holding, so the signer is the closest thing to a trader."""
        arb = "Arb1111111111111111111111111111111111111111"
        tx = _tx(
            pre=[
                _balance(19, MINT, POOL, POOL_COIN_PRE, 6),
                _balance(20, WSOL, POOL, POOL_SOL_PRE, 9),
            ],
            post=[
                _balance(19, MINT, POOL, POOL_COIN_POST, 6),
                _balance(20, WSOL, POOL, POOL_SOL_POST, 9),
            ],
            fee_payer=arb,
        )
        buy = pumpfun.parse_buy(tx, POOL, MINT)
        self.assertEqual(buy["buyer"], arb)
        self.assertFalse(buy["new_holder"])


class FormatBuyTest(unittest.TestCase):
    def test_post_layout(self):
        buy = pumpfun.parse_buy(REAL_BUY, POOL, MINT)
        # 0.981430814 SOL at the pair's own SOL/USD quote.
        text = pumpfun.format_buy(buy, usd=52.51, market_cap=22662.0)
        lines = text.split("\n")

        self.assertEqual(lines[0], '<a href="https://t.me/cyberia_network_chat">cyber.sol</a> Buy!')
        self.assertEqual(lines[1], "⛓️‍💥🎲" * 11)
        self.assertEqual(lines[2], "🔀 0.9814 SOL ($52.51)")
        self.assertEqual(lines[3], "🔀 3 133 447.2 CYBER.sol")
        self.assertEqual(
            lines[4],
            f'👤 <a href="https://solscan.io/address/{BUYER}">Dox7A7...hSUJ</a> | '
            f'<a href="https://solscan.io/tx/{SIGNATURE}">Txn</a>',
        )
        self.assertEqual(lines[5], "⬆️ New Holder!")
        self.assertEqual(lines[6], "💸 Market Cap $22 662")
        self.assertEqual(len(lines), 7)

    def test_returning_buyer_has_no_new_holder_line(self):
        buy = dict(pumpfun.parse_buy(REAL_BUY, POOL, MINT), new_holder=False)
        self.assertNotIn("New Holder", pumpfun.format_buy(buy, usd=52.51, market_cap=22662.0))

    def test_unknown_market_cap_is_omitted_never_zero(self):
        buy = pumpfun.parse_buy(REAL_BUY, POOL, MINT)
        self.assertNotIn("Market Cap", pumpfun.format_buy(buy, usd=52.51, market_cap=None))

    def test_emoji_row_scales_with_size_and_is_capped(self):
        self.assertEqual(pumpfun._emoji_row(5.0), "⛓️‍💥🎲")
        self.assertEqual(pumpfun._emoji_row(100.0), "⛓️‍💥🎲" * 20)
        self.assertEqual(pumpfun._emoji_row(10_000_000.0), "⛓️‍💥🎲" * 40)
        # A buy just over the floor still gets a row.
        self.assertEqual(pumpfun._emoji_row(5.01), "⛓️‍💥🎲")


class CursorTest(unittest.TestCase):
    def test_slot_then_index_in_block(self):
        """Two buys in one slot must not collapse onto the same cursor."""
        first = pumpfun._cursor_of({"slot": 438515078, "transactionIndex": 12})
        second = pumpfun._cursor_of({"slot": 438515078, "transactionIndex": 1281})
        later_slot = pumpfun._cursor_of({"slot": 438515079, "transactionIndex": 0})
        self.assertLess(first, second)
        self.assertLess(second, later_slot)

    def test_missing_index_defaults_to_zero(self):
        self.assertEqual(pumpfun._cursor_of({"slot": 7}), (7, 0))


if __name__ == "__main__":
    unittest.main()
