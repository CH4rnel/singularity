"""Read-only V2 buy/sell quotes against identified external reference assets.

The reserve ratio is never a trade price: every size walks the full route with
integer amounts, the pool's 0.3% fee and a final slippage buffer. Outside quotes
are references, not executable bids/offers or a promise of arbitrage profit.
"""
import json
import logging
import time
import urllib.parse
from dataclasses import dataclass
from decimal import Decimal, InvalidOperation
from pathlib import Path

from web3 import Web3
from web3.middleware import ExtraDataToPOAMiddleware
from telegram import InlineKeyboardButton, InlineKeyboardMarkup

from bot.chain import ERC20_META_ABI, FACTORY_GET_PAIR_ABI, PAIR_RESERVES_ABI
from bot.config import (
    RPC_URL, RITUAL_V2_FACTORY, USD_ANCHORS, PRICE_RELAY_TOKENS,
    CYBER_CA_EVM, CYBER_SOL_MINT, DEXSCREENER_API_URL, SWAP_URL,
    MARKET_GAP_SIZES_USD, MARKET_GAP_MIN_BPS, MARKET_GAP_MIN_USD,
    MARKET_GAP_CHANGE_BPS, MARKET_GAP_SLIPPAGE_BPS, MARKET_GAP_MAX_AGE_SECONDS,
)
from bot.pumpfun import _http_json, WSOL_MINT

logger = logging.getLogger(__name__)
STATE_KEY = "digest_market_gaps_v1"
# A ticker alone proves nothing. These IDs apply only to the wrapper addresses
# in our deployment registry; native CYBER is not CoinGecko's CyberConnect.
COINS = {
    "BTC": "bitcoin", "LTC": "litecoin", "SOL": "solana",
    "ETH": "ethereum", "BNB": "binancecoin", "XMR": "monero",
    "TRX": "tron", "YTN": "yenten", "USDC": "usd-coin", "USDT": "tether",
}
REGISTRY = Path(__file__).resolve().parents[3] / "crypto/hardhat/deployments/cyberia-tokens.json"


def _positive(value) -> Decimal | None:
    try:
        number = Decimal(str(value))
    except InvalidOperation:
        return None
    return number if number.is_finite() and number > 0 else None


@dataclass(frozen=True)
class Reference:
    price: Decimal
    source: str
    timestamp: float = 0


@dataclass(frozen=True)
class Gap:
    symbol: str
    side: str
    local: Decimal
    reference: Reference
    amount: Decimal
    stable: str
    stable_amount: Decimal
    edge_pct: Decimal
    advantage: Decimal
    path: tuple[str, ...] = ()

    @property
    def key(self) -> str:
        return f"{self.symbol}:{self.side}"


@dataclass(frozen=True)
class Scan:
    gaps: tuple[Gap, ...]
    observed: frozenset[str]
    block: int
    timestamp: int


def _references(assets: dict[str, str], now: float) -> dict[str, Reference]:
    references = {}
    ids = sorted({COINS[symbol] for symbol in assets if symbol in COINS})
    if ids:
        try:
            data = _http_json("https://api.coingecko.com/api/v3/simple/price?" + urllib.parse.urlencode({
                "ids": ",".join(ids), "vs_currencies": "usd",
                "include_last_updated_at": "true",
            }), timeout=8)
            for symbol in assets:
                row = data.get(COINS.get(symbol), {})
                price = _positive(row.get("usd"))
                age = now - float(row.get("last_updated_at") or 0)
                if price is not None and -30 <= age <= MARKET_GAP_MAX_AGE_SECONDS:
                    references[symbol] = Reference(price, "CoinGecko", float(row["last_updated_at"]))
        except Exception as error:
            logger.warning("market gaps: external references unavailable (%s)", type(error).__name__)

    if "CYBER.sol" in assets:
        try:
            data = _http_json(f"{DEXSCREENER_API_URL}/latest/dex/tokens/{CYBER_SOL_MINT}", timeout=8)
            pairs = []
            for pair in data.get("pairs") or []:
                # The feed has no quote timestamp. Require activity within its
                # five-minute bucket, and never reuse the buy bot's stale cache.
                recent = pair.get("txns", {}).get("m5") or {}
                price = _positive(pair.get("priceUsd"))
                depth = _positive((pair.get("liquidity") or {}).get("usd"))
                if (pair.get("chainId") == "solana"
                    and (pair.get("baseToken") or {}).get("address") == CYBER_SOL_MINT
                    and (pair.get("quoteToken") or {}).get("address") == WSOL_MINT
                    and price is not None and depth is not None and depth >= 1000
                    and int(recent.get("buys", 0)) + int(recent.get("sells", 0)) > 0):
                    pairs.append((depth, price))
            if pairs:
                references["CYBER.sol"] = Reference(max(pairs)[1], "DexScreener / Solana", time.time())
        except Exception as error:
            logger.warning("market gaps: Solana reference unavailable (%s)", type(error).__name__)
    return references


def _paths(graph: dict, start: str, end: str, relays: set[str], path=()):
    path = (*path, start)
    if start == end:
        yield path
    elif len(path) < 4:
        for following in sorted(graph.get(start, {})):
            if following not in path and (following == end or following in relays):
                yield from _paths(graph, following, end, relays, path)


def _quote(graph: dict, path: tuple[str, ...], amount: int) -> int:
    for first, second in zip(path, path[1:]):
        reserve_in, reserve_out = graph[first][second]
        taxed = amount * 997
        amount = taxed * reserve_out // (reserve_in * 1000 + taxed)
        if amount <= 0:
            return 0
    return amount * (10_000 - MARKET_GAP_SLIPPAGE_BPS) // 10_000


def _compare(assets, decimals, references, graph) -> tuple[Gap, ...]:
    sizes = sorted({size for raw in MARKET_GAP_SIZES_USD.split(",") if (size := _positive(raw))})
    floor_usd = _positive(MARKET_GAP_MIN_USD)
    if not sizes or floor_usd is None:
        return ()
    relays = {address.lower() for address in (*USD_ANCHORS, *PRICE_RELAY_TOKENS)}
    gaps = []
    for symbol, address in assets.items():
        if symbol in ("USDC", "USDT") or symbol not in references:
            continue
        ref = references[symbol]
        scale = 10 ** decimals[address]
        for side in ("buy", "sell"):
            best = None
            for stable in ("USDC", "USDT"):
                anchor = assets.get(stable)
                if anchor not in USD_ANCHORS or stable not in references:
                    continue
                stable_price = references[stable].price
                stable_scale = 10 ** decimals[anchor]
                start, end = (anchor, address) if side == "buy" else (address, anchor)
                paths = tuple(_paths(graph, start, end, relays))
                if not paths:
                    continue
                for size in sizes:
                    raw = int(size / (stable_price if side == "buy" else ref.price)
                              * (stable_scale if side == "buy" else scale))
                    if raw <= 0:
                        continue
                    output, path = max((_quote(graph, path, raw), path) for path in paths)
                    if output <= 0:
                        continue
                    amount = Decimal(output if side == "buy" else raw) / scale
                    stable_amount = Decimal(raw if side == "buy" else output) / stable_scale
                    value = stable_amount * stable_price
                    local = value / amount
                    difference = ref.price - local if side == "buy" else local - ref.price
                    edge = difference / ref.price * 100
                    advantage = difference * amount
                    if edge * 100 < MARKET_GAP_MIN_BPS or advantage < floor_usd:
                        continue
                    gap = Gap(symbol, side, local, ref, amount, stable, stable_amount, edge, advantage, path)
                    if best is None or gap.advantage > best.advantage:
                        best = gap
            if best is not None:
                gaps.append(best)
    return tuple(sorted(gaps, key=lambda gap: (-gap.advantage, gap.key)))


def scan() -> Scan | None:
    """None means unreadable, not an empty market. Never signs or reserves."""
    try:
        registry = json.loads(REGISTRY.read_text())
        if registry.get("chainId") != 49406:
            return None
        assets = {entry["symbol"]: entry["address"].lower()
                  for entry in registry["tokens"]
                  if entry["symbol"] in COINS and entry.get("address")}
        if CYBER_CA_EVM:
            assets["CYBER.sol"] = CYBER_CA_EVM.lower()
        references = _references(assets, time.time())
        if (not {"USDC", "USDT"}.issubset(references)
            or not any(symbol not in ("USDC", "USDT") for symbol in references)):
            return None
        w3 = Web3(Web3.HTTPProvider(RPC_URL, request_kwargs={"timeout": 5}))
        w3.middleware_onion.inject(ExtraDataToPOAMiddleware, layer=0)
        if w3.eth.chain_id != 49406:
            return None
        head = w3.eth.get_block("latest")
        block, timestamp = int(head["number"]), int(head["timestamp"])
        if not -30 <= time.time() - timestamp <= MARKET_GAP_MAX_AGE_SECONDS:
            return None
        assets = {symbol: address for symbol, address in assets.items() if symbol in references}
        addresses = set(assets.values()) | {a.lower() for a in (*USD_ANCHORS, *PRICE_RELAY_TOKENS)}
        decimals = {}
        for address in sorted(addresses):
            token = w3.eth.contract(address=Web3.to_checksum_address(address), abi=ERC20_META_ABI)
            decimals[address] = int(token.functions.decimals().call(block_identifier=block))
            if not 0 <= decimals[address] <= 36:
                return None
        factory = w3.eth.contract(address=Web3.to_checksum_address(RITUAL_V2_FACTORY), abi=FACTORY_GET_PAIR_ABI)
        relays = {a.lower() for a in (*USD_ANCHORS, *PRICE_RELAY_TOKENS)}
        graph = {}
        ordered = sorted(addresses)
        for index, first in enumerate(ordered):
            for second in ordered[index + 1:]:
                if first not in relays and second not in relays:
                    continue
                pair_address = factory.functions.getPair(
                    Web3.to_checksum_address(first), Web3.to_checksum_address(second),
                ).call(block_identifier=block)
                if not pair_address or int(pair_address, 16) == 0:
                    continue
                pair = w3.eth.contract(address=Web3.to_checksum_address(pair_address), abi=PAIR_RESERVES_ABI)
                r0, r1, _ = pair.functions.getReserves().call(block_identifier=block)
                # V2 token0 is the numerically smaller address; all paths are
                # quoted at one block, including both halves of a relay route.
                if r0 > 0 and r1 > 0:
                    graph.setdefault(first, {})[second] = (int(r0), int(r1))
                    graph.setdefault(second, {})[first] = (int(r1), int(r0))
        if time.time() - timestamp > MARKET_GAP_MAX_AGE_SECONDS:
            return None
        references = {symbol: reference for symbol, reference in references.items()
                      if time.time() - reference.timestamp <= MARKET_GAP_MAX_AGE_SECONDS}
        if not {"USDC", "USDT"}.issubset(references):
            return None
        assets = {symbol: address for symbol, address in assets.items() if symbol in references}
        gaps = _compare(assets, decimals, references, graph)
        return Scan(gaps, frozenset(assets) - {"USDC", "USDT"}, block, timestamp)
    except Exception as error:
        # Log only the error class: provider URLs may contain credentials.
        logger.warning("market gaps: scan unavailable (%s)", type(error).__name__)
        return None


def prepare(
    result: Scan | None, previous: str | None,
) -> tuple[str | None, InlineKeyboardMarkup | None, str | None]:
    """Build offer buttons and proposed state, committed after delivery only.

    Unknown assets preserve their last announced edge through an outage.
    Unchanged edges keep the last *published* baseline, so slow drift adds up.
    """
    if result is None:
        return None, None, None
    try:
        state = json.loads(previous or "{}")
        if not isinstance(state, dict):
            state = {}
    except (ValueError, TypeError):
        state = {}
    current = {gap.key for gap in result.gaps}
    state = {key: value for key, value in state.items()
             if key.partition(":")[0] not in result.observed or key in current}
    changed = []
    for gap in result.gaps:
        prior = _positive(state.get(gap.key))
        if prior is None or abs(gap.edge_pct - prior) * 100 >= MARKET_GAP_CHANGE_BPS:
            changed.append(gap)
    shown = changed[:6]
    for gap in shown:
        state[gap.key] = str(gap.edge_pct)
    next_state = json.dumps(state, sort_keys=True)
    if not shown:
        return None, None, next_state
    rows = []
    for side in ("buy", "sell"):
        for gap in shown:
            if gap.side != side:
                continue
            action = "Buy" if side == "buy" else "Sell"
            comparison = "cheaper" if side == "buy" else "higher"
            label = f"{action} {gap.symbol} in Cyberia · {int(gap.edge_pct)}% {comparison}"
            parts = urllib.parse.urlsplit(SWAP_URL)
            query = dict(urllib.parse.parse_qsl(parts.query))
            query.update({"chain": "49406", "in": gap.path[0], "out": gap.path[-1]})
            url = urllib.parse.urlunsplit(parts._replace(query=urllib.parse.urlencode(query)))
            rows.append([InlineKeyboardButton(label, url=url)])
    text = ("⚖️ <b>Cyberia · market offers</b>\n"
            "Compared with external markets. Before network, bridge and outside trading fees.")
    return text, InlineKeyboardMarkup(rows), next_state
