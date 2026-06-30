"""Background announcer loops and the periodic market snapshot."""
import asyncio
import json
import time
import logging
import urllib.request
from datetime import datetime, timedelta, timezone

from web3 import Web3
from sqlalchemy import text

from telegram.ext import Application
from telegram.error import TelegramError

from bot.config import (
    RPC_URL, EXPLORER_URL, SOLSCAN_URL,
    BRIDGE_ANNOUNCE_CHAT, BRIDGE_POLL_SECONDS,
    SWAP_ANNOUNCE_CHAT, SWAP_POLL_SECONDS, SWAP_MAX_BLOCK_RANGE,
    LIQUIDITY_ANNOUNCE_CHAT, LIQUIDITY_POLL_SECONDS, LIQUIDITY_MAX_BLOCK_RANGE,
    LENDING_ANNOUNCE_CHAT, LENDING_POLL_SECONDS, LENDING_MAX_BLOCK_RANGE,
    LENDING_COMPTROLLER,
    CYBERSOL_SWAP_ADDRESS, CYBERSOL_SWAP_ANNOUNCE_CHAT,
    CYBERSOL_SWAP_POLL_SECONDS, CYBERSOL_SWAP_MAX_BLOCK_RANGE,
    MIN_ANNOUNCE_USD, BIG_ANNOUNCE_USD, RITUAL_V2_FACTORY,
    DIGEST_ANNOUNCE_CHAT, DIGEST_INTERVAL_SECONDS, DIGEST_RETENTION_DAYS,
    MARKET_SNAPSHOT_SECONDS, CYBER_CA_EVM,
    SOLANA_RPC_URL, CYBER_SOL_MINT, CYBER_SOL_DECIMALS, WHALE_MIN_RAW,
    WHALE_CHAT_ID, WHALE_POLL_SECONDS, WHALE_RECHECK_SECONDS, DB_PATH,
)
from bot.db import (
    engine, _kv_get, _kv_set, _record_activity,
    _get_block_cursor, _set_block_cursor, _get_swap_cursor, _set_swap_cursor,
)
from bot.chain import (
    SWAP_EVENT_TOPIC, PAIR_RESERVES_ABI,
    _event_topic, _router_topic_hex,
    _hex_no_prefix, _decode_topic_address, _decode_swap_data, _decode_data_words,
    _get_pair_tokens, _get_token_meta, _get_tx_sender,
    _get_token_usd_price, _swap_usd_volume, _liquidity_usd_volume,
    _get_lending_markets, _get_market_underlying,
)
from bot.utils import (
    _short_addr, _format_decimal_amount, _format_token_amount, _fmt_usd,
    _format_window,
)

logger = logging.getLogger(__name__)


def _bridge_tx_links(direction: str, source_tx: str, dest_tx: str | None) -> tuple[str, str | None]:
    """Return (source_link, dest_link). Direction tells us which chain each tx is on."""
    if direction == "sol_to_evm":
        src = f"{SOLSCAN_URL}/tx/{source_tx}" if source_tx else ""
        dst = f"{EXPLORER_URL}/tx/{dest_tx}" if dest_tx else None
    else:  # evm_to_sol (and anything else falls back to this layout)
        src = f"{EXPLORER_URL}/tx/{source_tx}" if source_tx else ""
        dst = f"{SOLSCAN_URL}/tx/{dest_tx}" if dest_tx else None
    return src, dst


def _direction_label(direction: str) -> str:
    return {
        "sol_to_evm": "Solana → Cyberia",
        "evm_to_sol": "Cyberia → Solana",
    }.get(direction, direction)


def _bridge_token_usd_price(symbol: str) -> float | None:
    """Best-effort USD price for a bridged token symbol. Stables are $1;
    CYBER variants are priced via the on-chain walker through CYBER_CA_EVM."""
    sym = (symbol or "").upper()
    if sym in ("USDC", "USDT"):
        return 1.0
    if sym.startswith("CYBER") and CYBER_CA_EVM:
        try:
            return _get_token_usd_price(Web3(Web3.HTTPProvider(RPC_URL)), CYBER_CA_EVM)
        except Exception as e:
            logger.debug(f"bridge price: CYBER lookup failed: {e}")
    return None


async def _announce_bridge_tick(bot) -> None:
    """Find newly-completed bridge_requests and announce them in BRIDGE_ANNOUNCE_CHAT.

    Advances `last_announced_bridge_id` only when send_message succeeds, so a
    transient Telegram failure is retried on the next tick.
    """
    last_id = int(_kv_get("last_announced_bridge_id", "0") or "0")

    with engine.connect() as conn:
        rows = conn.execute(
            text("""
                SELECT id, direction, token, source_tx_hash, sender_address,
                       recipient_address, amount, destination_tx_hash
                FROM bridge_requests
                WHERE status = 'completed'
                  AND id > :last
                ORDER BY id ASC
                LIMIT 50
            """),
            {"last": last_id},
        ).fetchall()

    for row in rows:
        (req_id, direction, token, source_tx, sender, recipient,
         amount, dest_tx) = row
        src_link, dst_link = _bridge_tx_links(direction, source_tx or "", dest_tx)
        lines = [
            f"🌉 Bridge: {_direction_label(direction)}",
            f"Amount: {_format_decimal_amount(amount)} {token}",
            f"From: {_short_addr(sender)}",
            f"To: {_short_addr(recipient)}",
        ]
        if src_link:
            lines.append(f"Source: {src_link}")
        if dst_link:
            lines.append(f"Destination: {dst_link}")
        try:
            await bot.send_message(
                chat_id=BRIDGE_ANNOUNCE_CHAT,
                text="\n".join(lines),
                disable_web_page_preview=True,
            )
        except TelegramError as e:
            logger.error(f"announce_bridge: send failed for id={req_id}: {e}")
            return
        try:
            amt = float(_format_decimal_amount(amount))
        except ValueError:
            amt = None
        price = _bridge_token_usd_price(token)
        _record_activity(
            "bridge",
            usd=amt * price if (amt is not None and price is not None) else None,
            sym_in=token, amt_in=amt,
            user_addr=sender, tx_hash=source_tx, meta=direction,
        )
        _kv_set("last_announced_bridge_id", str(req_id))
        logger.info(f"announce_bridge: posted bridge id={req_id} ({direction})")


async def bridge_announcer_loop(application: Application) -> None:
    """Background loop that polls bridge_requests every BRIDGE_POLL_SECONDS."""
    # Bootstrap: on a fresh install we skip historical completed bridges so the
    # chat doesn't get spammed with the entire history on first run.
    if _kv_get("last_announced_bridge_id") is None:
        try:
            with engine.connect() as conn:
                max_id = conn.execute(
                    text("SELECT COALESCE(MAX(id), 0) FROM bridge_requests WHERE status = 'completed'"),
                ).scalar() or 0
        except Exception as e:
            logger.warning(f"bridge_announcer: bootstrap query failed: {e}")
            max_id = 0
        _kv_set("last_announced_bridge_id", str(max_id))
        logger.info(f"bridge_announcer: bootstrapped last_announced_bridge_id={max_id}")

    while True:
        try:
            await _announce_bridge_tick(application.bot)
        except Exception as e:
            logger.error(f"bridge_announcer_loop: {e}")
        await asyncio.sleep(BRIDGE_POLL_SECONDS)
def _decode_swap_hop(w3: Web3, log) -> dict | None:
    """Decode one Swap log into a hop dict, or None when the pair is unknown
    or the event has no clear in/out direction."""
    tokens = _get_pair_tokens(w3, log["address"])
    if not tokens:
        return None
    t0, t1 = tokens
    sym0, dec0 = _get_token_meta(w3, t0)
    sym1, dec1 = _get_token_meta(w3, t1)
    a0in, a1in, a0out, a1out = _decode_swap_data(log["data"])

    if a0in > 0 and a1out > 0:
        in_addr, in_sym, in_amt, in_dec = t0, sym0, a0in, dec0
        out_addr, out_sym, out_amt, out_dec = t1, sym1, a1out, dec1
    elif a1in > 0 and a0out > 0:
        in_addr, in_sym, in_amt, in_dec = t1, sym1, a1in, dec1
        out_addr, out_sym, out_amt, out_dec = t0, sym0, a0out, dec0
    else:
        return None

    return {
        "in_addr": in_addr, "in_sym": in_sym, "in_amt": in_amt, "in_dec": in_dec,
        "out_addr": out_addr, "out_sym": out_sym, "out_amt": out_amt, "out_dec": out_dec,
        "to": _decode_topic_address(log["topics"][2]),
        "route": None,
    }
async def _announce_swap_tick(bot) -> None:
    """Scan new Swap events from the Ritual V2 router and post one msg per
    trade. A routed swap (A → B → C) emits one Swap event per pair with the
    *next pair* as the intermediate recipient, so consecutive events of the
    same tx are merged into a single first-in → last-out announcement.

    Cursor is stored as `block:log_index` so a mid-tick Telegram failure
    resumes exactly where it stopped without duplicating earlier sends.
    """
    w3 = Web3(Web3.HTTPProvider(RPC_URL))
    try:
        latest = w3.eth.block_number
    except Exception as e:
        logger.error(f"swap_announcer: block_number failed: {e}")
        return

    cursor = _get_swap_cursor()
    if cursor is None:
        # Don't backfill historical swaps on first run.
        _set_swap_cursor(latest, 10**9)
        logger.info(f"swap_announcer: bootstrapped cursor to head block={latest}")
        return
    cur_block, cur_idx = cursor

    if cur_block > latest:
        return

    end = min(cur_block + SWAP_MAX_BLOCK_RANGE - 1, latest)

    try:
        logs = w3.eth.get_logs({
            "fromBlock": cur_block,
            "toBlock": end,
            "topics": [SWAP_EVENT_TOPIC, _router_topic_hex()],
        })
    except Exception as e:
        logger.error(f"swap_announcer: get_logs {cur_block}..{end}: {e}")
        return

    new_logs = [
        log for log in sorted(logs, key=lambda l: (int(l["blockNumber"]), int(l["logIndex"])))
        if (int(log["blockNumber"]), int(log["logIndex"])) > (cur_block, cur_idx)
    ]
    threshold = max(MIN_ANNOUNCE_USD, BIG_ANNOUNCE_USD)

    i = 0
    while i < len(new_logs):
        # Within one block, a tx's logs occupy a contiguous logIndex range, so
        # the Swap events of one routed trade are consecutive here.
        tx_raw = new_logs[i]["transactionHash"]
        group = [new_logs[i]]
        j = i + 1
        while j < len(new_logs) and new_logs[j]["transactionHash"] == tx_raw:
            group.append(new_logs[j])
            j += 1
        i = j

        last_blk = int(group[-1]["blockNumber"])
        last_idx = int(group[-1]["logIndex"])
        tx_hash = "0x" + _hex_no_prefix(tx_raw).lower()

        try:
            hops = [h for h in (_decode_swap_hop(w3, log) for log in group) if h is not None]
        except Exception as e:
            logger.error(f"swap_announcer: decode failed tx={tx_hash}: {e}")
            hops = []
        if not hops:
            _set_swap_cursor(last_blk, last_idx)
            cur_block, cur_idx = last_blk, last_idx
            continue

        # Merge a chained route into one announcement: trade in = first hop's
        # input, trade out = last hop's output, trader = last hop's recipient.
        is_route = len(hops) > 1 and all(
            hops[k]["out_addr"].lower() == hops[k + 1]["in_addr"].lower()
            for k in range(len(hops) - 1)
        )
        if is_route:
            first, last = hops[0], hops[-1]
            announcements = [{
                **{k: first[k] for k in ("in_addr", "in_sym", "in_amt", "in_dec")},
                **{k: last[k] for k in ("out_addr", "out_sym", "out_amt", "out_dec")},
                "to": last["to"],
                "route": [first["in_sym"]] + [h["in_sym"] for h in hops[1:]] + [last["out_sym"]],
            }]
        else:
            announcements = hops

        aborted = False
        for ann in announcements:
            try:
                usd = _swap_usd_volume(
                    w3,
                    ann["in_addr"], ann["in_amt"], ann["in_dec"],
                    ann["out_addr"], ann["out_amt"], ann["out_dec"],
                )
                event_kwargs = dict(
                    kind="swap", usd=usd,
                    sym_in=ann["in_sym"], amt_in=ann["in_amt"] / 10**ann["in_dec"],
                    sym_out=ann["out_sym"], amt_out=ann["out_amt"] / 10**ann["out_dec"],
                    user_addr=ann["to"], tx_hash=tx_hash, block=last_blk,
                )

                # Only big swaps get their own post; everything below the
                # threshold is recorded and surfaces in the periodic digest.
                # Unprice-able swaps still post so novel pairs the price walker
                # can't reach yet stay visible.
                if usd is not None and usd < threshold:
                    _record_activity(**event_kwargs)
                    logger.info(
                        f"swap_announcer: digest-only tx={tx_hash} "
                        f"usd={usd:.4f} < {threshold}"
                    )
                    continue

                text_lines = [
                    "🔄 Swap on Ritual",
                    f"{_format_token_amount(ann['in_amt'], ann['in_dec'])} {ann['in_sym']} → "
                    f"{_format_token_amount(ann['out_amt'], ann['out_dec'])} {ann['out_sym']}",
                ]
                if ann["route"]:
                    text_lines.append("Route: " + " → ".join(ann["route"]))
                if usd is not None:
                    text_lines.append(f"Value: {_fmt_usd(usd)}")
                text_lines += [
                    f"User: {_short_addr(ann['to'])}",
                    f"Tx: {EXPLORER_URL}/tx/{tx_hash}",
                ]
            except Exception as e:
                logger.error(f"swap_announcer: build failed tx={tx_hash}: {e}")
                continue

            try:
                await bot.send_message(
                    chat_id=SWAP_ANNOUNCE_CHAT,
                    text="\n".join(text_lines),
                    disable_web_page_preview=True,
                )
            except TelegramError as e:
                logger.error(f"swap_announcer: send failed tx={tx_hash}: {e}")
                aborted = True
                break

            # Record only after a successful send so a mid-tick retry can't
            # double-count the same swap in the digest.
            _record_activity(**event_kwargs)
            logger.info(f"swap_announcer: posted swap block={last_blk} tx={tx_hash}")

        if aborted:
            return  # cursor still before this tx; the whole group retries next tick

        _set_swap_cursor(last_blk, last_idx)
        cur_block, cur_idx = last_blk, last_idx

    # Empty range, or fully drained — advance past the scanned window so we
    # don't refetch the same blocks indefinitely.
    if end > cur_block:
        _set_swap_cursor(end, 10**9)


async def swap_announcer_loop(application: Application) -> None:
    while True:
        try:
            await _announce_swap_tick(application.bot)
        except Exception as e:
            logger.error(f"swap_announcer_loop: {e}")
        await asyncio.sleep(SWAP_POLL_SECONDS)
# keccak256("Mint(address,uint256,uint256)") / "Burn(address,uint256,uint256,address)".
LP_MINT_TOPIC = _event_topic("Mint(address,uint256,uint256)")
LP_BURN_TOPIC = _event_topic("Burn(address,uint256,uint256,address)")
async def _announce_liquidity_tick(bot) -> None:
    """Scan V2 Mint/Burn events routed through the Ritual router and announce
    each add/remove. Mirrors the swap announcer's cursor handling."""
    w3 = Web3(Web3.HTTPProvider(RPC_URL))
    try:
        latest = w3.eth.block_number
    except Exception as e:
        logger.error(f"liquidity_announcer: block_number failed: {e}")
        return

    cursor = _get_block_cursor("last_announced_liq_cursor")
    if cursor is None:
        _set_block_cursor("last_announced_liq_cursor", latest, 10**9)
        logger.info(f"liquidity_announcer: bootstrapped cursor to head block={latest}")
        return
    cur_block, cur_idx = cursor
    if cur_block > latest:
        return
    end = min(cur_block + LIQUIDITY_MAX_BLOCK_RANGE - 1, latest)

    try:
        logs = w3.eth.get_logs({
            "fromBlock": cur_block,
            "toBlock": end,
            "topics": [[LP_MINT_TOPIC, LP_BURN_TOPIC], _router_topic_hex()],
        })
    except Exception as e:
        logger.error(f"liquidity_announcer: get_logs {cur_block}..{end}: {e}")
        return

    for log in sorted(logs, key=lambda l: (int(l["blockNumber"]), int(l["logIndex"]))):
        blk = int(log["blockNumber"])
        idx = int(log["logIndex"])
        if (blk, idx) <= (cur_block, cur_idx):
            continue

        try:
            topic0 = "0x" + _hex_no_prefix(log["topics"][0]).lower()
            is_add = topic0 == LP_MINT_TOPIC.lower()
            tokens = _get_pair_tokens(w3, log["address"])
            if not tokens:
                _set_block_cursor("last_announced_liq_cursor", blk, idx)
                cur_block, cur_idx = blk, idx
                continue
            t0, t1 = tokens
            sym0, dec0 = _get_token_meta(w3, t0)
            sym1, dec1 = _get_token_meta(w3, t1)
            # Both Mint and Burn carry (amount0, amount1) as the first two words.
            words = _decode_data_words(log["data"], 2)
            amount0, amount1 = words[0], words[1]

            usd = _liquidity_usd_volume(
                w3, t0, t1, dec0, dec1, amount0, amount1
            )

            tx_hash = "0x" + _hex_no_prefix(log["transactionHash"]).lower()
            # Burn carries the LP recipient in topics[2]; for Mint we fall back
            # to the tx initiator (the EOA that called the router).
            if not is_add and len(log["topics"]) > 2:
                user = _decode_topic_address(log["topics"][2])
            else:
                user = _get_tx_sender(w3, tx_hash) or "?"

            event_kwargs = dict(
                kind="liq_add" if is_add else "liq_remove", usd=usd,
                sym_in=sym0, amt_in=amount0 / 10**dec0,
                sym_out=sym1, amt_out=amount1 / 10**dec1,
                user_addr=user, tx_hash=tx_hash, block=blk,
            )

            threshold = max(MIN_ANNOUNCE_USD, BIG_ANNOUNCE_USD)
            if usd is not None and usd < threshold:
                _record_activity(**event_kwargs)
                logger.info(
                    f"liquidity_announcer: digest-only block={blk} idx={idx} "
                    f"usd={usd:.4f} < {threshold}"
                )
                _set_block_cursor("last_announced_liq_cursor", blk, idx)
                cur_block, cur_idx = blk, idx
                continue

            verb = "added" if is_add else "removed"
            sign = "+" if is_add else "-"
            text_lines = [
                f"💧 Liquidity {verb} on Ritual",
                f"{sign}{_format_token_amount(amount0, dec0)} {sym0} + "
                f"{sign}{_format_token_amount(amount1, dec1)} {sym1}",
            ]
            if usd is not None:
                text_lines.append(f"Value: {_fmt_usd(usd)}")
            text_lines += [
                f"User: {_short_addr(user)}",
                f"Tx: {EXPLORER_URL}/tx/{tx_hash}",
            ]
        except Exception as e:
            logger.error(f"liquidity_announcer: decode failed block={blk} idx={idx}: {e}")
            _set_block_cursor("last_announced_liq_cursor", blk, idx)
            cur_block, cur_idx = blk, idx
            continue

        try:
            await bot.send_message(
                chat_id=LIQUIDITY_ANNOUNCE_CHAT,
                text="\n".join(text_lines),
                disable_web_page_preview=True,
            )
        except TelegramError as e:
            logger.error(f"liquidity_announcer: send failed block={blk} idx={idx}: {e}")
            return

        _record_activity(**event_kwargs)
        _set_block_cursor("last_announced_liq_cursor", blk, idx)
        cur_block, cur_idx = blk, idx
        logger.info(f"liquidity_announcer: posted {verb} block={blk} idx={idx} tx={tx_hash}")

    if end > cur_block:
        _set_block_cursor("last_announced_liq_cursor", end, 10**9)


async def liquidity_announcer_loop(application: Application) -> None:
    while True:
        try:
            await _announce_liquidity_tick(application.bot)
        except Exception as e:
            logger.error(f"liquidity_announcer_loop: {e}")
        await asyncio.sleep(LIQUIDITY_POLL_SECONDS)
LEND_MINT_TOPIC = _event_topic("Mint(address,uint256,uint256)")
LEND_REDEEM_TOPIC = _event_topic("Redeem(address,uint256,uint256)")
LEND_BORROW_TOPIC = _event_topic("Borrow(address,uint256,uint256,uint256)")
LEND_REPAY_TOPIC = _event_topic("RepayBorrow(address,address,uint256,uint256,uint256)")

_LEND_ACTION = {
    LEND_MINT_TOPIC.lower():   ("supplied", "🏦"),
    LEND_REDEEM_TOPIC.lower(): ("withdrew", "🏦"),
    LEND_BORROW_TOPIC.lower(): ("borrowed", "💸"),
    LEND_REPAY_TOPIC.lower():  ("repaid",   "💵"),
}
async def _announce_lending_tick(bot) -> None:
    """Scan supply/withdraw/borrow/repay events from every lending market and
    announce them. Amounts are in underlying-token units."""
    if not LENDING_COMPTROLLER:
        return

    w3 = Web3(Web3.HTTPProvider(RPC_URL))
    try:
        latest = w3.eth.block_number
    except Exception as e:
        logger.error(f"lending_announcer: block_number failed: {e}")
        return

    cursor = _get_block_cursor("last_announced_lend_cursor")
    if cursor is None:
        _set_block_cursor("last_announced_lend_cursor", latest, 10**9)
        logger.info(f"lending_announcer: bootstrapped cursor to head block={latest}")
        return
    cur_block, cur_idx = cursor
    if cur_block > latest:
        return
    end = min(cur_block + LENDING_MAX_BLOCK_RANGE - 1, latest)

    try:
        markets = _get_lending_markets(w3)
    except Exception as e:
        logger.error(f"lending_announcer: getAllMarkets failed: {e}")
        return
    if not markets:
        return

    try:
        logs = w3.eth.get_logs({
            "fromBlock": cur_block,
            "toBlock": end,
            "address": markets,
            "topics": [[LEND_MINT_TOPIC, LEND_REDEEM_TOPIC, LEND_BORROW_TOPIC, LEND_REPAY_TOPIC]],
        })
    except Exception as e:
        logger.error(f"lending_announcer: get_logs {cur_block}..{end}: {e}")
        return

    for log in sorted(logs, key=lambda l: (int(l["blockNumber"]), int(l["logIndex"]))):
        blk = int(log["blockNumber"])
        idx = int(log["logIndex"])
        if (blk, idx) <= (cur_block, cur_idx):
            continue

        try:
            topic0 = "0x" + _hex_no_prefix(log["topics"][0]).lower()
            action = _LEND_ACTION.get(topic0)
            if action is None:
                _set_block_cursor("last_announced_lend_cursor", blk, idx)
                cur_block, cur_idx = blk, idx
                continue
            verb, emoji = action

            underlying = _get_market_underlying(w3, log["address"])
            if underlying is None:
                _set_block_cursor("last_announced_lend_cursor", blk, idx)
                cur_block, cur_idx = blk, idx
                continue
            sym, dec = _get_token_meta(w3, underlying)

            # First data word is always the underlying amount (mint/redeem/borrow/
            # repay all lead with it). The acting user is the first indexed arg,
            # except RepayBorrow where topics[1]=payer and topics[2]=borrower.
            amount = _decode_data_words(log["data"], 1)[0]
            if topic0 == LEND_REPAY_TOPIC.lower() and len(log["topics"]) > 2:
                user = _decode_topic_address(log["topics"][2])
            else:
                user = _decode_topic_address(log["topics"][1])

            price = _get_token_usd_price(w3, underlying)
            usd = (amount / 10**dec) * price if price is not None else None

            tx_hash = "0x" + _hex_no_prefix(log["transactionHash"]).lower()
            event_kwargs = dict(
                kind=f"lend_{verb}", usd=usd,
                sym_in=sym, amt_in=amount / 10**dec,
                user_addr=user, tx_hash=tx_hash, block=blk,
            )

            threshold = max(MIN_ANNOUNCE_USD, BIG_ANNOUNCE_USD)
            if usd is not None and usd < threshold:
                _record_activity(**event_kwargs)
                logger.info(
                    f"lending_announcer: digest-only block={blk} idx={idx} "
                    f"usd={usd:.4f} < {threshold}"
                )
                _set_block_cursor("last_announced_lend_cursor", blk, idx)
                cur_block, cur_idx = blk, idx
                continue

            text_lines = [
                f"{emoji} Lending: {verb} {_format_token_amount(amount, dec)} {sym}",
            ]
            if usd is not None:
                text_lines.append(f"Value: {_fmt_usd(usd)}")
            text_lines += [
                f"User: {_short_addr(user)}",
                f"Tx: {EXPLORER_URL}/tx/{tx_hash}",
            ]
        except Exception as e:
            logger.error(f"lending_announcer: decode failed block={blk} idx={idx}: {e}")
            _set_block_cursor("last_announced_lend_cursor", blk, idx)
            cur_block, cur_idx = blk, idx
            continue

        try:
            await bot.send_message(
                chat_id=LENDING_ANNOUNCE_CHAT,
                text="\n".join(text_lines),
                disable_web_page_preview=True,
            )
        except TelegramError as e:
            logger.error(f"lending_announcer: send failed block={blk} idx={idx}: {e}")
            return

        _record_activity(**event_kwargs)
        _set_block_cursor("last_announced_lend_cursor", blk, idx)
        cur_block, cur_idx = blk, idx
        logger.info(f"lending_announcer: posted {verb} block={blk} idx={idx} tx={tx_hash}")

    if end > cur_block:
        _set_block_cursor("last_announced_lend_cursor", end, 10**9)


async def lending_announcer_loop(application: Application) -> None:
    while True:
        try:
            await _announce_lending_tick(application.bot)
        except Exception as e:
            logger.error(f"lending_announcer_loop: {e}")
        await asyncio.sleep(LENDING_POLL_SECONDS)
# keccak256("Swapped(address,uint256,uint256)") — CyberSolSwap fixed-rate redeem.
CYBERSOL_SWAPPED_TOPIC = _event_topic("Swapped(address,uint256,uint256)")
# The bridged CYBER.sol ERC20 and native CYBER both use 18 decimals (the
# redeemer's 1000:1 wei math relies on it), so both amounts decode at 18.
_CYBERSOL_DECIMALS = 18
async def _announce_cybersol_swap_tick(bot) -> None:
    """Scan CyberSolSwap `Swapped` events and announce each CYBER.sol -> native
    CYBER conversion. Cursor handling mirrors the lending announcer; the input
    side (CYBER.sol == CYBER_CA_EVM) carries the priceable value."""
    if not CYBERSOL_SWAP_ADDRESS:
        return

    w3 = Web3(Web3.HTTPProvider(RPC_URL))
    try:
        latest = w3.eth.block_number
    except Exception as e:
        logger.error(f"cybersol_swap_announcer: block_number failed: {e}")
        return

    cursor = _get_block_cursor("last_announced_cybersol_cursor")
    if cursor is None:
        _set_block_cursor("last_announced_cybersol_cursor", latest, 10**9)
        logger.info(f"cybersol_swap_announcer: bootstrapped cursor to head block={latest}")
        return
    cur_block, cur_idx = cursor
    if cur_block > latest:
        return
    end = min(cur_block + CYBERSOL_SWAP_MAX_BLOCK_RANGE - 1, latest)

    try:
        logs = w3.eth.get_logs({
            "fromBlock": cur_block,
            "toBlock": end,
            "address": Web3.to_checksum_address(CYBERSOL_SWAP_ADDRESS),
            "topics": [CYBERSOL_SWAPPED_TOPIC],
        })
    except Exception as e:
        logger.error(f"cybersol_swap_announcer: get_logs {cur_block}..{end}: {e}")
        return

    for log in sorted(logs, key=lambda l: (int(l["blockNumber"]), int(l["logIndex"]))):
        blk = int(log["blockNumber"])
        idx = int(log["logIndex"])
        if (blk, idx) <= (cur_block, cur_idx):
            continue

        try:
            # Swapped data words: amountIn (CYBER.sol), amountOut (native CYBER).
            amount_in, amount_out = _decode_data_words(log["data"], 2)
            user = _decode_topic_address(log["topics"][1])
            tx_hash = "0x" + _hex_no_prefix(log["transactionHash"]).lower()

            price = None
            if CYBER_CA_EVM:
                try:
                    price = _get_token_usd_price(w3, CYBER_CA_EVM)
                except Exception as e:
                    logger.debug(f"cybersol_swap_announcer: price lookup failed: {e}")
            usd = (amount_in / 10**_CYBERSOL_DECIMALS) * price if price is not None else None

            event_kwargs = dict(
                kind="convert", usd=usd,
                sym_in="CYBER.sol", amt_in=amount_in / 10**_CYBERSOL_DECIMALS,
                sym_out="CYBER", amt_out=amount_out / 10**_CYBERSOL_DECIMALS,
                user_addr=user, tx_hash=tx_hash, block=blk,
            )

            threshold = max(MIN_ANNOUNCE_USD, BIG_ANNOUNCE_USD)
            if usd is not None and usd < threshold:
                _record_activity(**event_kwargs)
                logger.info(
                    f"cybersol_swap_announcer: digest-only block={blk} idx={idx} "
                    f"usd={usd:.4f} < {threshold}"
                )
                _set_block_cursor("last_announced_cybersol_cursor", blk, idx)
                cur_block, cur_idx = blk, idx
                continue

            text_lines = [
                "🔁 CYBER.sol → CYBER conversion",
                f"{_format_token_amount(amount_in, _CYBERSOL_DECIMALS)} CYBER.sol → "
                f"{_format_token_amount(amount_out, _CYBERSOL_DECIMALS)} CYBER",
            ]
            if usd is not None:
                text_lines.append(f"Value: {_fmt_usd(usd)}")
            text_lines += [
                f"User: {_short_addr(user)}",
                f"Tx: {EXPLORER_URL}/tx/{tx_hash}",
            ]
        except Exception as e:
            logger.error(f"cybersol_swap_announcer: decode failed block={blk} idx={idx}: {e}")
            _set_block_cursor("last_announced_cybersol_cursor", blk, idx)
            cur_block, cur_idx = blk, idx
            continue

        try:
            await bot.send_message(
                chat_id=CYBERSOL_SWAP_ANNOUNCE_CHAT,
                text="\n".join(text_lines),
                disable_web_page_preview=True,
            )
        except TelegramError as e:
            logger.error(f"cybersol_swap_announcer: send failed block={blk} idx={idx}: {e}")
            return

        _record_activity(**event_kwargs)
        _set_block_cursor("last_announced_cybersol_cursor", blk, idx)
        cur_block, cur_idx = blk, idx
        logger.info(f"cybersol_swap_announcer: posted block={blk} idx={idx} tx={tx_hash}")

    if end > cur_block:
        _set_block_cursor("last_announced_cybersol_cursor", end, 10**9)


async def cybersol_swap_announcer_loop(application: Application) -> None:
    while True:
        try:
            await _announce_cybersol_swap_tick(application.bot)
        except Exception as e:
            logger.error(f"cybersol_swap_announcer_loop: {e}")
        await asyncio.sleep(CYBERSOL_SWAP_POLL_SECONDS)
_KV_LAST_DIGEST_AT = "last_digest_at"
_KV_PREV_CYBER_PRICE = "digest_prev_cyber_price"
_SQLITE_TS = "%Y-%m-%d %H:%M:%S"
def _cyber_price_line(update_prev: bool = False) -> str | None:
    """'💰 CYBER: $… (+x% …)' line, or None when CYBER can't be priced.
    `update_prev` stores the fresh price as the next digest's comparison base."""
    if not CYBER_CA_EVM:
        return None
    try:
        w3 = Web3(Web3.HTTPProvider(RPC_URL))
        price = _get_token_usd_price(w3, CYBER_CA_EVM)
    except Exception as e:
        logger.warning(f"digest: CYBER price read failed: {e}")
        return None
    if price is None or price <= 0:
        return None
    line = f"💰 CYBER: ${price:.6f}"
    try:
        prev = float(_kv_get(_KV_PREV_CYBER_PRICE) or 0)
    except ValueError:
        prev = 0.0
    if prev > 0:
        line += f" ({(price - prev) / prev * 100:+.1f}% since last digest)"
    if update_prev:
        _kv_set(_KV_PREV_CYBER_PRICE, f"{price:.12g}")
    return line
def _build_digest_text(since: str, window_label: str) -> str | None:
    """Summary of activity_events recorded after `since` (sqlite UTC text).
    Returns None when the window is empty so quiet periods stay silent."""
    with engine.connect() as conn:
        swap_count, swap_vol, traders, unpriced = conn.execute(
            text("""
                SELECT COUNT(*), COALESCE(SUM(usd), 0), COUNT(DISTINCT user_addr),
                       SUM(CASE WHEN usd IS NULL THEN 1 ELSE 0 END)
                FROM activity_events
                WHERE kind = 'swap' AND created_at >= :s
            """),
            {"s": since},
        ).fetchone()
        top_tokens = conn.execute(
            text("""
                SELECT sym, SUM(usd) AS vol FROM (
                    SELECT sym_in AS sym, usd FROM activity_events
                    WHERE kind = 'swap' AND created_at >= :s AND usd IS NOT NULL
                    UNION ALL
                    SELECT sym_out, usd FROM activity_events
                    WHERE kind = 'swap' AND created_at >= :s AND usd IS NOT NULL
                ) GROUP BY sym ORDER BY vol DESC LIMIT 3
            """),
            {"s": since},
        ).fetchall()
        largest = conn.execute(
            text("""
                SELECT sym_in, amt_in, sym_out, amt_out, usd, user_addr
                FROM activity_events
                WHERE kind = 'swap' AND created_at >= :s AND usd IS NOT NULL
                ORDER BY usd DESC LIMIT 1
            """),
            {"s": since},
        ).fetchone()
        liq = {
            kind: (cnt, vol)
            for kind, cnt, vol in conn.execute(
                text("""
                    SELECT kind, COUNT(*), COALESCE(SUM(usd), 0)
                    FROM activity_events
                    WHERE kind IN ('liq_add', 'liq_remove') AND created_at >= :s
                    GROUP BY kind
                """),
                {"s": since},
            ).fetchall()
        }
        bridges = conn.execute(
            text("""
                SELECT meta, sym_in, COUNT(*), COALESCE(SUM(amt_in), 0)
                FROM activity_events
                WHERE kind = 'bridge' AND created_at >= :s
                GROUP BY meta, sym_in
            """),
            {"s": since},
        ).fetchall()
        lending = conn.execute(
            text("""
                SELECT kind, COUNT(*), COALESCE(SUM(usd), 0)
                FROM activity_events
                WHERE kind LIKE 'lend_%' AND created_at >= :s
                GROUP BY kind
            """),
            {"s": since},
        ).fetchall()
        conversions = conn.execute(
            text("""
                SELECT COUNT(*), COALESCE(SUM(usd), 0),
                       COALESCE(SUM(amt_in), 0), COALESCE(SUM(amt_out), 0)
                FROM activity_events
                WHERE kind = 'convert' AND created_at >= :s
            """),
            {"s": since},
        ).fetchone()

    total = (
        swap_count
        + sum(c for c, _v in liq.values())
        + sum(row[2] for row in bridges)
        + sum(row[1] for row in lending)
        + (conversions[0] if conversions else 0)
    )
    if total == 0:
        return None

    lines = [f"📊 Cyberia activity — last {window_label}", ""]

    if swap_count:
        swap_line = (
            f"🔄 Swaps: {swap_count} · volume {_fmt_usd(swap_vol)} · {traders} traders"
        )
        if unpriced:
            swap_line += f" ({unpriced} unpriced)"
        lines.append(swap_line)
        if top_tokens:
            lines.append(
                "  Top: " + " · ".join(f"{sym} {_fmt_usd(vol)}" for sym, vol in top_tokens)
            )
        if largest:
            l_sin, l_ain, l_sout, l_aout, l_usd, l_user = largest
            lines.append(
                f"  Largest: {l_ain:g} {l_sin} → {l_aout:g} {l_sout} "
                f"({_fmt_usd(l_usd)}) by {_short_addr(l_user)}"
            )

    if liq:
        add_c, add_v = liq.get("liq_add", (0, 0))
        rem_c, rem_v = liq.get("liq_remove", (0, 0))
        lines.append(
            f"💧 Liquidity: +{_fmt_usd(add_v)} added ({add_c}) / "
            f"-{_fmt_usd(rem_v)} removed ({rem_c})"
        )

    if bridges:
        parts = [
            f"{vol:g} {sym} {_direction_label(direction or '?')} ({cnt})"
            for direction, sym, cnt, vol in bridges
        ]
        lines.append("🌉 Bridges: " + " · ".join(parts))

    if lending:
        parts = [
            f"{kind.removeprefix('lend_')} {_fmt_usd(vol)} ({cnt})"
            for kind, cnt, vol in lending
        ]
        lines.append("🏦 Lending: " + ", ".join(parts))

    if conversions and conversions[0]:
        c_cnt, c_usd, c_in, c_out = conversions
        conv_line = f"🔁 CYBER.sol → CYBER: {c_in:g} → {c_out:g} CYBER ({c_cnt})"
        if c_usd:
            conv_line += f" · {_fmt_usd(c_usd)}"
        lines.append(conv_line)

    return "\n".join(lines)
async def _digest_tick(bot) -> None:
    now = datetime.now(timezone.utc)
    last_raw = _kv_get(_KV_LAST_DIGEST_AT)
    if last_raw is None:
        # Fresh install: start the window now instead of summarizing nothing.
        _kv_set(_KV_LAST_DIGEST_AT, now.strftime(_SQLITE_TS))
        return
    try:
        last = datetime.strptime(last_raw, _SQLITE_TS).replace(tzinfo=timezone.utc)
    except ValueError:
        _kv_set(_KV_LAST_DIGEST_AT, now.strftime(_SQLITE_TS))
        return
    elapsed = (now - last).total_seconds()
    if elapsed < DIGEST_INTERVAL_SECONDS:
        return

    since = last.strftime(_SQLITE_TS)
    digest = await asyncio.to_thread(_build_digest_text, since, _format_window(elapsed))
    if digest is None:
        # Quiet window: advance silently so the next digest doesn't double-count,
        # but keep the price base fresh.
        _kv_set(_KV_LAST_DIGEST_AT, now.strftime(_SQLITE_TS))
        await asyncio.to_thread(_cyber_price_line, True)
        logger.info(f"digest: no activity since {since}, skipping post")
        return

    price_line = await asyncio.to_thread(_cyber_price_line)
    if price_line:
        digest += "\n\n" + price_line

    try:
        await bot.send_message(
            chat_id=DIGEST_ANNOUNCE_CHAT, text=digest, disable_web_page_preview=True
        )
    except TelegramError as e:
        # Window stays open; the next tick retries with a slightly wider range.
        logger.error(f"digest: send failed: {e}")
        return

    _kv_set(_KV_LAST_DIGEST_AT, now.strftime(_SQLITE_TS))
    await asyncio.to_thread(_cyber_price_line, True)
    logger.info(f"digest: posted window since {since}")

    try:
        with engine.begin() as conn:
            conn.execute(
                text("DELETE FROM activity_events WHERE created_at < datetime('now', :cutoff)"),
                {"cutoff": f"-{DIGEST_RETENTION_DAYS} days"},
            )
    except Exception as e:
        logger.warning(f"digest: retention cleanup failed: {e}")


async def digest_loop(application: Application) -> None:
    while True:
        try:
            await _digest_tick(application.bot)
        except Exception as e:
            logger.error(f"digest_loop: {e}")
        await asyncio.sleep(60)
FACTORY_ENUM_ABI = [
    {"inputs": [], "name": "allPairsLength",
     "outputs": [{"name": "", "type": "uint256"}],
     "stateMutability": "view", "type": "function"},
    {"inputs": [{"name": "", "type": "uint256"}], "name": "allPairs",
     "outputs": [{"name": "", "type": "address"}],
     "stateMutability": "view", "type": "function"},
]

# (fetched_at, [(pair_addr, token0, token1), ...]). The factory's pair list
# only grows, so a long TTL is fine; reserves are re-read on every snapshot.
_all_pairs_cache: tuple[float, list[tuple[str, str, str]]] | None = None
_ALL_PAIRS_TTL_SECONDS = 600.0


def _get_all_pairs(w3: Web3) -> list[tuple[str, str, str]]:
    """Every pair in the Ritual factory. Cyberia has a few dozen, so straight
    enumeration is cheap, and pair→token reads hit _pair_token_cache."""
    global _all_pairs_cache
    now = time.time()
    if _all_pairs_cache is not None and now - _all_pairs_cache[0] < _ALL_PAIRS_TTL_SECONDS:
        return _all_pairs_cache[1]
    factory = w3.eth.contract(
        address=Web3.to_checksum_address(RITUAL_V2_FACTORY), abi=FACTORY_ENUM_ABI
    )
    count = factory.functions.allPairsLength().call()
    pairs: list[tuple[str, str, str]] = []
    for i in range(count):
        pair_addr = factory.functions.allPairs(i).call()
        tokens = _get_pair_tokens(w3, pair_addr)
        if tokens is not None:
            pairs.append((pair_addr, tokens[0], tokens[1]))
    _all_pairs_cache = (now, pairs)
    return pairs
def _take_market_snapshot(w3: Web3) -> tuple[int, int]:
    """Read every pool's reserves, price every token via the walker, and
    rewrite token_prices + dex_pools in one transaction. Blocking (a long
    chain of RPC reads) — call through asyncio.to_thread. Returns
    (pools_written, tokens_priced)."""
    now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")

    pool_rows: list[dict] = []
    token_addrs: set[str] = set()
    for pair_addr, t0, t1 in _get_all_pairs(w3):
        try:
            pair = w3.eth.contract(
                address=Web3.to_checksum_address(pair_addr), abi=PAIR_RESERVES_ABI
            )
            r0, r1, _ts = pair.functions.getReserves().call()
        except Exception as e:
            logger.debug(f"market_snapshot: getReserves {pair_addr} failed: {e}")
            continue
        sym0, dec0 = _get_token_meta(w3, t0)
        sym1, dec1 = _get_token_meta(w3, t1)
        token_addrs.add(t0)
        token_addrs.add(t1)
        pool_rows.append({
            "pair": pair_addr.lower(),
            "t0": t0.lower(), "t1": t1.lower(),
            "s0": sym0, "s1": sym1,
            "r0": r0 / 10**dec0, "r1": r1 / 10**dec1,
            "tvl": None,  # filled below once prices are known
            "ts": now,
        })

    prices: dict[str, float | None] = {}
    price_rows: list[dict] = []
    for addr in token_addrs:
        sym, _dec = _get_token_meta(w3, addr)
        try:
            price = _get_token_usd_price(w3, addr)
        except Exception as e:
            logger.debug(f"market_snapshot: price {addr} failed: {e}")
            price = None
        prices[addr.lower()] = price
        price_rows.append({"a": addr.lower(), "s": sym, "p": price, "ts": now})

    # TVL mirrors _liquidity_usd_volume: both sides of a constant-product pool
    # are equal in value, so a single priced side is simply doubled.
    for row in pool_rows:
        p0 = prices.get(row["t0"])
        p1 = prices.get(row["t1"])
        if p0 is not None and p1 is not None:
            row["tvl"] = row["r0"] * p0 + row["r1"] * p1
        elif p0 is not None:
            row["tvl"] = row["r0"] * p0 * 2
        elif p1 is not None:
            row["tvl"] = row["r1"] * p1 * 2

    with engine.begin() as conn:
        conn.execute(text("DELETE FROM token_prices"))
        if price_rows:
            conn.execute(
                text("""
                    INSERT INTO token_prices (address, symbol, price_usd, updated_at)
                    VALUES (:a, :s, :p, :ts)
                """),
                price_rows,
            )
        conn.execute(text("DELETE FROM dex_pools"))
        if pool_rows:
            conn.execute(
                text("""
                    INSERT INTO dex_pools
                        (pair_address, token0, token1, symbol0, symbol1,
                         reserve0, reserve1, tvl_usd, updated_at)
                    VALUES
                        (:pair, :t0, :t1, :s0, :s1, :r0, :r1, :tvl, :ts)
                """),
                pool_rows,
            )

    priced = sum(1 for p in prices.values() if p is not None)
    return len(pool_rows), priced


async def market_snapshot_loop(application: Application) -> None:
    while True:
        try:
            pools, priced = await asyncio.to_thread(
                _take_market_snapshot, Web3(Web3.HTTPProvider(RPC_URL))
            )
            logger.info(
                f"market_snapshot: {pools} pools, {priced} tokens priced"
            )
        except Exception as e:
            logger.error(f"market_snapshot_loop: {e}")
        await asyncio.sleep(MARKET_SNAPSHOT_SECONDS)
def _read_cyber_sol_raw(address: str) -> int:
    """Sum the owner's CYBER.sol balance (base units) via Solana RPC. Blocking —
    call through asyncio.to_thread from the event loop."""
    payload = json.dumps({
        "jsonrpc": "2.0",
        "id": 1,
        "method": "getTokenAccountsByOwner",
        "params": [
            address,
            {"mint": CYBER_SOL_MINT},
            {"encoding": "jsonParsed", "commitment": "confirmed"},
        ],
    }).encode()
    req = urllib.request.Request(
        SOLANA_RPC_URL, data=payload, headers={"Content-Type": "application/json"}
    )
    with urllib.request.urlopen(req, timeout=15) as resp:
        data = json.loads(resp.read().decode())
    if "error" in data:
        raise RuntimeError(f"Solana RPC error: {data['error']}")
    total = 0
    for acc in (data.get("result", {}).get("value") or []):
        amount = acc["account"]["data"]["parsed"]["info"]["tokenAmount"]["amount"]
        total += int(amount)
    return total
async def _issue_whale_invites(bot) -> None:
    """DM a single-use invite link to every verified whale not yet invited."""
    with engine.connect() as conn:
        rows = conn.execute(
            text("""
                SELECT tg_user_id, solana_address, balance_raw
                FROM tg_sol_wallets
                WHERE is_whale = 1 AND invited = 0
            """)
        ).fetchall()

    for tg_user_id, address, balance_raw in rows:
        # Mint a single-use link. A failure here is chat-level (wrong
        # WHALE_CHAT_ID, or the bot is not an admin of the whales chat) and would
        # hit every pending whale, so abort the tick rather than logging the same
        # "Chat not found" once per row.
        try:
            link = await bot.create_chat_invite_link(
                WHALE_CHAT_ID,
                member_limit=1,
                expire_date=int(time.time()) + 3600,
                name=f"whale {tg_user_id}",
            )
        except Exception as e:
            logger.error(
                "whale: cannot create invite link for WHALE_CHAT_ID=%s — verify the id "
                "(supergroups are -100…) and that the bot is an admin there with "
                "'Invite Users via Link': %s",
                WHALE_CHAT_ID, e,
            )
            return

        # DM the link. A failure here is per-user (they never started the bot, or
        # blocked it); skip them and keep going.
        try:
            human = int(balance_raw) / 10 ** CYBER_SOL_DECIMALS
            await bot.send_message(
                tg_user_id,
                f"Verified: {human:,.0f} CYBER.sol on {address[:4]}…{address[-4:]}.\n"
                f"One-time invite to the whales chat:\n{link.invite_link}",
            )
        except Exception as e:
            logger.error(
                "whale: cannot DM invite to user=%s (have they started the bot?): %s",
                tg_user_id, e,
            )
            continue

        with engine.begin() as conn:
            conn.execute(
                text("UPDATE tg_sol_wallets SET invited = 1 WHERE tg_user_id = :u"),
                {"u": tg_user_id},
            )
        logger.info("whale: invited user=%s balance_raw=%s", tg_user_id, balance_raw)


async def _kick_from_whales(bot, tg_user_id: int) -> None:
    """Remove a user from the whales chat (kick, not perma-ban) and reset their
    invite flag so they can rejoin if they top up. Admins are never kicked."""
    try:
        member = await bot.get_chat_member(WHALE_CHAT_ID, tg_user_id)
        status = member.status
    except Exception:
        status = "left"

    if status in ("creator", "administrator"):
        return
    if status not in ("left", "kicked"):
        try:
            await bot.ban_chat_member(WHALE_CHAT_ID, tg_user_id)
            await bot.unban_chat_member(WHALE_CHAT_ID, tg_user_id)
            logger.info("whale: kicked user=%s (below threshold)", tg_user_id)
            try:
                await bot.send_message(
                    tg_user_id,
                    "Your CYBER.sol balance dropped below the whale threshold, so you were "
                    "removed from the whales chat. Top up and run /whale to rejoin.",
                )
            except Exception:
                pass
        except Exception as e:
            logger.error("whale: kick failed user=%s: %s", tg_user_id, e)

    with engine.begin() as conn:
        conn.execute(
            text("UPDATE tg_sol_wallets SET invited = 0 WHERE tg_user_id = :u"),
            {"u": tg_user_id},
        )


async def _recheck_whales(bot) -> None:
    """Re-read on-chain balances for rows staler than WHALE_RECHECK_SECONDS and
    kick anyone who fell below the threshold."""
    cutoff = (datetime.now(timezone.utc) - timedelta(seconds=WHALE_RECHECK_SECONDS)).strftime("%Y-%m-%d %H:%M:%S")
    with engine.connect() as conn:
        rows = conn.execute(
            text("""
                SELECT tg_user_id, solana_address, is_whale
                FROM tg_sol_wallets
                WHERE last_checked_at IS NULL OR last_checked_at < :cutoff
            """),
            {"cutoff": cutoff},
        ).fetchall()

    for tg_user_id, address, was_whale in rows:
        try:
            raw = await asyncio.to_thread(_read_cyber_sol_raw, address)
        except Exception as e:
            logger.warning("whale recheck: balance read failed for %s: %s", address, e)
            continue
        is_whale = 1 if raw >= WHALE_MIN_RAW else 0
        with engine.begin() as conn:
            conn.execute(
                text("""
                    UPDATE tg_sol_wallets
                    SET balance_raw = :b, is_whale = :w, last_checked_at = datetime('now')
                    WHERE tg_user_id = :u
                """),
                {"b": str(raw), "w": is_whale, "u": tg_user_id},
            )
        if was_whale and not is_whale:
            await _kick_from_whales(bot, tg_user_id)


async def whale_loop(application: Application) -> None:
    bot = application.bot
    # One-time self-check so a misconfigured WHALE_CHAT_ID surfaces clearly on
    # startup instead of as a recurring per-user "Chat not found".
    try:
        chat = await bot.get_chat(WHALE_CHAT_ID)
        me = await bot.get_me()
        member = await bot.get_chat_member(WHALE_CHAT_ID, me.id)
        logger.info(
            "Whale chat reachable: %r (id=%s); bot status=%s",
            chat.title, WHALE_CHAT_ID, member.status,
        )
        if member.status not in ("administrator", "creator"):
            logger.warning(
                "whale: bot is not an admin in WHALE_CHAT_ID=%s — it cannot create "
                "invite links; grant it 'Invite Users via Link'",
                WHALE_CHAT_ID,
            )
    except Exception as e:
        logger.error(
            "whale: WHALE_CHAT_ID=%s is unreachable — add the bot to that chat as an "
            "admin and verify the id (supergroups are -100…): %s",
            WHALE_CHAT_ID, e,
        )

    while True:
        try:
            await _issue_whale_invites(application.bot)
            await _recheck_whales(application.bot)
        except Exception as e:
            logger.error(f"whale_loop: {e}")
        await asyncio.sleep(WHALE_POLL_SECONDS)
def run_snapshot_once() -> None:
    """Refresh the analytics market snapshot (token_prices + dex_pools) once and
    exit. Needs only RPC + the shared SQLite DB — no Telegram token, no polling —
    so it can be run by hand or from cron to populate the /analytics page
    independently of the long-running bot."""
    logging.basicConfig(level=logging.INFO)
    w3 = Web3(Web3.HTTPProvider(RPC_URL))
    pools, priced = _take_market_snapshot(w3)
    logger.info(f"snapshot-once: {pools} pools, {priced} tokens priced -> {DB_PATH}")
