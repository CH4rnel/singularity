"""Opt-in LAIN buybot for the native ETH/LAIN Pons v4 pool on Robinhood.

Pool and manager are pinned to the receipt supplied by the operator, not
discovered from untrusted token metadata. Only confirmed Swap logs from this
pool count. USD is optional, indicative market data; it never gates delivery.
This module reads chain data and sends notices. It cannot trade or mint.
"""
import asyncio
import json
import logging
import time
from collections import defaultdict
from decimal import Decimal, localcontext

from sqlalchemy import text
from telegram import InlineKeyboardButton, InlineKeyboardMarkup
from telegram.error import BadRequest, TelegramError
from web3 import Web3
from web3.middleware import ExtraDataToPOAMiddleware

from bot.config import (
    LAIN_BUYBOT_RPC_URLS, LAIN_BUYBOT_POLL_SECONDS,
    LAIN_BUYBOT_CONFIRMATIONS, LAIN_BUYBOT_MIN_ETH,
    LAIN_BUYBOT_MAX_AGE_SECONDS, LAIN_BUYBOT_PHOTO_URL,
    PROJECT_WEBSITE_URL, WALLET_MINI_APP_URL,
)
from bot.db import engine, _kv_get, _kv_set
from bot.pumpfun import _http_json

logger = logging.getLogger(__name__)

CHAIN_ID = 4663
TOKEN = "0x2b79a071a75dd40f1aca68146d7978204efd156a"
MANAGER = "0x8366a39cc670b4001a1121b8f6a443a643e40951"
POOL_ID = "0x9103c042ae23fbcc5141b202dee6d9016d57b649bdc6e9c2a5764b0ad9f2cf9c"
EXPLORER = "https://robin.etherscan.io"
SAMPLE_TX = "0x2bb92603b4fe4d41310de00eccedc19f3b7f7ab8faf49aab459bff9700c7933d"
SWAP_TOPIC = Web3.to_hex(Web3.keccak(
    text="Swap(bytes32,address,int128,int128,uint160,uint128,int24,uint24)"
))
TRANSFER_TOPIC = Web3.to_hex(Web3.keccak(text="Transfer(address,address,uint256)"))
CHAT_PREFIX = "lain_buybot_chat:"
STATE_PREFIX = f"lain_buybot:{CHAIN_ID}:{POOL_ID}:"
END_OF_BLOCK = 10**9
MIN_ETH = Decimal(LAIN_BUYBOT_MIN_ETH)
if not MIN_ETH.is_finite() or MIN_ETH < 0:
    raise ValueError("LAIN_BUYBOT_MIN_ETH must be finite and nonnegative")


def _hex(value):
    return value.lower() if isinstance(value, str) else Web3.to_hex(value).lower()


def _int(value):
    return int(value, 16) if isinstance(value, str) else int(value)


def _words(data):
    raw = bytes.fromhex(_hex(data).removeprefix("0x"))
    if len(raw) % 32:
        raise ValueError("Incomplete ABI word")
    return [raw[i:i + 32] for i in range(0, len(raw), 32)]


def is_pool_swap(log):
    topics = list(map(_hex, log.get("topics", [])))
    return (
        not log.get("removed", False)
        and _hex(log["address"]) == MANAGER
        and len(topics) == 3
        and topics[:2] == [SWAP_TOPIC, POOL_ID]
    )


def parse_buy(receipt):
    """One transaction, one net pool purchase (round trips/sells excluded).

    v4's amounts are signed from the swapper's side, opposite to v3. Here
    currency0 is native ETH (zero address), currency1 is LAIN (18 decimals).
    The actual token transfers can be smaller than the Swap output: this
    pool's hook takes a fee. Never label the gross output as tokens received.
    A sender need not be the beneficiary, including in the supplied receipt.
    """
    if _int(receipt.get("status", 0)) != 1:
        return None
    swaps = [log for log in receipt["logs"] if is_pool_swap(log)]
    if not swaps:
        return None
    eth_delta = token_delta = 0
    for log in swaps:
        words = _words(log["data"])
        if len(words) != 6:
            raise ValueError("Unexpected v4 Swap layout")
        eth_delta += int.from_bytes(words[0], "big", signed=True)
        token_delta += int.from_bytes(words[1], "big", signed=True)
    if eth_delta >= 0 or token_delta <= 0:
        return None

    balances = defaultdict(int)
    for log in receipt["logs"]:
        topics = list(map(_hex, log.get("topics", [])))
        if _hex(log["address"]) != TOKEN or len(topics) != 3 or topics[0] != TRANSFER_TOPIC:
            continue
        amount = int.from_bytes(_words(log["data"])[0], "big")
        balances["0x" + topics[1][-40:]] -= amount
        balances["0x" + topics[2][-40:]] += amount
    candidates = sorted(
        ((amount, address) for address, amount in balances.items()
         if amount > 0 and address not in (MANAGER, "0x" + "0" * 40)),
        reverse=True,
    )
    # Conservative attribution: a unique recipient with >=90% of the pool's
    # output and no excess transfers. Otherwise report pool output alone.
    receiver = None
    received = None
    if (candidates and sum(a for a, _ in candidates) <= token_delta
            and candidates[0][0] * 10 >= token_delta * 9):
        amount, receiver = candidates[0]
        received = Decimal(amount) / 10**18
    with localcontext() as ctx:
        ctx.prec = 80
        sqrt = int.from_bytes(_words(swaps[-1]["data"])[2], "big")
        price_eth = Decimal(2**192) / (sqrt * sqrt) if sqrt else None
        return {
            "tx": _hex(receipt["transactionHash"]),
            "block": _int(receipt["blockNumber"]),
            "block_hash": _hex(receipt["blockHash"]),
            "index": max(_int(log["logIndex"]) for log in swaps),
            "sender": _hex(receipt["from"]),
            "receiver": receiver, "received": received,
            "eth": Decimal(-eth_delta) / 10**18,
            "tokens": Decimal(token_delta) / 10**18,
            "price_eth": price_eth,
        }


def keyboard():
    """URL buttons work in groups too; no web_app or wallet signing here.

    Our exchange currently serves v2/v3, not this Pons v4 pool. The wallet
    button is deliberately labelled Wallet; it makes no promise of a route.
    """
    return InlineKeyboardMarkup([
        [InlineKeyboardButton("👛 Cyberia Wallet", url=WALLET_MINI_APP_URL),
         InlineKeyboardButton("🧠 Lain OS", url="https://lain-os.com")],
        [InlineKeyboardButton("💬 Cyberia Board", url=PROJECT_WEBSITE_URL.rstrip("/") + "/board")],
    ])


def _amount(value, places=2):
    return f"{value:,.{places}f}".rstrip("0").rstrip(".") if places else f"{value:,.0f}"


def _address(address):
    return f'<a href="{EXPLORER}/address/{address}">{address[:6]}…{address[-4:]}</a>'


def format_buy(buy, eth_usd=None, supply=None, preview=False):
    lines = (["<b>PREVIEW · historical transaction</b>"] if preview else [])
    lines += ["🔹 <b>LAIN Buy!</b> · Robinhood Chain", "",
              "🐋" * min(20, max(1, int(buy["eth"] / Decimal("0.005")))), ""]
    paid = f'💵 Pool input: <b>{_amount(buy["eth"], 6)} ETH</b>'
    if eth_usd:
        paid += f' (≈ ${buy["eth"] * eth_usd:,.2f})'
    lines.append(paid)
    # Display the amount reported by Swap, not the recipient's net transfer.
    lines.append(f'🪙 <b>{_amount(buy["tokens"])} LAIN</b>')
    lines.append("🔷 Sender: " + _address(buy["sender"]))
    if buy["receiver"] and buy["receiver"] != buy["sender"]:
        lines.append("👛 Receiver: " + _address(buy["receiver"]))
    if eth_usd and supply and buy["price_eth"]:
        cap = buy["price_eth"] * supply * eth_usd
        lines.append(f'🔼 FDV ≈ <b>${cap:,.0f}</b>')
    if eth_usd:
        lines.append("<i>USD estimates use the latest ETH quote.</i>")
    lines += ["", f'<a href="{EXPLORER}/tx/{buy["tx"]}">Transaction</a> · '
              f'<a href="https://www.geckoterminal.com/robinhood/pools/{POOL_ID}">Chart</a>']
    return "\n".join(lines)


class Reader:
    """RPC failover with a chain-id check before accepting any endpoint."""
    def __init__(self):
        self.clients = [Web3(Web3.HTTPProvider(url, request_kwargs={"timeout": 15}))
                        for url in LAIN_BUYBOT_RPC_URLS]
        for client in self.clients:
            client.middleware_onion.inject(ExtraDataToPOAMiddleware, layer=0)

    def read(self, operation):
        for client in self.clients:
            try:
                if client.eth.chain_id != CHAIN_ID:
                    continue
                return operation(client.eth)
            except Exception:
                # Do not print provider URLs (they may contain operator keys).
                continue
        raise RuntimeError("LAIN buybot: no verified Robinhood RPC answered")

    def head(self):
        return max(0, self.read(lambda eth: eth.block_number) - LAIN_BUYBOT_CONFIRMATIONS)

    def block(self, number):
        return self.read(lambda eth: eth.get_block(number))

    def receipt(self, tx):
        return self.read(lambda eth: eth.get_transaction_receipt(tx))

    def logs(self, start, end):
        return self.read(lambda eth: eth.get_logs({
            "fromBlock": start, "toBlock": end,
            "address": Web3.to_checksum_address(MANAGER),
            "topics": [SWAP_TOPIC, POOL_ID],
        }))

    def supply(self, block):
        try:
            # totalSupply(), at the trade's block. This is FDV, never a claim
            # about circulating supply or a token holder's realizable wealth.
            raw = self.read(lambda eth: eth.call(
                {"to": Web3.to_checksum_address(TOKEN), "data": "0x18160ddd"}, block))
            return Decimal(int.from_bytes(raw, "big")) / 10**18
        except RuntimeError:
            return None


_quote_cache = (0, None)


def eth_quote():
    global _quote_cache
    if time.monotonic() - _quote_cache[0] < 60:
        return _quote_cache[1]
    quote = None
    try:
        data = _http_json(f"https://api.geckoterminal.com/api/v2/networks/robinhood/pools/{POOL_ID}")
        pool = data["data"]
        if pool["attributes"]["address"].lower() == POOL_ID:
            quote = Decimal(pool["attributes"]["quote_token_price_usd"])
            if not quote.is_finite() or quote <= 0:
                quote = None
    except Exception:
        pass
    _quote_cache = (time.monotonic(), quote)
    return quote


def _subscriptions():
    with engine.connect() as conn:
        rows = conn.execute(text(
            "SELECT key FROM bot_kv WHERE substr(key, 1, :n) = :p AND value = 'on'"),
            {"n": len(CHAT_PREFIX), "p": CHAT_PREFIX})
        return [int(row[0][len(CHAT_PREFIX):]) for row in rows]


def _state_key(chat_id):
    return STATE_PREFIX + str(chat_id)


def _save(chat_id, block, index, block_hash):
    _kv_set(_state_key(chat_id), json.dumps({"block": block, "index": index, "hash": _hex(block_hash)}))


async def send_buy(bot, chat_id, buy, quote=None, supply=None, preview=False):
    caption = format_buy(buy, quote, supply, preview)
    if LAIN_BUYBOT_PHOTO_URL:
        try:
            await bot.send_photo(chat_id=chat_id, photo=LAIN_BUYBOT_PHOTO_URL,
                                 caption=caption, parse_mode="HTML", reply_markup=keyboard())
            return
        except BadRequest:
            # A rejected image must not suppress the chain event. A timeout
            # is ambiguous, so it propagates instead of sending a second copy.
            pass
    await bot.send_message(chat_id=chat_id, text=caption, parse_mode="HTML",
                           disable_web_page_preview=True, reply_markup=keyboard())


async def tick(bot, reader=None, now=None):
    chats = _subscriptions()
    if not chats:
        return  # no chain/market traffic before a chat opts in
    reader = reader or Reader()
    now = time.time() if now is None else now
    head = await asyncio.to_thread(reader.head)
    for chat_id in chats:
        try:
            raw = _kv_get(_state_key(chat_id))
            state = json.loads(raw) if raw else None
            if state is None:
                block = await asyncio.to_thread(reader.block, head)
                _save(chat_id, head, END_OF_BLOCK, block["hash"])
                continue  # first run starts now, no historical flood
            if state["block"] > head:
                continue
            canonical = await asyncio.to_thread(reader.block, state["block"])
            if _hex(canonical["hash"]) != state["hash"]:
                raise RuntimeError("cursor block reorganized; admin must /buybot reset")
            end = min(head, state["block"] + 999)
            logs = await asyncio.to_thread(reader.logs, state["block"], end)
            seen = set()
            for log in sorted(logs, key=lambda item: (_int(item["blockNumber"]), _int(item["logIndex"]))):
                # An admin can turn the feed off while chain reads are in
                # flight. Stop before the next notice, retaining the cursor.
                if _kv_get(CHAT_PREFIX + str(chat_id)) != "on":
                    break
                position = (_int(log["blockNumber"]), _int(log["logIndex"]))
                if not is_pool_swap(log) or position <= (state["block"], state["index"]):
                    continue
                tx = _hex(log["transactionHash"])
                if tx in seen:
                    continue
                seen.add(tx)
                receipt = await asyncio.to_thread(reader.receipt, tx)
                block = await asyncio.to_thread(reader.block, position[0])
                block_hash = _hex(block["hash"])
                if (_hex(log["blockHash"]) != block_hash
                        or _hex(receipt["blockHash"]) != block_hash
                        or _int(receipt["blockNumber"]) != position[0]):
                    raise RuntimeError("receipt/log block is no longer canonical")
                buy = parse_buy(receipt)
                index = max(_int(item["logIndex"]) for item in receipt["logs"] if is_pool_swap(item))
                age = now - _int(block["timestamp"])
                if buy and buy["eth"] >= MIN_ETH and 0 <= age <= LAIN_BUYBOT_MAX_AGE_SECONDS:
                    quote = await asyncio.to_thread(eth_quote)
                    supply = await asyncio.to_thread(reader.supply, position[0]) if quote else None
                    if _kv_get(CHAT_PREFIX + str(chat_id)) == "on":
                        await send_buy(bot, chat_id, buy, quote, supply)
                # Persist only after successful send (or a classified skip).
                # Telegram has no idempotency key: a lost successful response
                # or a crash before this write can still cause a duplicate.
                _save(chat_id, position[0], index, block_hash)
            block = await asyncio.to_thread(reader.block, end)
            _save(chat_id, end, END_OF_BLOCK, block["hash"])
            _kv_set(_state_key(chat_id) + ":error", "")
        except Exception as error:
            # One broken destination must not starve the other subscribed chats.
            reason = str(error) if isinstance(error, RuntimeError) else type(error).__name__
            _kv_set(_state_key(chat_id) + ":error", reason)
            logger.warning("LAIN buybot chat=%s: %s", chat_id, reason)


async def loop(application):
    reader = Reader()
    while True:
        try:
            await tick(application.bot, reader)
        except Exception as error:
            logger.warning("LAIN buybot tick: %s", type(error).__name__)
        await asyncio.sleep(LAIN_BUYBOT_POLL_SECONDS)


async def command(update, context):
    chat, user = update.effective_chat, update.effective_user
    if not chat or not user or not update.effective_message:
        return
    message = update.effective_message
    action = context.args[0].lower() if context.args else "status"
    if chat.type not in ("group", "supergroup"):
        await message.reply_text("Add the bot to a group. A group administrator can enable LAIN buy alerts with /buybot on.")
        return
    try:
        member = await context.bot.get_chat_member(chat.id, user.id)
    except TelegramError:
        await message.reply_text("Could not verify administrator permissions. Please try again.")
        return
    if member.status not in ("creator", "administrator"):
        await message.reply_text("Only group administrators can change buybot settings.")
        return
    if action not in ("on", "off", "status", "reset", "preview"):
        await message.reply_text("/buybot on · off · status · reset · preview")
        return
    if action == "preview":
        try:
            reader = Reader()
            receipt = await asyncio.to_thread(reader.receipt, SAMPLE_TX)
            buy = parse_buy(receipt)
            if not buy:
                raise RuntimeError("sample purchase could not be verified")
            await send_buy(context.bot, chat.id, buy, preview=True)
        except Exception:
            await message.reply_text("Could not load the sample purchase from the chain. Please try again later.")
        return
    enabled = _kv_get(CHAT_PREFIX + str(chat.id)) == "on"
    if action == "reset" or (action == "on" and not enabled):
        try:
            reader = Reader()
            head = await asyncio.to_thread(reader.head)
            block = await asyncio.to_thread(reader.block, head)
            _save(chat.id, head, END_OF_BLOCK, block["hash"])
            _kv_set(CHAT_PREFIX + str(chat.id), "on")
            _kv_set(_state_key(chat.id) + ":error", "")
        except Exception:
            await message.reply_text("Robinhood RPC is unavailable. Settings were not changed. Please try again later.")
            return
    elif action == "off":
        _kv_set(CHAT_PREFIX + str(chat.id), "off")
    enabled = _kv_get(CHAT_PREFIX + str(chat.id)) == "on"
    error = _kv_get(_state_key(chat.id) + ":error") if enabled else None
    status = f"LAIN buybot: {'enabled' if enabled else 'disabled'} in this group.\n"
    if error:
        status += f"Last polling error: {error}\n"
    await message.reply_text(
        status + f"Robinhood Chain · ETH/LAIN · minimum {MIN_ETH} ETH.\n"
        "Commands: /buybot on · off · status · reset · preview\n"
        "Only new purchases are announced after enabling alerts. Historical purchases are not replayed.",
        reply_markup=keyboard(),
    )
