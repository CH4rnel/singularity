import asyncio
import json
import os
import re
import secrets
import sys
import time
import logging
import urllib.request
from datetime import datetime, timedelta, timezone
from pathlib import Path

from dotenv import load_dotenv

from telegram import BotCommand, Update
from telegram.ext import (
    Application,
    ChatMemberHandler,
    CommandHandler,
    ContextTypes,
    MessageHandler,
    filters,
)
from telegram.error import TelegramError
from telegram.request import HTTPXRequest

from sqlalchemy import create_engine, text
from web3 import Web3

load_dotenv(Path(__file__).parent / ".env")

# Required to run the Telegram bot itself, but NOT to refresh the analytics
# market snapshot (see `--snapshot-once`), so the check is deferred to
# run_dispatcher() instead of failing at import time.
TELEGRAM_BOT_TOKEN = os.environ.get("TELEGRAM_BOT_TOKEN")

CHAT_ID = os.environ.get("TELEGRAM_CHAT_ID")
HTTP_PROXY = os.environ.get("HTTP_PROXY")

DB_PATH = os.environ.get("DB_PATH", "/home/lain/random/singularity/backend/laravel/database/database.sqlite")

TOKEN_ADDRESS = os.environ.get(
    "TG_TOKEN_ADDRESS", "0x4A3B5919e60fd290172955753DdA9216E396170A"
).strip() or None
TOKEN_ABI = [
    {
        "inputs": [
            {"name": "to", "type": "address"},
            {"name": "amount", "type": "uint256"},
        ],
        "name": "mint",
        "outputs": [],
        "stateMutability": "nonpayable",
        "type": "function",
    },
    {
        "inputs": [],
        "name": "symbol",
        "outputs": [{"name": "", "type": "string"}],
        "stateMutability": "view",
        "type": "function",
    },
]

RPC_URL = os.environ.get("RPC_URL", "https://rpc.cyberia.church")
CHAIN_ID = int(os.environ.get("CHAIN_ID", "49406"))
EXPLORER_URL = os.environ.get("EXPLORER_URL", "https://explorer.cyberia.church").rstrip("/")

TELEGRAM_TOKEN_FACTORY = os.environ.get("TELEGRAM_TOKEN_FACTORY")
DEPLOYER_PK = os.environ.get("DEPLOYER_PK")

FACTORY_ABI = [
    {
        "inputs": [
            {"name": "name_", "type": "string"},
            {"name": "symbol_", "type": "string"},
            {"name": "tokenOwner", "type": "address"},
        ],
        "name": "createToken",
        "outputs": [{"name": "token", "type": "address"}],
        "stateMutability": "nonpayable",
        "type": "function",
    },
    {
        "anonymous": False,
        "inputs": [
            {"indexed": True, "name": "token", "type": "address"},
            {"indexed": True, "name": "owner", "type": "address"},
            {"indexed": False, "name": "name", "type": "string"},
            {"indexed": False, "name": "symbol", "type": "string"},
        ],
        "name": "TokenCreated",
        "type": "event",
    },
]

TOKEN_CREATED_TOPIC = Web3.keccak(text="TokenCreated(address,address,string,string)").hex()

MIN_REWARDS_INTERVAL_SECONDS = int(os.environ.get("MIN_REWARDS_INTERVAL_SECONDS", "60"))
MAX_REWARDS_INTERVAL_SECONDS = int(os.environ.get("MAX_REWARDS_INTERVAL_SECONDS", str(30 * 24 * 3600)))

# Public chat where successful bridge txs are announced.
BRIDGE_ANNOUNCE_CHAT = os.environ.get("BRIDGE_ANNOUNCE_CHAT", "@cyberia_network_chat")
BRIDGE_POLL_SECONDS = int(os.environ.get("BRIDGE_POLL_SECONDS", "30"))
SOLSCAN_URL = os.environ.get("SOLSCAN_URL", "https://solscan.io").rstrip("/")

# Ritual DEX (Quickswap V2 fork on Cyberia) swap announcer.
SWAP_ANNOUNCE_CHAT = os.environ.get("SWAP_ANNOUNCE_CHAT", BRIDGE_ANNOUNCE_CHAT)
SWAP_POLL_SECONDS = int(os.environ.get("SWAP_POLL_SECONDS", "30"))
RITUAL_V2_ROUTER = os.environ.get(
    "RITUAL_V2_ROUTER", "0x8bECfB12Ab113586D8deD3D343aEfFd8eD54FD62"
)
RITUAL_V2_FACTORY = os.environ.get(
    "RITUAL_V2_FACTORY", "0xB0aC30907c04b61F1482e62eA66eF4562a690917"
)

# Don't announce swap/liquidity events whose USD volume is below this. Set to 0
# to disable the filter. Default: $1 — drops dust noise from cheap launchpad
# memecoins paired against CYBER.sol.
MIN_ANNOUNCE_USD = float(os.environ.get("MIN_ANNOUNCE_USD", "1.0"))

# Per-event posts are reserved for events at/above this USD value. Everything
# below it (but above MIN_ANNOUNCE_USD's dust floor) is recorded in
# activity_events and only surfaces in the periodic digest. Set to 0 to post
# every event individually (the old behaviour).
BIG_ANNOUNCE_USD = float(os.environ.get("BIG_ANNOUNCE_USD", "10.0"))

# Periodic on-chain activity digest: swap volume, top tokens, liquidity flows,
# bridges, lending and the CYBER price — one summary message instead of a
# stream of per-event posts. Set DIGEST_INTERVAL_SECONDS=0 to disable.
DIGEST_ANNOUNCE_CHAT = os.environ.get("DIGEST_ANNOUNCE_CHAT", BRIDGE_ANNOUNCE_CHAT)
DIGEST_INTERVAL_SECONDS = int(os.environ.get("DIGEST_INTERVAL_SECONDS", str(6 * 3600)))
# Recorded events older than this are pruned (the digest never looks that far back).
DIGEST_RETENTION_DAYS = int(os.environ.get("DIGEST_RETENTION_DAYS", "30"))

# Tokens pegged to $1 — anchor points for the price walker. Comma-separated
# addresses. Default: USDC + USDT on Cyberia.
USD_ANCHORS = {
    a.strip().lower()
    for a in os.environ.get(
        "USD_ANCHORS",
        "0xdc25597B19799010047F17e9591EFE08EFd40077,"
        "0x94845aF24a3E431593A2b941b2b31836dE45185D",
    ).split(",")
    if a.strip()
}

# A pool is only trusted as a price source when its anchor/relay-side reserve
# is worth at least this many USD. Dust pools (e.g. a pair seeded with 1e-6
# USDC) have meaningless reserve ratios and would otherwise poison the price
# walker. When several pools qualify, the deepest one wins.
#
# Kept well below $1: Cyberia's WCYBER/stablecoin relay pools currently hold
# under a dollar of stables (~$0.8-0.95), and at a $1 floor they get rejected,
# which unprices WCYBER/CYBER.sol and collapses the whole relay cascade down to
# just the two anchors. $0.1 still rejects genuine dust (depth ~$0) while
# admitting the real pools (depth $0.4-$3).
PRICE_MIN_POOL_USD = float(os.environ.get("PRICE_MIN_POOL_USD", "0.1"))

# Relay tokens used to price assets that have no direct stablecoin pair.
# Default: WCYBER and CYBER.sol — together they reach the long tail of pairs.
PRICE_RELAY_TOKENS = [
    a.strip().lower()
    for a in os.environ.get(
        "PRICE_RELAY_TOKENS",
        "0x78272aAd03E4b9d7A9134e874BA6d419B534F6c9,"
        "0x7DcDa19Cf984ca708E5fA228AC148e7d82D508BA",
    ).split(",")
    if a.strip()
]
# Bound the per-tick block range so eth_getLogs stays well under provider limits
# even if the bot was stopped for a while.
SWAP_MAX_BLOCK_RANGE = int(os.environ.get("SWAP_MAX_BLOCK_RANGE", "2000"))

# Ritual DEX liquidity (V2 Mint/Burn) announcer. Same router filter as swaps:
# addLiquidity/removeLiquidity go through the router, so the pair's Mint/Burn
# `sender` topic equals the router address.
LIQUIDITY_ANNOUNCE_CHAT = os.environ.get("LIQUIDITY_ANNOUNCE_CHAT", BRIDGE_ANNOUNCE_CHAT)
LIQUIDITY_POLL_SECONDS = int(os.environ.get("LIQUIDITY_POLL_SECONDS", str(SWAP_POLL_SECONDS)))
LIQUIDITY_MAX_BLOCK_RANGE = int(os.environ.get("LIQUIDITY_MAX_BLOCK_RANGE", str(SWAP_MAX_BLOCK_RANGE)))

# Lending (Compound-style markets) announcer. Markets are enumerated from the
# comptroller's getAllMarkets(); supply/withdraw/borrow/repay are announced.
LENDING_ANNOUNCE_CHAT = os.environ.get("LENDING_ANNOUNCE_CHAT", BRIDGE_ANNOUNCE_CHAT)
LENDING_POLL_SECONDS = int(os.environ.get("LENDING_POLL_SECONDS", str(SWAP_POLL_SECONDS)))
LENDING_MAX_BLOCK_RANGE = int(os.environ.get("LENDING_MAX_BLOCK_RANGE", str(SWAP_MAX_BLOCK_RANGE)))
# Same value the DEX frontend reads as VITE_LENDING_COMPTROLLER. Without it the
# lending announcer stays dormant.
LENDING_COMPTROLLER = (os.environ.get("LENDING_COMPTROLLER", "") or "").strip()

# --- Whales chat gate (CYBER.sol holders) -------------------------------------
# Users prove ownership of a Solana wallet with a Phantom signature on the
# Laravel-served page (/tg/cyber-sol); the backend writes the verified balance
# to tg_sol_wallets and this bot invites/kicks based on the threshold. Balance
# re-checks here need no signature — ownership was already proven once.
SOLANA_RPC_URL = os.environ.get("SOLANA_RPC_URL", "https://api.mainnet-beta.solana.com")
CYBER_SOL_MINT = os.environ.get("CYBER_SOL_MINT", "E67WWiQY4s9SZbCyFVTh2CEjorEYbhuVJQUZb3Mbpump")
CYBER_SOL_DECIMALS = int(os.environ.get("CYBER_SOL_DECIMALS", "6"))
WHALE_MIN_CYBER_SOL = int(os.environ.get("WHALE_MIN_CYBER_SOL", "10000000"))
WHALE_MIN_RAW = WHALE_MIN_CYBER_SOL * (10 ** CYBER_SOL_DECIMALS)
WHALE_VERIFY_URL = os.environ.get("WHALE_VERIFY_URL", "https://cyberia.church/tg/cyber-sol").rstrip("/")
WHALE_LINK_TTL_MINUTES = int(os.environ.get("WHALE_LINK_TTL_MINUTES", "15"))
# How often the loop ticks (issuing pending invites) and how stale a row must be
# before its on-chain balance is re-read to enforce the threshold.
WHALE_POLL_SECONDS = int(os.environ.get("WHALE_POLL_SECONDS", "20"))
WHALE_RECHECK_SECONDS = int(os.environ.get("WHALE_RECHECK_SECONDS", "3600"))

_whale_chat_raw = (os.environ.get("WHALE_CHAT_ID", "") or "").strip()
try:
    WHALE_CHAT_ID = int(_whale_chat_raw) if _whale_chat_raw else None
except ValueError:
    WHALE_CHAT_ID = None

# --- Quick replies ("x", "ca", …) ---------------------------------------------
# Plain one-word messages people drop in chat ("ca?", "x") asking for the
# project's social link or token contract address. Answered both as /x, /ca
# commands and as bare-text triggers. All values are env-overridable.
PROJECT_X_URL = os.environ.get("PROJECT_X_URL", "https://x.com/cyberia_temple")
PROJECT_WEBSITE_URL = os.environ.get("PROJECT_WEBSITE_URL", "https://cyberia.church")
TELEGRAM_CHANNEL_URL = os.environ.get("TELEGRAM_CHANNEL_URL", "https://t.me/cyberia_network")
TELEGRAM_CHAT_URL = os.environ.get("TELEGRAM_CHAT_URL", "https://t.me/cyberia_network_chat")

# Contract addresses surfaced by "ca". CYBER.sol is the community pump.fun token
# (also gates the whales chat); its EVM counterpart is the bridged CYBER.sol on
# Cyberia (matches PRICE_RELAY_TOKENS). Blank values are simply omitted.
CYBER_CA_SOLANA = (os.environ.get("CYBER_CA_SOLANA", CYBER_SOL_MINT) or "").strip()
CYBER_CA_EVM = (os.environ.get(
    "CYBER_CA_EVM", "0x7DcDa19Cf984ca708E5fA228AC148e7d82D508BA"
) or "").strip()

LOG_FILE = Path(__file__).parent / "bot.log"

logging.basicConfig(
    format="%(asctime)s - %(name)s - %(levelname)s - %(message)s",
    level=logging.INFO,
    handlers=[
        logging.FileHandler(LOG_FILE),
        logging.StreamHandler(sys.stdout),
    ],
)

logging.getLogger("httpx").setLevel(logging.WARNING)
logging.getLogger("telegram").setLevel(logging.WARNING)


class SecurityFilter(logging.Filter):
    def filter(self, record):
        if TELEGRAM_BOT_TOKEN:
            record.msg = record.msg.replace(TELEGRAM_BOT_TOKEN, "***")
        return True


logger = logging.getLogger(__name__)
logger.addFilter(SecurityFilter())

logger.info("Starting bot...")

try:
    engine = create_engine(f"sqlite:///{DB_PATH}", connect_args={"check_same_thread": False})
    with engine.connect() as conn:
        conn.execute(text("SELECT 1"))
    logger.info(f"Database connected: {DB_PATH}")
except Exception as e:
    logger.error(f"Database connection failed: {e}")
    raise


def is_valid_eth_address(address: str) -> bool:
    return address.startswith("0x") and len(address) == 42


_INTERVAL_UNITS = {
    "s": 1,
    "sec": 1,
    "secs": 1,
    "m": 60,
    "min": 60,
    "mins": 60,
    "h": 3600,
    "hr": 3600,
    "hrs": 3600,
    "d": 86400,
    "day": 86400,
    "days": 86400,
    "w": 7 * 86400,
    "wk": 7 * 86400,
    "weeks": 7 * 86400,
}

_INTERVAL_RE = re.compile(r"^\s*(\d+)\s*([a-zA-Z]*)\s*$")


def parse_interval(value: str) -> int:
    """Parse '1h', '30m', '2d', '90', etc. into seconds. Raises ValueError."""
    match = _INTERVAL_RE.match(value or "")
    if not match:
        raise ValueError(f"invalid interval: {value!r}")
    num = int(match.group(1))
    unit = (match.group(2) or "s").lower()
    if unit not in _INTERVAL_UNITS:
        raise ValueError(f"unknown interval unit: {unit!r}")
    seconds = num * _INTERVAL_UNITS[unit]
    if seconds < MIN_REWARDS_INTERVAL_SECONDS:
        raise ValueError(f"interval too small (min {MIN_REWARDS_INTERVAL_SECONDS}s)")
    if seconds > MAX_REWARDS_INTERVAL_SECONDS:
        raise ValueError(f"interval too large (max {MAX_REWARDS_INTERVAL_SECONDS}s)")
    return seconds


def format_interval(seconds: int) -> str:
    for label, div in (("d", 86400), ("h", 3600), ("m", 60)):
        if seconds % div == 0 and seconds >= div:
            return f"{seconds // div}{label}"
    return f"{seconds}s"


_CYRILLIC_TRANSLIT = {
    "а": "a",
    "б": "b",
    "в": "v",
    "г": "g",
    "д": "d",
    "е": "e",
    "ё": "e",
    "ж": "zh",
    "з": "z",
    "и": "i",
    "й": "y",
    "к": "k",
    "л": "l",
    "м": "m",
    "н": "n",
    "о": "o",
    "п": "p",
    "р": "r",
    "с": "s",
    "т": "t",
    "у": "u",
    "ф": "f",
    "х": "h",
    "ц": "ts",
    "ч": "ch",
    "ш": "sh",
    "щ": "sch",
    "ъ": "",
    "ы": "y",
    "ь": "",
    "э": "e",
    "ю": "yu",
    "я": "ya",
}


def transliterate_name(value: str) -> str:
    return "".join(_CYRILLIC_TRANSLIT.get(char.lower(), char) for char in value)


def slugify_symbol(name: str, chat_id: int) -> str:
    """Derive a short ERC20 symbol from a human-readable name."""
    cleaned = re.sub(r"[^A-Za-z0-9_]+", "", transliterate_name(name)).upper()
    if not cleaned:
        return f"CHAT{abs(chat_id)}"
    return cleaned[:16]


def ensure_chat_token_schema():
    with engine.begin() as conn:
        conn.execute(text("""
            CREATE TABLE IF NOT EXISTS chat_tokens (
                chat_id           INTEGER PRIMARY KEY,
                name              TEXT NOT NULL,
                symbol            TEXT NOT NULL,
                token_address     TEXT,
                rewards_interval  INTEGER NOT NULL,
                reward_amount     TEXT NOT NULL DEFAULT '1000000000000000000',
                created_by        INTEGER NOT NULL,
                created_at        TEXT DEFAULT (datetime('now')),
                last_payout_at    TEXT
            )
        """))
        conn.execute(text("""
            CREATE TABLE IF NOT EXISTS chat_members (
                chat_id    INTEGER NOT NULL,
                user_id    INTEGER NOT NULL,
                first_seen TEXT DEFAULT (datetime('now')),
                last_seen  TEXT DEFAULT (datetime('now')),
                PRIMARY KEY (chat_id, user_id)
            )
        """))
        conn.execute(text("""
            CREATE TABLE IF NOT EXISTS tg_wallets (
                user_id    INTEGER PRIMARY KEY,
                address    TEXT NOT NULL,
                created_at TEXT DEFAULT (datetime('now'))
            )
        """))
        # Owed rewards for users without a wallet yet. distribute_chat_tokens.py
        # adds to `amount` (uint256 as text) on every payout tick where the
        # user is a chat member but has no entry in tg_wallets. /set_wallet
        # flushes these on-chain in one mint per chat token.
        conn.execute(text("""
            CREATE TABLE IF NOT EXISTS pending_rewards (
                chat_id    INTEGER NOT NULL,
                user_id    INTEGER NOT NULL,
                amount     TEXT NOT NULL DEFAULT '0',
                updated_at TEXT DEFAULT (datetime('now')),
                PRIMARY KEY (chat_id, user_id)
            )
        """))
        # Small key/value store for the bot's own state, separate from the
        # Laravel-owned tables. Used to track the last bridge_requests.id we
        # already announced in BRIDGE_ANNOUNCE_CHAT.
        conn.execute(text("""
            CREATE TABLE IF NOT EXISTS bot_kv (
                key   TEXT PRIMARY KEY,
                value TEXT
            )
        """))
        # One-time links minted by /whale and consumed by the Laravel verify
        # endpoint (shared SQLite file): maps an opaque token -> telegram user.
        conn.execute(text("""
            CREATE TABLE IF NOT EXISTS tg_link_tokens (
                token      TEXT PRIMARY KEY,
                tg_user_id INTEGER NOT NULL,
                expires_at TEXT NOT NULL,
                used       INTEGER NOT NULL DEFAULT 0,
                created_at TEXT DEFAULT (datetime('now'))
            )
        """))
        # Verified Solana wallets + CYBER.sol balance. Laravel writes on verify;
        # this bot reads to invite/kick and re-reads balances on a schedule.
        conn.execute(text("""
            CREATE TABLE IF NOT EXISTS tg_sol_wallets (
                tg_user_id      INTEGER PRIMARY KEY,
                solana_address  TEXT NOT NULL,
                balance_raw     TEXT NOT NULL DEFAULT '0',
                is_whale        INTEGER NOT NULL DEFAULT 0,
                invited         INTEGER NOT NULL DEFAULT 0,
                verified_at     TEXT,
                last_checked_at TEXT
            )
        """))
        # Every on-chain event the announcers observe (swaps, liquidity,
        # bridges, lending), whether or not it was posted individually. The
        # periodic digest and /stats aggregate from here. usd is NULL when the
        # price walker found no route. meta holds kind-specific extras
        # (bridge direction).
        conn.execute(text("""
            CREATE TABLE IF NOT EXISTS activity_events (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                kind       TEXT NOT NULL,
                usd        REAL,
                sym_in     TEXT,
                amt_in     REAL,
                sym_out    TEXT,
                amt_out    REAL,
                user_addr  TEXT,
                tx_hash    TEXT,
                block      INTEGER,
                meta       TEXT,
                created_at TEXT DEFAULT (datetime('now'))
            )
        """))
        conn.execute(text("""
            CREATE INDEX IF NOT EXISTS idx_activity_events_kind_time
            ON activity_events (kind, created_at)
        """))
        # Market snapshot rewritten by market_snapshot_loop every few minutes:
        # token USD prices and DEX pool reserves/TVL. Read by the Laravel
        # /analytics page through the shared SQLite file.
        conn.execute(text("""
            CREATE TABLE IF NOT EXISTS token_prices (
                address    TEXT PRIMARY KEY,
                symbol     TEXT,
                price_usd  REAL,
                updated_at TEXT
            )
        """))
        conn.execute(text("""
            CREATE TABLE IF NOT EXISTS dex_pools (
                pair_address TEXT PRIMARY KEY,
                token0       TEXT,
                token1       TEXT,
                symbol0      TEXT,
                symbol1      TEXT,
                reserve0     REAL,
                reserve1     REAL,
                tvl_usd      REAL,
                updated_at   TEXT
            )
        """))


ensure_chat_token_schema()


def _kv_get(key: str, default: str | None = None) -> str | None:
    with engine.connect() as conn:
        row = conn.execute(
            text("SELECT value FROM bot_kv WHERE key = :k"),
            {"k": key},
        ).fetchone()
    return row[0] if row else default


def _kv_set(key: str, value: str) -> None:
    with engine.begin() as conn:
        conn.execute(
            text("""
                INSERT INTO bot_kv (key, value) VALUES (:k, :v)
                ON CONFLICT(key) DO UPDATE SET value = excluded.value
            """),
            {"k": key, "v": value},
        )


def _record_activity(
    kind: str,
    usd: float | None = None,
    sym_in: str | None = None,
    amt_in: float | None = None,
    sym_out: str | None = None,
    amt_out: float | None = None,
    user_addr: str | None = None,
    tx_hash: str | None = None,
    block: int | None = None,
    meta: str | None = None,
) -> None:
    """Persist one observed on-chain event for the digest. Never raises —
    losing a stats row must not break the announcer that called us."""
    try:
        with engine.begin() as conn:
            conn.execute(
                text("""
                    INSERT INTO activity_events
                        (kind, usd, sym_in, amt_in, sym_out, amt_out,
                         user_addr, tx_hash, block, meta)
                    VALUES
                        (:kind, :usd, :sym_in, :amt_in, :sym_out, :amt_out,
                         :user_addr, :tx_hash, :block, :meta)
                """),
                {
                    "kind": kind, "usd": usd,
                    "sym_in": sym_in, "amt_in": amt_in,
                    "sym_out": sym_out, "amt_out": amt_out,
                    "user_addr": user_addr, "tx_hash": tx_hash,
                    "block": block, "meta": meta,
                },
            )
    except Exception as e:
        logger.error(f"record_activity: insert failed kind={kind} tx={tx_hash}: {e}")


def _fmt_usd(value: float) -> str:
    if value >= 1000:
        return f"${value:,.0f}"
    if value >= 1:
        return f"${value:,.2f}"
    return f"${value:.4f}"


async def is_chat_admin(update: Update, context: ContextTypes.DEFAULT_TYPE) -> bool:
    chat = update.effective_chat
    user = update.effective_user
    if chat is None or user is None:
        return False
    # private chats: owner by definition
    if chat.type == "private":
        return True
    try:
        member = await context.bot.get_chat_member(chat.id, user.id)
        return member.status in ("creator", "administrator")
    except TelegramError as e:
        logger.warning(f"get_chat_member failed for chat {chat.id} user {user.id}: {e}")
        return False


async def is_chat_owner(update: Update, context: ContextTypes.DEFAULT_TYPE) -> bool:
    chat = update.effective_chat
    user = update.effective_user
    if chat is None or user is None:
        return False
    try:
        member = await context.bot.get_chat_member(chat.id, user.id)
        return member.status == "creator"
    except TelegramError as e:
        logger.warning(f"get_chat_member failed for chat {chat.id} user {user.id}: {e}")
        return False


def get_chat_token(chat_id: int):
    with engine.connect() as conn:
        row = conn.execute(
            text("SELECT chat_id, name, symbol, token_address, rewards_interval, reward_amount "
                 "FROM chat_tokens WHERE chat_id = :c"),
            {"c": chat_id},
        ).fetchone()
    return row


async def start_command(update: Update, context: ContextTypes.DEFAULT_TYPE):
    user = update.effective_user

    has_wallet = False
    if user is not None:
        try:
            with engine.connect() as conn:
                row = conn.execute(
                    text("SELECT 1 FROM tg_wallets WHERE user_id = :u"),
                    {"u": user.id},
                ).fetchone()
            has_wallet = row is not None
        except Exception as e:
            logger.debug(f"start: wallet lookup failed: {e}")

    lines = [
        "Hi! I send you 1 TG token every hour on Cyberia (49406).",
        "",
        "Commands:",
        "/help - show available commands",
        "/wallet - show your linked wallet and explorer link",
        "/balance - show TG, all chat tokens, and pending rewards",
        "/token - show this chat's reward token (group only)",
        "/unset_wallet - unlink your wallet (pending rewards are kept)",
        "/cancel - cancel an interactive prompt",
        "/github <username> <address> - link GitHub for GITHUB token airdrop",
        "/whale - verify CYBER.sol holdings to join the whales chat",
        "/create_token [name] [interval] - (admins) create a chat reward token",
        "/set_rewards_interval <interval> - (admins) change payout interval",
        "/reward_now - (admins) trigger an extra payout right now",
        "/website - project website",
    ]

    # Only nudge wallet-less users to register, and keep it at the very end.
    if not has_wallet:
        lines += [
            "",
            "You haven't linked a wallet yet — link one to receive rewards:",
            "/set_wallet [address] - link your wallet (asks for address if omitted)",
            "Example: /set_wallet 0x1234567890abcdef1234567890abcdef12345678",
        ]

    await update.message.reply_text("\n".join(lines))


async def help_command(update: Update, context: ContextTypes.DEFAULT_TYPE):
    await update.message.reply_text(
        "Commands:\n"
        "/start - start receiving TG\n"
        "/set_wallet [address] - link your wallet (asks for address if omitted)\n"
        "/unset_wallet - unlink your wallet (pending rewards are kept)\n"
        "/wallet - show your linked wallet and explorer link\n"
        "/balance - show TG, all chat tokens, and pending rewards\n"
        "/token - show this chat's reward token (group only)\n"
        "/cancel - cancel an interactive prompt\n"
        "/create_token [name] [interval] - (admins) create a chat reward token\n"
        "   (prompts for missing arguments; use /cancel to abort)\n"
        "   e.g. /create_token MyChatToken 1h\n"
        "/set_rewards_interval <interval> - (admins) change payout interval\n"
        "/reward_now - (admins) trigger an extra payout right now\n"
        "/github <username> <address> - link GitHub for GITHUB token airdrop\n"
        "/whale - verify CYBER.sol holdings to join the whales chat\n"
        "/x - X (Twitter) and Telegram links (also replies to \"x\")\n"
        "/ca - CYBER contract address (also replies to \"ca\")\n"
        "/stats [window] - on-chain activity digest (default 24h, e.g. /stats 6h)\n"
        "/website - project website\n\n"
        "You can chat in groups without a wallet -- rewards will be saved as "
        "pending and minted in one go when you /set_wallet."
    )


async def website_command(update: Update, context: ContextTypes.DEFAULT_TYPE):
    await update.message.reply_text(PROJECT_WEBSITE_URL)


def _build_x_reply() -> str:
    """X (Twitter) link, plus the Telegram channel/chat for good measure."""
    lines = [f"𝕏 (Twitter): {PROJECT_X_URL}"]
    if TELEGRAM_CHANNEL_URL:
        lines.append(f"Channel: {TELEGRAM_CHANNEL_URL}")
    if TELEGRAM_CHAT_URL:
        lines.append(f"Chat: {TELEGRAM_CHAT_URL}")
    return "\n".join(lines)


def _build_ca_reply() -> str:
    """CYBER contract address(es). Solana mint first (the pump.fun token people
    trade), then the bridged EVM token with an explorer link."""
    if not CYBER_CA_SOLANA and not CYBER_CA_EVM:
        return "Contract address is not configured on this bot yet."
    lines = ["📜 CYBER contract address:"]
    if CYBER_CA_SOLANA:
        lines.append(f"Solana (CYBER.sol): {CYBER_CA_SOLANA}")
    if CYBER_CA_EVM:
        lines.append(f"Cyberia EVM: {CYBER_CA_EVM}")
        lines.append(f"{EXPLORER_URL}/address/{CYBER_CA_EVM}")
    return "\n".join(lines)


async def x_command(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """/x — reply with the project's X (Twitter) and Telegram links."""
    await update.message.reply_text(_build_x_reply())


async def ca_command(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """/ca — reply with the CYBER token contract address(es)."""
    await update.message.reply_text(_build_ca_reply(), disable_web_page_preview=True)


# Bare-text triggers -> reply builder. Synonyms map to the same answer so that
# "ca", "contract" or "address" all surface the contract address.
_QUICK_REPLIES = {
    "x": _build_x_reply,
    "twitter": _build_x_reply,
    "ca": _build_ca_reply,
    "contract": _build_ca_reply,
    "address": _build_ca_reply,
}

# Matches a message that is *only* a trigger word, optionally wrapped in
# whitespace/punctuation (e.g. "ca?", " X ", "Contract."). Keeps the handler
# from firing on ordinary chat that merely contains the word.
_QUICK_REPLY_RE = re.compile(
    r"^[\s]*(" + "|".join(_QUICK_REPLIES) + r")[\s?!.,]*$",
    re.IGNORECASE,
)


def _normalize_trigger(text_value: str) -> str:
    return text_value.strip().strip("?!.,").strip().lower()


async def quick_reply_handler(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Answer bare 'x'/'ca'-style messages with links and contract addresses.

    Registered in its own handler group so it runs independently of the
    wallet/token follow-up handlers, and gated by `_QUICK_REPLY_RE` so casual
    chat is never hijacked.
    """
    msg = update.effective_message
    if msg is None or not msg.text:
        return
    builder = _QUICK_REPLIES.get(_normalize_trigger(msg.text))
    if builder is None:
        return
    await update.message.reply_text(builder(), disable_web_page_preview=True)


async def github_command(update: Update, context: ContextTypes.DEFAULT_TYPE):
    args = context.args
    if not args or len(args) < 2:
        await update.message.reply_text(
            "Usage: /github <github_username> <wallet_address>\n"
            "Example: /github octocat 0x1234...abcd\n\n"
            "Star https://github.com/cyberia-temple/singularity and "
            "follow https://github.com/cyberia-temple to earn GITHUB tokens!"
        )
        return

    github_username = args[0].strip().lstrip("@").lower()
    address = args[1].strip()

    if not is_valid_eth_address(address):
        await update.message.reply_text("Invalid wallet address. Expected 0x...")
        return

    try:
        with engine.connect() as conn:
            conn.execute(
                text("""
                    INSERT INTO github_wallets (github_username, wallet_address)
                    VALUES (:user, :wallet)
                    ON CONFLICT(github_username) DO UPDATE SET wallet_address = :wallet
                """),
                {"user": github_username, "wallet": address},
            )
            conn.commit()

        await update.message.reply_text(
            f"Linked GitHub @{github_username} -> {address[:6]}...{address[-4:]}\n\n"
            f"Now star the repo and follow the org to receive GITHUB tokens!"
        )
    except Exception as e:
        logger.error(f"Error in github command: {e}")
        await update.message.reply_text("Error saving. Try again.")


async def _process_create_token(
    update: Update, context: ContextTypes.DEFAULT_TYPE,
    name: str, interval_raw: str,
) -> None:
    """Shared implementation for /create_token and the follow-up prompts.

    Validates name & interval, persists, deploys on-chain, and stores the
    resulting token address.
    """
    chat = update.effective_chat
    user = update.effective_user
    if chat is None or user is None:
        return

    if not name or len(name) > 48:
        await update.message.reply_text("Token name must be 1..48 characters.")
        return

    try:
        rewards_interval = parse_interval(interval_raw)
    except ValueError as e:
        await update.message.reply_text(
            f"Bad interval: {e}\nExamples: 30s, 15m, 1h, 2d, 1w"
        )
        return

    if not TELEGRAM_TOKEN_FACTORY or not DEPLOYER_PK:
        await update.message.reply_text(
            "Token creation is not configured on this bot "
            "(TELEGRAM_TOKEN_FACTORY / DEPLOYER_PK missing). Please contact the operator."
        )
        return

    symbol = slugify_symbol(name, chat.id)

    with engine.begin() as conn:
        conn.execute(
            text("""
                INSERT INTO chat_tokens
                    (chat_id, name, symbol, rewards_interval, created_by)
                VALUES
                    (:chat_id, :name, :symbol, :interval, :user_id)
                ON CONFLICT(chat_id) DO UPDATE SET
                    name = excluded.name,
                    symbol = excluded.symbol,
                    token_address = NULL,
                    rewards_interval = excluded.rewards_interval,
                    created_by = excluded.created_by,
                    created_at = datetime('now'),
                    last_payout_at = NULL
            """),
            {
                "chat_id": chat.id,
                "name": name,
                "symbol": symbol,
                "interval": rewards_interval,
                "user_id": user.id,
            },
        )

    status_msg = await update.message.reply_text(
        f"Deploying token {name} ({symbol})... this may take a few seconds."
    )

    token_address = None
    try:
        from web3 import Web3

        w3 = Web3(Web3.HTTPProvider(RPC_URL))
        acct = w3.eth.account.from_key(DEPLOYER_PK)
        factory = w3.eth.contract(
            address=Web3.to_checksum_address(TELEGRAM_TOKEN_FACTORY),
            abi=FACTORY_ABI,
        )

        nonce = w3.eth.get_transaction_count(acct.address, "pending")
        try:
            estimated = factory.functions.createToken(
                name, symbol, acct.address
            ).estimate_gas({"from": acct.address})
        except Exception as est_err:
            logger.warning(f"estimate_gas failed, falling back to 5_000_000: {est_err}")
            estimated = 5_000_000
        gas_limit = int(estimated * 1.25) + 50_000
        tx = factory.functions.createToken(
            name, symbol, acct.address
        ).build_transaction({
            "from": acct.address,
            "nonce": nonce,
            "gas": gas_limit,
            "gasPrice": w3.eth.gas_price,
            "chainId": CHAIN_ID,
        })
        signed = acct.sign_transaction(tx)
        tx_hash = w3.eth.send_raw_transaction(signed.raw_transaction)
        receipt = w3.eth.wait_for_transaction_receipt(tx_hash, timeout=180)

        if receipt.status != 1:
            raise RuntimeError(f"tx reverted: {tx_hash.hex()}")

        for log in receipt.logs:
            topics = log.get("topics") or []
            if not topics:
                continue
            if log.get("address", "").lower() != factory.address.lower():
                continue
            if topics[0].hex().lower() != TOKEN_CREATED_TOPIC.lower():
                continue
            event = factory.events.TokenCreated().process_log(log)
            token_address = event["args"]["token"]
            break

        if not token_address:
            raise RuntimeError("TokenCreated event did not include token address")
        token_address = Web3.to_checksum_address(token_address)
    except Exception as e:
        logger.error(f"On-chain createToken failed for chat {chat.id}: {e}")
        with engine.begin() as conn:
            conn.execute(
                text("DELETE FROM chat_tokens WHERE chat_id = :c AND token_address IS NULL"),
                {"c": chat.id},
            )
        await status_msg.edit_text(f"On-chain deployment failed: {e}")
        return

    with engine.begin() as conn:
        conn.execute(
            text("UPDATE chat_tokens SET token_address = :addr WHERE chat_id = :c"),
            {"addr": token_address, "c": chat.id},
        )

    await status_msg.edit_text(
        "New token created.\n"
        f"Name: {name}\n"
        f"Symbol: {symbol}\n"
        f"Address: {token_address}\n"
        f"Rewards interval: {format_interval(rewards_interval)}"
    )


async def create_token_command(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """
    /create_token <name> <rewards_interval>
    Creates a per-chat ERC20Votes reward token via the on-chain factory and
    wires it to this chat. Only the chat owner can run it; must be used from the
    target group/supergroup.

    If arguments are omitted the bot prompts for them interactively.
    """
    chat = update.effective_chat
    user = update.effective_user
    if chat is None or user is None:
        return

    if chat.type not in ("group", "supergroup"):
        await update.message.reply_text(
            "This command must be used inside a group chat."
        )
        return

    if not await is_chat_owner(update, context):
        await update.message.reply_text("Only the chat owner can create a token.")
        logger.warning(
            "Unauthorized create_token attempt by non-owner user_id=%s username=%s chat_id=%s",
            user.id,
            user.username,
            chat.id,
        )
        return

    args = context.args or []

    if not args:
        context.user_data["awaiting_token_name"] = True
        context.user_data["awaiting_token_chat_id"] = chat.id
        bot_msg = await update.message.reply_text(
            "Reply to this message with the token name (1-48 characters), or /cancel to abort."
        )
        context.user_data["awaiting_token_bot_msg_id"] = bot_msg.message_id
        return

    if len(args) == 1:
        name = args[0].strip()
        if not name or len(name) > 48:
            await update.message.reply_text("Token name must be 1..48 characters.")
            return
        context.user_data["token_name"] = name
        context.user_data["awaiting_token_interval"] = True
        context.user_data["awaiting_token_chat_id"] = chat.id
        bot_msg = await update.message.reply_text(
            "Reply to this message with the rewards interval (e.g. 30s, 15m, 1h, 2d, 1w), or /cancel to abort."
        )
        context.user_data["awaiting_token_bot_msg_id"] = bot_msg.message_id
        return

    # 2+ args: last token is always the interval
    name = " ".join(args[:-1]).strip()
    interval_raw = args[-1]
    await _process_create_token(update, context, name, interval_raw)


async def set_rewards_interval_command(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """/set_rewards_interval <interval> — admin-only, changes how often rewards are paid."""
    chat = update.effective_chat
    if chat is None or chat.type not in ("group", "supergroup"):
        await update.message.reply_text("Use this command in the group chat that owns the token.")
        return
    if not await is_chat_admin(update, context):
        await update.message.reply_text("Only chat admins can change the rewards interval.")
        return

    args = context.args or []
    if not args:
        await update.message.reply_text(
            "Usage: /set_rewards_interval <interval>\nExamples: 30s, 15m, 1h, 2d, 1w"
        )
        return

    try:
        rewards_interval = parse_interval(args[0])
    except ValueError as e:
        await update.message.reply_text(f"Bad interval: {e}")
        return

    chat_token = get_chat_token(chat.id)
    if chat_token is None:
        await update.message.reply_text("This chat has no token yet. Use /create_token first.")
        return

    try:
        with engine.begin() as conn:
            conn.execute(
                text("UPDATE chat_tokens SET rewards_interval = :i WHERE chat_id = :c"),
                {"i": rewards_interval, "c": chat.id},
            )
    except Exception as e:
        logger.error(f"set_rewards_interval db error: {e}")
        await update.message.reply_text("Internal error. Try again.")
        return

    await update.message.reply_text(
        f"Rewards interval updated: {format_interval(rewards_interval)}."
    )


def _get_chat_payout_recipients(chat_id: int):
    """Return [(user_id, address), ...] for users that have BOTH been seen in
    this chat (`chat_members`) AND linked a wallet globally (`tg_wallets`).

    `chat_members` is maintained by the bot via message tracking and
    left/kicked event handlers, so it reflects current membership for users
    the bot has ever seen. `tg_wallets` is global, so a user only needs to
    /set_wallet once anywhere to receive rewards from every chat they
    participate in.
    """
    with engine.connect() as conn:
        return conn.execute(
            text("""
                SELECT cm.user_id, w.address
                FROM chat_members cm
                JOIN tg_wallets w ON w.user_id = cm.user_id
                WHERE cm.chat_id = :c
            """),
            {"c": chat_id},
        ).fetchall()


def _record_chat_member(chat_id: int, user_id: int):
    with engine.begin() as conn:
        conn.execute(
            text("""
                INSERT INTO chat_members (chat_id, user_id, first_seen, last_seen)
                VALUES (:c, :u, datetime('now'), datetime('now'))
                ON CONFLICT(chat_id, user_id) DO UPDATE SET last_seen = datetime('now')
            """),
            {"c": chat_id, "u": user_id},
        )


def _forget_chat_member(chat_id: int, user_id: int):
    with engine.begin() as conn:
        conn.execute(
            text("DELETE FROM chat_members WHERE chat_id = :c AND user_id = :u"),
            {"c": chat_id, "u": user_id},
        )


async def reward_now_command(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """/reward_now — admin-only, trigger an immediate payout without touching the timer."""
    chat = update.effective_chat
    user = update.effective_user
    if chat is None or chat.type not in ("group", "supergroup"):
        await update.message.reply_text("Use this command in the group chat that owns the token.")
        return
    if not await is_chat_admin(update, context):
        await update.message.reply_text("Only chat admins can trigger a payout.")
        return

    chat_token = get_chat_token(chat.id)
    if chat_token is None:
        await update.message.reply_text("This chat has no token yet. Use /create_token first.")
        return

    _chat_id_col, name, symbol, token_address, _interval, reward_amount = chat_token
    if not token_address:
        await update.message.reply_text("Token deployment is still pending. Try again later.")
        return

    if not DEPLOYER_PK:
        await update.message.reply_text(
            "Payouts are not configured on this bot (DEPLOYER_PK missing)."
        )
        return

    if user is not None and not user.is_bot:
        try:
            _record_chat_member(chat.id, user.id)
        except Exception as e:
            logger.debug(f"reward_now member tracking failed: {e}")

    recipients = _get_chat_payout_recipients(chat.id)
    if not recipients:
        await update.message.reply_text(
            "No eligible recipients: no current member of this chat has registered "
            "a wallet via /set_wallet yet."
        )
        return

    amount_human = int(reward_amount) / 10**18
    status_msg = await update.message.reply_text(
        f"Minting {amount_human} {symbol} to {len(recipients)} wallet(s)..."
    )

    try:
        from web3 import Web3

        w3 = Web3(Web3.HTTPProvider(RPC_URL))
        acct = w3.eth.account.from_key(DEPLOYER_PK)
        token_abi = [{
            "inputs": [
                {"name": "to", "type": "address"},
                {"name": "amount", "type": "uint256"},
            ],
            "name": "mint",
            "outputs": [],
            "stateMutability": "nonpayable",
            "type": "function",
        }]
        contract = w3.eth.contract(
            address=Web3.to_checksum_address(token_address), abi=token_abi
        )
        amount = int(reward_amount)
        nonce = w3.eth.get_transaction_count(acct.address, "pending")

        succeeded = 0
        failed = 0
        for user_id, address in recipients:
            try:
                to = Web3.to_checksum_address(address)
                tx = contract.functions.mint(to, amount).build_transaction({
                    "from": acct.address,
                    "nonce": nonce,
                    "gas": 150_000,
                    "gasPrice": w3.eth.gas_price,
                    "chainId": CHAIN_ID,
                })
                signed = acct.sign_transaction(tx)
                tx_hash = w3.eth.send_raw_transaction(signed.raw_transaction)
                w3.eth.wait_for_transaction_receipt(tx_hash, timeout=120)
                logger.info(
                    f"reward_now chat={chat.id} user={user_id} -> {address} tx={tx_hash.hex()}"
                )
                nonce += 1
                succeeded += 1
            except Exception as e:
                logger.error(f"reward_now mint failed for {address}: {e}")
                nonce += 1
                failed += 1
    except Exception as e:
        logger.error(f"reward_now fatal: {e}")
        await status_msg.edit_text(f"Payout failed: {e}")
        return

    # Intentionally do NOT update last_payout_at: this is an extra payout.
    await status_msg.edit_text(
        f"Payout done: {succeeded} ok, {failed} failed. "
        "Regular schedule is unchanged."
    )


CHAT_TOKEN_MINT_ABI = [{
    "inputs": [
        {"name": "to", "type": "address"},
        {"name": "amount", "type": "uint256"},
    ],
    "name": "mint",
    "outputs": [],
    "stateMutability": "nonpayable",
    "type": "function",
}]


def _claim_pending_rewards(user_id: int, address: str):
    """Mint everything in pending_rewards for this user, one tx per chat token.

    Returns (claimed, failed, total_amount_by_symbol_dict).
    Rows are deleted only on successful mint to keep retries safe.
    """
    if not DEPLOYER_PK:
        logger.warning("claim_pending: DEPLOYER_PK not set, skipping")
        return 0, 0, {}

    with engine.connect() as conn:
        rows = conn.execute(
            text("""
                SELECT p.chat_id, p.amount, t.symbol, t.token_address
                FROM pending_rewards p
                JOIN chat_tokens t ON t.chat_id = p.chat_id
                WHERE p.user_id = :u
                  AND CAST(p.amount AS INTEGER) > 0
                  AND t.token_address IS NOT NULL
            """),
            {"u": user_id},
        ).fetchall()

    if not rows:
        return 0, 0, {}

    w3 = Web3(Web3.HTTPProvider(RPC_URL))
    acct = w3.eth.account.from_key(DEPLOYER_PK)
    to = Web3.to_checksum_address(address)
    nonce = w3.eth.get_transaction_count(acct.address, "pending")

    claimed = 0
    failed = 0
    totals: dict = {}

    for chat_id, amount_str, symbol, token_address in rows:
        try:
            amount = int(amount_str)
            if amount <= 0:
                continue
            contract = w3.eth.contract(
                address=Web3.to_checksum_address(token_address),
                abi=CHAT_TOKEN_MINT_ABI,
            )
            tx = contract.functions.mint(to, amount).build_transaction({
                "from": acct.address,
                "nonce": nonce,
                "gas": 200_000,
                "gasPrice": w3.eth.gas_price,
                "chainId": CHAIN_ID,
            })
            signed = acct.sign_transaction(tx)
            tx_hash = w3.eth.send_raw_transaction(signed.raw_transaction)
            receipt = w3.eth.wait_for_transaction_receipt(tx_hash, timeout=180)
            nonce += 1
            if receipt.status != 1:
                failed += 1
                logger.error(
                    "claim_pending: tx reverted user=%s chat=%s symbol=%s tx=%s",
                    user_id, chat_id, symbol, tx_hash.hex(),
                )
                continue

            with engine.begin() as conn:
                conn.execute(
                    text("DELETE FROM pending_rewards WHERE chat_id = :c AND user_id = :u"),
                    {"c": chat_id, "u": user_id},
                )
            claimed += 1
            totals[symbol] = totals.get(symbol, 0) + amount
            logger.info(
                "claim_pending: minted %s %s to %s user=%s chat=%s tx=%s",
                amount, symbol, address, user_id, chat_id, tx_hash.hex(),
            )
        except Exception as e:
            failed += 1
            logger.error(
                "claim_pending: mint failed user=%s chat=%s symbol=%s: %s",
                user_id, chat_id, symbol, e,
            )
            # Bump nonce defensively in case the tx was actually broadcast.
            nonce += 1

    return claimed, failed, totals


async def _process_set_wallet(update: Update, context: ContextTypes.DEFAULT_TYPE, address: str):
    """Shared implementation for /set_wallet <addr> and the follow-up message
    captured after a bare /set_wallet. Validates, persists, claims pending."""
    user_id = update.effective_user.id

    if not is_valid_eth_address(address):
        # Re-arm the prompt so the user can simply retry without re-invoking
        # /set_wallet. They can /cancel to bail out.
        context.user_data["awaiting_wallet"] = True
        await update.message.reply_text(
            "Invalid address format. Expected 0x followed by 40 hex characters.\n"
            "Send a valid address, or /cancel to abort."
        )
        return

    try:
        with engine.begin() as conn:
            conn.execute(
                text("""
                    INSERT INTO tg_wallets (user_id, address)
                    VALUES (:user_id, :address)
                    ON CONFLICT(user_id) DO UPDATE SET address = :address
                """),
                {"user_id": user_id, "address": address},
            )
    except Exception as e:
        logger.error(f"Error in set_wallet: {e}")
        await update.message.reply_text("Error saving address. Try again.")
        return

    # Check whether anything is owed before we promise a mint.
    with engine.connect() as conn:
        pending_count = conn.execute(
            text("""
                SELECT COUNT(*)
                FROM pending_rewards p
                JOIN chat_tokens t ON t.chat_id = p.chat_id
                WHERE p.user_id = :u
                  AND CAST(p.amount AS INTEGER) > 0
                  AND t.token_address IS NOT NULL
            """),
            {"u": user_id},
        ).scalar() or 0

    if pending_count == 0:
        await update.message.reply_text(
            f"Wallet saved: {address}\n"
            "You will receive rewards from every chat token in groups you participate in, "
            "and 1 TG every hour from the global airdrop."
        )
        return

    status_msg = await update.message.reply_text(
        f"Wallet saved: {address}\n"
        f"Claiming pending rewards from {pending_count} chat(s)..."
    )
    try:
        claimed, failed, totals = _claim_pending_rewards(user_id, address)
    except Exception as e:
        logger.error(f"set_wallet claim failed: {e}")
        await status_msg.edit_text(
            f"Wallet saved: {address}\n"
            "Could not claim pending rewards automatically. They are safe in the database "
            "and will be retried later."
        )
        return

    if not totals:
        await status_msg.edit_text(
            f"Wallet saved: {address}\n"
            f"Claim attempted but nothing succeeded ({failed} failed). Will retry later."
        )
        return

    lines = [f"Wallet saved: {address}", "Claimed pending rewards:"]
    for symbol, total in totals.items():
        human = total / 10**18
        lines.append(f"  {human:g} {symbol}")
    if failed:
        lines.append(f"({failed} chat(s) failed and will retry later)")
    await status_msg.edit_text("\n".join(lines))


async def set_wallet_command(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """`/set_wallet <address>` -- bind a wallet and claim pending rewards.

    If invoked without an argument, the bot asks the user to send the address
    in the next message. The follow-up handler (`pending_input_handler`)
    picks it up using `context.user_data["awaiting_wallet"]`.
    """
    args = context.args or []
    chat = update.effective_chat
    if not args:
        # Interactive follow-up only makes sense in DMs -- in groups the bot
        # would otherwise hijack the next casual message the user sends.
        if chat is not None and chat.type == "private":
            context.user_data["awaiting_wallet"] = True
            await update.message.reply_text(
                "Send your wallet address now (a single message starting with 0x), "
                "or /cancel to abort."
            )
        else:
            await update.message.reply_text(
                "Usage: /set_wallet <address>\nExample: /set_wallet 0x1234..."
            )
        return
    context.user_data.pop("awaiting_wallet", None)
    await _process_set_wallet(update, context, args[0].strip())


async def cancel_command(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Cancel any pending interactive flow (/set_wallet or /create_token)."""
    cancelled = bool(context.user_data.pop("awaiting_wallet", None))
    for key in ("awaiting_token_name", "awaiting_token_interval", "token_name", "awaiting_token_chat_id", "awaiting_token_bot_msg_id"):
        if context.user_data.pop(key, None) is not None:
            cancelled = True
    await update.message.reply_text("Cancelled." if cancelled else "Nothing to cancel.")


async def pending_input_handler(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Catch the next text message from a user who issued bare /set_wallet.

    Only fires when `awaiting_wallet` is set in the user's `user_data` and the
    message text looks like a single address-shaped token. Anything else is
    ignored so we don't accidentally hijack normal chat messages.
    """
    if not context.user_data.get("awaiting_wallet"):
        return
    msg = update.effective_message
    if msg is None or not msg.text:
        return
    text_value = msg.text.strip()
    # Bare addresses only -- skip if user typed a command in the meantime.
    if text_value.startswith("/"):
        return
    # If the user sent multiple tokens, take the first.
    candidate = text_value.split()[0]
    context.user_data.pop("awaiting_wallet", None)
    await _process_set_wallet(update, context, candidate)


async def pending_create_token_handler(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Catch the next text message from a user who issued bare /create_token.

    Fires when `awaiting_token_name` or `awaiting_token_interval` is set in
    the user's `user_data`.  Only processes messages that reply to the bot's
    last prompt, so the handler works in both privacy mode and when the bot is
    an admin, without hijacking casual group chat.
    """
    awaiting_name = context.user_data.get("awaiting_token_name")
    awaiting_interval = context.user_data.get("awaiting_token_interval")
    if not awaiting_name and not awaiting_interval:
        return

    chat = update.effective_chat
    if chat is None or chat.id != context.user_data.get("awaiting_token_chat_id"):
        return

    msg = update.effective_message
    if msg is None or not msg.text:
        return

    text_value = msg.text.strip()
    if text_value.startswith("/"):
        return

    # Must be a reply to the bot's prompt message (handles privacy mode).
    expected_bot_msg_id = context.user_data.get("awaiting_token_bot_msg_id")
    if not msg.reply_to_message or msg.reply_to_message.message_id != expected_bot_msg_id:
        return

    # Use the first line as the value so multi-line pastes don't confuse us.
    value = text_value.splitlines()[0].strip()

    if awaiting_name:
        context.user_data.pop("awaiting_token_name", None)
        context.user_data.pop("awaiting_token_bot_msg_id", None)
        name = value
        if not name or len(name) > 48:
            context.user_data["awaiting_token_name"] = True
            retry = await update.message.reply_text(
                "Token name must be 1..48 characters. Reply to this message with the name, or /cancel to abort."
            )
            context.user_data["awaiting_token_bot_msg_id"] = retry.message_id
            return
        context.user_data["token_name"] = name
        context.user_data["awaiting_token_interval"] = True
        bot_msg = await update.message.reply_text(
            "Reply to this message with the rewards interval (e.g. 30s, 15m, 1h, 2d, 1w), or /cancel to abort."
        )
        context.user_data["awaiting_token_bot_msg_id"] = bot_msg.message_id
        return

    if awaiting_interval:
        context.user_data.pop("awaiting_token_interval", None)
        context.user_data.pop("awaiting_token_bot_msg_id", None)
        name = context.user_data.pop("token_name", None)
        if not name:
            context.user_data.pop("awaiting_token_chat_id", None)
            await update.message.reply_text(
                "Something went wrong — the token name was lost. Try /create_token again."
            )
            return
        context.user_data.pop("awaiting_token_chat_id", None)
        interval_raw = value
        await _process_create_token(update, context, name, interval_raw)


async def unset_wallet_command(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Remove the linked wallet without dropping accrued pending rewards.

    The user can /set_wallet again later (possibly with a different address)
    and the pending balance will be minted to the new wallet.
    """
    user_id = update.effective_user.id
    try:
        with engine.begin() as conn:
            result = conn.execute(
                text("DELETE FROM tg_wallets WHERE user_id = :u"),
                {"u": user_id},
            )
    except Exception as e:
        logger.error(f"Error in unset_wallet: {e}")
        await update.message.reply_text("Error removing wallet. Try again.")
        return

    if result.rowcount == 0:
        await update.message.reply_text("No wallet was linked.")
        return

    await update.message.reply_text(
        "Wallet removed. Any pending rewards stay credited and will be minted "
        "to the next wallet you link with /set_wallet."
    )


BALANCE_OF_ABI = [{
    "inputs": [{"name": "account", "type": "address"}],
    "name": "balanceOf",
    "outputs": [{"name": "", "type": "uint256"}],
    "stateMutability": "view",
    "type": "function",
}]


async def balance_command(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Show: linked wallet, global TG balance, every chat-token balance the user
    is eligible for, and any pending (claimable on /set_wallet) amounts."""
    user_id = update.effective_user.id

    # Without admin rights the bot doesn't receive chat_member updates, so
    # silent observers never end up in chat_members. Treat /balance in a group
    # as proof-of-presence: record the caller so they (and the chat owner who
    # almost certainly also runs /balance) actually start accruing rewards.
    chat = update.effective_chat
    if chat is not None and chat.type in ("group", "supergroup"):
        try:
            _record_chat_member(chat.id, user_id)
        except Exception as e:
            logger.debug(f"balance member tracking failed: {e}")

    with engine.connect() as conn:
        row = conn.execute(
            text("SELECT address FROM tg_wallets WHERE user_id = :u"),
            {"u": user_id},
        ).fetchone()
        # Chat tokens this user is a member of (regardless of wallet status).
        chat_tokens = conn.execute(
            text("""
                SELECT t.chat_id, t.name, t.symbol, t.token_address
                FROM chat_members cm
                JOIN chat_tokens t ON t.chat_id = cm.chat_id
                WHERE cm.user_id = :u
                  AND t.token_address IS NOT NULL
            """),
            {"u": user_id},
        ).fetchall()
        pending = conn.execute(
            text("""
                SELECT t.symbol, p.amount
                FROM pending_rewards p
                JOIN chat_tokens t ON t.chat_id = p.chat_id
                WHERE p.user_id = :u
                  AND CAST(p.amount AS INTEGER) > 0
            """),
            {"u": user_id},
        ).fetchall()

    address = row[0] if row else None
    lines = []

    if address:
        lines.append(f"Wallet: {address}")
    else:
        lines.append("Wallet: not set -- use /set_wallet <address> to claim pending rewards.")

    if address:
        try:
            w3 = Web3(Web3.HTTPProvider(RPC_URL))
            checksum = Web3.to_checksum_address(address)
        except Exception as e:
            logger.exception(f"balance: web3/checksum init failed: {e}")
            w3 = None
            checksum = None

        # Global TG token is optional: if TG_TOKEN_ADDRESS isn't configured we
        # just skip this line instead of poisoning the whole message.
        if w3 is not None and checksum is not None and TOKEN_ADDRESS:
            try:
                # TOKEN_ABI only declares mint/symbol; balanceOf lives in
                # BALANCE_OF_ABI, which is the only thing we need here.
                tg_contract = w3.eth.contract(
                    address=Web3.to_checksum_address(TOKEN_ADDRESS), abi=BALANCE_OF_ABI
                )
                tg_balance = tg_contract.functions.balanceOf(checksum).call() / 10**18
                lines.append(f"TG (global): {tg_balance:g}")
            except Exception as e:
                logger.exception(
                    f"balance: TG global read failed for {address} at {TOKEN_ADDRESS}: {e}"
                )
                lines.append("TG (global): (read error)")

        if w3 is not None and checksum is not None and chat_tokens:
            lines.append("")
            lines.append("Chat token balances:")
            for _cid, _name, symbol, token_address in chat_tokens:
                try:
                    c = w3.eth.contract(
                        address=Web3.to_checksum_address(token_address),
                        abi=BALANCE_OF_ABI,
                    )
                    bal = c.functions.balanceOf(checksum).call() / 10**18
                    lines.append(f"  {symbol}: {bal:g}")
                except Exception as e:
                    logger.exception(
                        f"balance: chat-token read failed for {symbol} ({token_address}): {e}"
                    )
                    lines.append(f"  {symbol}: (read error)")

    if pending:
        lines.append("")
        lines.append("Pending (claim by setting a wallet):")
        for symbol, amount_str in pending:
            human = int(amount_str) / 10**18
            lines.append(f"  {human:g} {symbol}")
    elif not address:
        # Help wallet-less users understand why pending may be zero. Show every
        # chat-with-token they are a member of, so they know what to expect.
        lines.append("")
        if chat_tokens:
            lines.append("You are eligible for these chat tokens (rewards will accrue here):")
            for _cid, _name, symbol, _addr in chat_tokens:
                lines.append(f"  {symbol}")
            lines.append(
                "Pending is empty right now. New rewards are credited on each "
                "payout tick of the chat, so check back after the next interval."
            )
        else:
            lines.append(
                "No pending rewards yet. Write at least one message in a chat "
                "that has a reward token, then wait for its next payout tick."
            )

    await update.message.reply_text("\n".join(lines))


async def wallet_command(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Show the user's linked wallet address and a link to it on the explorer."""
    user_id = update.effective_user.id
    with engine.connect() as conn:
        row = conn.execute(
            text("SELECT address FROM tg_wallets WHERE user_id = :u"),
            {"u": user_id},
        ).fetchone()
    if not row:
        await update.message.reply_text(
            "No wallet linked yet. Use /set_wallet <address> to link one."
        )
        return
    address = row[0]
    await update.message.reply_text(
        f"Wallet: {address}\n{EXPLORER_URL}/address/{address}"
    )


ADMIN_USERNAME = "rtutin"


async def whois_command(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """/whois <address> — admin-only. Show the Telegram user(s) linked to a
    wallet address, the chats they belong to, any GitHub link, and pending
    rewards. Restricted to @rtutin."""
    user = update.effective_user
    if user is None or (user.username or "").lower() != ADMIN_USERNAME:
        logger.warning(
            "Unauthorized /whois attempt by user_id=%s username=%s",
            user.id if user else None,
            user.username if user else None,
        )
        return

    args = context.args or []
    if not args:
        await update.message.reply_text("Usage: /whois <wallet_address>")
        return

    address = args[0].strip()
    if not is_valid_eth_address(address):
        await update.message.reply_text("Invalid address. Expected 0x followed by 40 hex characters.")
        return

    address_lower = address.lower()

    with engine.connect() as conn:
        wallets = conn.execute(
            text("SELECT user_id, address, created_at FROM tg_wallets WHERE LOWER(address) = :a"),
            {"a": address_lower},
        ).fetchall()

        github_links = []
        try:
            github_links = conn.execute(
                text("SELECT github_username FROM github_wallets WHERE LOWER(wallet_address) = :a"),
                {"a": address_lower},
            ).fetchall()
        except Exception as e:
            logger.debug(f"whois: github_wallets lookup failed: {e}")

    if not wallets and not github_links:
        await update.message.reply_text(f"No user is linked to {address}.")
        return

    lines = [f"Wallet: {address}"]

    if github_links:
        gh = ", ".join(f"@{row[0]}" for row in github_links)
        lines.append(f"GitHub: {gh}")

    for user_id, addr, created_at in wallets:
        lines.append("")
        lines.append(f"Telegram user_id: {user_id}")
        lines.append(f"Linked at: {created_at}")

        try:
            tg_user = await context.bot.get_chat(user_id)
            uname = f"@{tg_user.username}" if tg_user.username else "(no username)"
            full_name = " ".join(
                p for p in (tg_user.first_name, tg_user.last_name) if p
            ) or "(no name)"
            lines.append(f"Name: {full_name} {uname}")
        except TelegramError as e:
            lines.append(f"Name: (lookup failed: {e})")

        with engine.connect() as conn:
            chats = conn.execute(
                text("""
                    SELECT cm.chat_id, t.name, t.symbol, cm.first_seen, cm.last_seen
                    FROM chat_members cm
                    LEFT JOIN chat_tokens t ON t.chat_id = cm.chat_id
                    WHERE cm.user_id = :u
                    ORDER BY cm.last_seen DESC
                """),
                {"u": user_id},
            ).fetchall()
            pending = conn.execute(
                text("""
                    SELECT t.symbol, p.amount
                    FROM pending_rewards p
                    JOIN chat_tokens t ON t.chat_id = p.chat_id
                    WHERE p.user_id = :u
                      AND CAST(p.amount AS INTEGER) > 0
                """),
                {"u": user_id},
            ).fetchall()

        if chats:
            lines.append(f"Chats ({len(chats)}):")
            for chat_id, name, symbol, first_seen, last_seen in chats:
                label = f"{name} ({symbol})" if name else "(no token)"
                lines.append(
                    f"  {chat_id} {label} — seen {first_seen}..{last_seen}"
                )
        else:
            lines.append("Chats: none tracked")

        if pending:
            lines.append("Pending rewards:")
            for symbol, amount_str in pending:
                human = int(amount_str) / 10**18
                lines.append(f"  {human:g} {symbol}")

    await update.message.reply_text("\n".join(lines))


async def token_command(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Show this chat's reward token details and an explorer link."""
    chat = update.effective_chat
    if chat is None:
        return
    if chat.type not in ("group", "supergroup"):
        await update.message.reply_text(
            "Use this command inside the group chat whose token you want to inspect."
        )
        return
    # Same reasoning as in /balance: running /token in the group is concrete
    # proof the caller belongs here, so use it to backfill chat_members when
    # the bot lacks admin rights to receive chat_member updates.
    user = update.effective_user
    if user is not None and not user.is_bot:
        try:
            _record_chat_member(chat.id, user.id)
        except Exception as e:
            logger.debug(f"token member tracking failed: {e}")

    chat_token = get_chat_token(chat.id)
    if chat_token is None:
        await update.message.reply_text(
            "This chat has no reward token. The chat owner can create one with "
            "/create_token <name> <interval>."
        )
        return
    _cid, name, symbol, token_address, interval, _reward = chat_token
    if not token_address:
        await update.message.reply_text(
            f"Token {name} ({symbol}) is still being deployed. Try again shortly."
        )
        return
    await update.message.reply_text(
        f"Name: {name}\n"
        f"Symbol: {symbol}\n"
        f"Address: {token_address}\n"
        f"Rewards interval: {format_interval(interval)}\n"
        f"{EXPLORER_URL}/address/{token_address}"
    )


async def track_chat_member(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Record (chat_id, user_id) for any message in a group, and remove the
    row when a service message reports the user left or was kicked. Used by
    the payout pipeline to know who currently belongs to a chat that has its
    own reward token."""
    chat = update.effective_chat
    message = update.effective_message
    if chat is None or chat.type not in ("group", "supergroup"):
        return

    # Membership departures arrive as service messages on the same update.
    if message is not None:
        left = getattr(message, "left_chat_member", None)
        if left is not None and not left.is_bot:
            try:
                _forget_chat_member(chat.id, left.id)
                logger.info(
                    "chat_members: removed user_id=%s from chat_id=%s (left/kicked via service msg)",
                    left.id, chat.id,
                )
            except Exception as e:
                logger.debug(f"forget_chat_member (left) failed: {e}")

        new_members = getattr(message, "new_chat_members", None) or []
        for member in new_members:
            if member.is_bot:
                continue
            try:
                _record_chat_member(chat.id, member.id)
            except Exception as e:
                logger.debug(f"record_chat_member (new) failed: {e}")

    # Anyone whose message reached us is, by definition, currently in the chat.
    user = update.effective_user
    if user is None or user.is_bot:
        return
    try:
        _record_chat_member(chat.id, user.id)
    except Exception as e:
        logger.debug(f"track_chat_member failed: {e}")


async def on_chat_member_update(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Handle `chat_member` updates -- fires for *every* membership status
    transition in groups where the bot is admin, including silent leaves and
    bans that never produce a visible service message. Required for the
    payout filter to stay accurate when users quietly leave."""
    cmu = update.chat_member
    if cmu is None:
        return
    chat = cmu.chat
    if chat.type not in ("group", "supergroup"):
        return
    user = cmu.new_chat_member.user
    if user.is_bot:
        return

    new_status = cmu.new_chat_member.status
    # Statuses that mean "currently in the chat".
    present = {"creator", "administrator", "member", "restricted"}
    try:
        if new_status in present:
            _record_chat_member(chat.id, user.id)
        else:
            # left, kicked, etc.
            _forget_chat_member(chat.id, user.id)
            logger.info(
                "chat_members: removed user_id=%s from chat_id=%s (status=%s via chat_member update)",
                user.id, chat.id, new_status,
            )
    except Exception as e:
        logger.debug(f"on_chat_member_update failed: {e}")


def _short_addr(addr: str | None) -> str:
    if not addr:
        return "?"
    if len(addr) <= 12:
        return addr
    return f"{addr[:6]}...{addr[-4:]}"


def _format_decimal_amount(value) -> str:
    """bridge_requests.amount is a DECIMAL(_,18). Strip trailing zeros for display."""
    s = str(value or "0")
    if "." in s:
        s = s.rstrip("0").rstrip(".")
    return s or "0"


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


# --- Ritual DEX swap announcer ---------------------------------------------

# keccak256("Swap(address,uint256,uint256,uint256,uint256,address)").
# Normalize to "0x..." since hexbytes' `.hex()` adds the prefix in 1.x but not
# in older releases, and eth_getLogs expects 0x-prefixed topic hex.
_swap_topic_hex = Web3.keccak(
    text="Swap(address,uint256,uint256,uint256,uint256,address)"
).hex()
SWAP_EVENT_TOPIC = "0x" + _swap_topic_hex.removeprefix("0x").removeprefix("0X")

PAIR_ABI = [
    {"inputs": [], "name": "token0",
     "outputs": [{"name": "", "type": "address"}],
     "stateMutability": "view", "type": "function"},
    {"inputs": [], "name": "token1",
     "outputs": [{"name": "", "type": "address"}],
     "stateMutability": "view", "type": "function"},
]
ERC20_META_ABI = [
    {"inputs": [], "name": "symbol",
     "outputs": [{"name": "", "type": "string"}],
     "stateMutability": "view", "type": "function"},
    {"inputs": [], "name": "decimals",
     "outputs": [{"name": "", "type": "uint8"}],
     "stateMutability": "view", "type": "function"},
]

# Long-lived process: caching pair→tokens and token→(symbol,decimals) cuts the
# RPC chatter from "4 calls per swap" to ~zero once the active set is warm.
_pair_token_cache: dict[str, tuple[str, str]] = {}
_token_meta_cache: dict[str, tuple[str, int]] = {}


def _get_pair_tokens(w3: Web3, pair_addr: str) -> tuple[str, str] | None:
    addr = Web3.to_checksum_address(pair_addr)
    cached = _pair_token_cache.get(addr)
    if cached is not None:
        return cached
    try:
        pair = w3.eth.contract(address=addr, abi=PAIR_ABI)
        t0 = pair.functions.token0().call()
        t1 = pair.functions.token1().call()
    except Exception as e:
        logger.warning(f"swap_announcer: pair {addr} token0/1 failed: {e}")
        return None
    _pair_token_cache[addr] = (t0, t1)
    return t0, t1


def _get_token_meta(w3: Web3, token_addr: str) -> tuple[str, int]:
    addr = Web3.to_checksum_address(token_addr)
    cached = _token_meta_cache.get(addr)
    if cached is not None:
        return cached
    c = w3.eth.contract(address=addr, abi=ERC20_META_ABI)
    try:
        sym = c.functions.symbol().call()
    except Exception:
        sym = addr[:6] + "..." + addr[-4:]
    try:
        dec = int(c.functions.decimals().call())
    except Exception:
        dec = 18
    meta = (sym, dec)
    _token_meta_cache[addr] = meta
    return meta


def _hex_no_prefix(value) -> str:
    if isinstance(value, (bytes, bytearray)):
        return value.hex()
    s = str(value)
    if s.startswith("0x") or s.startswith("0X"):
        return s[2:]
    return s


def _decode_topic_address(topic) -> str:
    h = _hex_no_prefix(topic).lower()
    return Web3.to_checksum_address("0x" + h[-40:])


def _decode_swap_data(data) -> tuple[int, int, int, int]:
    """V2 Swap data: four uint256 words — amount0In, amount1In, amount0Out, amount1Out."""
    h = _hex_no_prefix(data)
    return (
        int(h[0:64], 16),
        int(h[64:128], 16),
        int(h[128:192], 16),
        int(h[192:256], 16),
    )


FACTORY_GET_PAIR_ABI = [{
    "inputs": [
        {"name": "tokenA", "type": "address"},
        {"name": "tokenB", "type": "address"},
    ],
    "name": "getPair",
    "outputs": [{"name": "pair", "type": "address"}],
    "stateMutability": "view", "type": "function",
}]

PAIR_RESERVES_ABI = [{
    "inputs": [], "name": "getReserves",
    "outputs": [
        {"name": "reserve0", "type": "uint112"},
        {"name": "reserve1", "type": "uint112"},
        {"name": "blockTimestampLast", "type": "uint32"},
    ],
    "stateMutability": "view", "type": "function",
}]

# token_addr_lower → (usd_price | None, fetched_at_ts). `None` means "looked
# up but no priceable route" — cached to avoid retrying every block.
_token_usd_price_cache: dict[str, tuple[float | None, float]] = {}
_TOKEN_USD_TTL_SECONDS = 300.0


def _get_token_usd_price(w3: Web3, token_addr: str, _depth: int = 0) -> float | None:
    """USD price of one whole token. Anchors return 1.0; otherwise walks at
    most one hop via a relay token to reach an anchor. Pools whose anchor/relay
    side holds less than PRICE_MIN_POOL_USD are ignored (dust pools have
    meaningless reserve ratios); among the rest the deepest pool wins.
    Cached for 5 min."""
    addr = token_addr.lower()
    if addr in USD_ANCHORS:
        return 1.0
    now = time.time()
    cached = _token_usd_price_cache.get(addr)
    if cached is not None and now - cached[1] < _TOKEN_USD_TTL_SECONDS:
        return cached[0]
    if _depth >= 2:
        return None

    factory = w3.eth.contract(
        address=Web3.to_checksum_address(RITUAL_V2_FACTORY), abi=FACTORY_GET_PAIR_ABI
    )

    def price_via(target_addr: str, target_price_usd: float) -> tuple[float, float] | None:
        """(price, depth_usd) via the token's direct pool with `target`, where
        depth_usd is the USD value of the pool's target-side reserve — the
        walker's confidence in this route."""
        try:
            pair_addr = factory.functions.getPair(
                Web3.to_checksum_address(token_addr),
                Web3.to_checksum_address(target_addr),
            ).call()
        except Exception:
            return None
        if not pair_addr or int(pair_addr, 16) == 0:
            return None
        try:
            pair = w3.eth.contract(
                address=Web3.to_checksum_address(pair_addr), abi=PAIR_RESERVES_ABI
            )
            reserves = pair.functions.getReserves().call()
        except Exception:
            return None
        tokens = _get_pair_tokens(w3, pair_addr)
        if tokens is None:
            return None
        t0, _t1 = tokens
        _, dec_token = _get_token_meta(w3, token_addr)
        _, dec_target = _get_token_meta(w3, target_addr)
        if t0.lower() == addr:
            r_token, r_target = reserves[0], reserves[1]
        else:
            r_token, r_target = reserves[1], reserves[0]
        if r_token <= 0 or r_target <= 0:
            return None
        depth_usd = (r_target / 10**dec_target) * target_price_usd
        if depth_usd < PRICE_MIN_POOL_USD:
            return None
        price = (r_target / 10**dec_target) / (r_token / 10**dec_token) * target_price_usd
        return price, depth_usd

    routes: list[tuple[float, float]] = []

    for anchor in USD_ANCHORS:
        r = price_via(anchor, 1.0)
        if r is not None and r[0] > 0:
            routes.append(r)

    for relay in PRICE_RELAY_TOKENS:
        if relay == addr:
            continue
        relay_price = _get_token_usd_price(w3, relay, _depth=_depth + 1)
        if relay_price is None or relay_price <= 0:
            continue
        r = price_via(relay, relay_price)
        if r is not None and r[0] > 0:
            routes.append(r)

    price = max(routes, key=lambda r: r[1])[0] if routes else None
    # A None computed mid-recursion may be an artifact of the depth guard, not
    # a real "no route" — caching it would poison the token for the TTL.
    if price is not None or _depth == 0:
        _token_usd_price_cache[addr] = (price, now)
    return price


def _swap_usd_volume(
    w3: Web3,
    in_addr: str, in_amt: int, in_dec: int,
    out_addr: str, out_amt: int, out_dec: int,
) -> float | None:
    """USD value of a swap. Prefers pricing the input side (closer to user
    intent), falls back to the output side. Returns None if neither can be
    priced — caller decides whether to allow through."""
    in_price = _get_token_usd_price(w3, in_addr)
    if in_price is not None:
        return (in_amt / 10**in_dec) * in_price
    out_price = _get_token_usd_price(w3, out_addr)
    if out_price is not None:
        return (out_amt / 10**out_dec) * out_price
    return None


def _liquidity_usd_volume(
    w3: Web3,
    t0_addr: str, t1_addr: str,
    dec0: int, dec1: int,
    amount0: int, amount1: int,
) -> float | None:
    """USD value of a Mint/Burn. Both sides equal in a constant-product pool,
    so if only one side can be priced we double it."""
    p0 = _get_token_usd_price(w3, t0_addr)
    p1 = _get_token_usd_price(w3, t1_addr)
    val0 = (amount0 / 10**dec0) * p0 if p0 is not None else None
    val1 = (amount1 / 10**dec1) * p1 if p1 is not None else None
    if val0 is None and val1 is None:
        return None
    if val0 is None:
        return val1 * 2  # type: ignore[operator]
    if val1 is None:
        return val0 * 2
    return val0 + val1


def _format_token_amount(amount: int, decimals: int) -> str:
    if amount == 0:
        return "0"
    if decimals <= 0:
        return str(amount)
    # 6 fractional digits is enough for human-readable; trim trailing zeros.
    s = f"{amount / 10**decimals:.6f}"
    if "." in s:
        s = s.rstrip("0").rstrip(".")
    return s or "0"


def _router_topic_hex() -> str:
    return "0x" + RITUAL_V2_ROUTER.lower().replace("0x", "").rjust(64, "0")


def _decode_data_words(data, n: int) -> list[int]:
    """Decode the first `n` 32-byte words of an event's data blob to ints."""
    h = _hex_no_prefix(data)
    return [int(h[i * 64:(i + 1) * 64], 16) for i in range(n)]


def _get_block_cursor(key: str) -> tuple[int, int] | None:
    """Read a `block:log_index` cursor stored in bot_kv under `key`."""
    raw = _kv_get(key)
    if raw is None:
        return None
    try:
        block_str, idx_str = raw.split(":", 1)
        return int(block_str), int(idx_str)
    except Exception:
        return None


def _set_block_cursor(key: str, block: int, log_index: int) -> None:
    _kv_set(key, f"{block}:{log_index}")


def _get_swap_cursor() -> tuple[int, int] | None:
    return _get_block_cursor("last_announced_swap_cursor")


def _set_swap_cursor(block: int, log_index: int) -> None:
    _set_block_cursor("last_announced_swap_cursor", block, log_index)


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


# --- Ritual DEX liquidity announcer (V2 Mint/Burn) -------------------------

def _event_topic(signature: str) -> str:
    """0x-prefixed keccak topic for an event signature. Normalizes the prefix
    since hexbytes' `.hex()` adds `0x` in 1.x but not in older releases."""
    return "0x" + Web3.keccak(text=signature).hex().removeprefix("0x").removeprefix("0X")


# keccak256("Mint(address,uint256,uint256)") / "Burn(address,uint256,uint256,address)".
LP_MINT_TOPIC = _event_topic("Mint(address,uint256,uint256)")
LP_BURN_TOPIC = _event_topic("Burn(address,uint256,uint256,address)")

# Cache tx hash → initiating EOA so re-scanned/multi-event txs cost one lookup.
_tx_sender_cache: dict[str, str] = {}


def _get_tx_sender(w3: Web3, tx_hash: str) -> str | None:
    cached = _tx_sender_cache.get(tx_hash)
    if cached is not None:
        return cached
    try:
        tx = w3.eth.get_transaction(tx_hash)
        sender = Web3.to_checksum_address(tx["from"])
    except Exception as e:
        logger.warning(f"liquidity_announcer: get_transaction {tx_hash} failed: {e}")
        return None
    _tx_sender_cache[tx_hash] = sender
    return sender


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


# --- Lending announcer (Compound-style markets) ----------------------------

# Market event topics. Note Mint(address,uint256,uint256) collides with the V2
# pair Mint signature, but the two announcers filter disjoint address sets
# (lending markets vs. router-routed pairs), so they never cross-fire.
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

GET_ALL_MARKETS_ABI = [{
    "inputs": [], "name": "getAllMarkets",
    "outputs": [{"name": "", "type": "address[]"}],
    "stateMutability": "view", "type": "function",
}]
UNDERLYING_ABI = [{
    "inputs": [], "name": "underlying",
    "outputs": [{"name": "", "type": "address"}],
    "stateMutability": "view", "type": "function",
}]

# market address (checksummed) → underlying ERC20 address.
_market_underlying_cache: dict[str, str] = {}


def _get_lending_markets(w3: Web3) -> list[str]:
    c = w3.eth.contract(
        address=Web3.to_checksum_address(LENDING_COMPTROLLER), abi=GET_ALL_MARKETS_ABI
    )
    return [Web3.to_checksum_address(m) for m in c.functions.getAllMarkets().call()]


def _get_market_underlying(w3: Web3, market_addr: str) -> str | None:
    addr = Web3.to_checksum_address(market_addr)
    cached = _market_underlying_cache.get(addr)
    if cached is not None:
        return cached
    try:
        c = w3.eth.contract(address=addr, abi=UNDERLYING_ABI)
        underlying = Web3.to_checksum_address(c.functions.underlying().call())
    except Exception as e:
        logger.warning(f"lending_announcer: underlying() failed for {addr}: {e}")
        return None
    _market_underlying_cache[addr] = underlying
    return underlying


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


# --- Activity digest --------------------------------------------------------
# Aggregates activity_events into one periodic summary message instead of a
# stream of per-event posts. Also backs the on-demand /stats command.

_KV_LAST_DIGEST_AT = "last_digest_at"
_KV_PREV_CYBER_PRICE = "digest_prev_cyber_price"
_SQLITE_TS = "%Y-%m-%d %H:%M:%S"


def _format_window(seconds: float) -> str:
    seconds = int(seconds)
    if seconds >= 48 * 3600:
        return f"{round(seconds / 86400)}d"
    if seconds >= 3600:
        return f"{round(seconds / 3600)}h"
    return f"{max(1, seconds // 60)}m"


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

    total = (
        swap_count
        + sum(c for c, _v in liq.values())
        + sum(row[2] for row in bridges)
        + sum(row[1] for row in lending)
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


# --- Market snapshot (token prices + DEX pools → SQLite) --------------------
# Mirrors the price walker's view of the chain into token_prices/dex_pools so
# the Laravel /analytics page can render without doing any RPC of its own.

MARKET_SNAPSHOT_SECONDS = int(os.environ.get("MARKET_SNAPSHOT_SECONDS", "300"))

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


async def stats_command(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """/stats [window] — on-demand activity digest, default last 24h.
    Examples: /stats, /stats 6h, /stats 3d. Read-only: never moves the
    periodic digest's window or its price comparison base."""
    args = context.args or []
    seconds = 24 * 3600
    if args:
        try:
            seconds = parse_interval(args[0])
        except ValueError as e:
            await update.message.reply_text(
                f"Bad window: {e}\nExamples: /stats 6h, /stats 3d"
            )
            return

    since = (datetime.now(timezone.utc) - timedelta(seconds=seconds)).strftime(_SQLITE_TS)
    window_label = _format_window(seconds)
    digest = await asyncio.to_thread(_build_digest_text, since, window_label)
    if digest is None:
        await update.message.reply_text(
            f"No tracked on-chain activity in the last {window_label}."
        )
        return
    price_line = await asyncio.to_thread(_cyber_price_line)
    if price_line:
        digest += "\n\n" + price_line
    await update.message.reply_text(digest, disable_web_page_preview=True)


# --- Whales chat gate ---------------------------------------------------------

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


async def whale_command(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """/whale — DM only. Hand out a one-time link to prove CYBER.sol holdings via
    Phantom and (if above the threshold) get invited to the whales chat."""
    chat = update.effective_chat
    user = update.effective_user
    if user is None:
        return
    if chat is not None and chat.type != "private":
        await update.message.reply_text(
            f"To access the chat you must hold {WHALE_MIN_CYBER_SOL:,} CYBER.sol.\n"
            "DM me /whale in private to verify your CYBER.sol balance."
        )
        return
    if not WHALE_CHAT_ID:
        await update.message.reply_text("Whale verification isn't configured on this bot yet.")
        return

    token = secrets.token_urlsafe(24)
    expires_at = (datetime.now(timezone.utc) + timedelta(minutes=WHALE_LINK_TTL_MINUTES)).strftime("%Y-%m-%d %H:%M:%S")
    try:
        with engine.begin() as conn:
            conn.execute(
                text("""
                    INSERT INTO tg_link_tokens (token, tg_user_id, expires_at, used)
                    VALUES (:t, :u, :e, 0)
                """),
                {"t": token, "u": user.id, "e": expires_at},
            )
    except Exception as e:
        logger.error(f"whale: token insert failed for {user.id}: {e}")
        await update.message.reply_text("Internal error. Try again.")
        return

    url = f"{WHALE_VERIFY_URL}?t={token}"
    await update.message.reply_text(
        f"To join the whales chat you must hold at least {WHALE_MIN_CYBER_SOL:,} CYBER.sol.\n\n"
        f"Open this link, connect Phantom and sign (valid {WHALE_LINK_TTL_MINUTES} min):\n{url}\n\n"
        "Once verified I'll DM you a one-time invite."
    )


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


async def error_handler(update: Update, context: ContextTypes.DEFAULT_TYPE):
    logger.error(f"Update {update} caused error {context.error}")


async def post_init(application: Application):
    await application.bot.set_my_commands(
        [
            BotCommand("start", "Start receiving TG"),
            BotCommand("help", "Show available commands"),
            BotCommand("set_wallet", "Link wallet (asks for address if omitted)"),
            BotCommand("unset_wallet", "Unlink your wallet (keep pending)"),
            BotCommand("wallet", "Show your linked wallet"),
            BotCommand("balance", "Show TG, chat tokens, and pending rewards"),
            BotCommand("token", "Show this chat's reward token"),
            BotCommand("cancel", "Cancel an interactive prompt"),
            BotCommand("github", "Link GitHub for GITHUB airdrop"),
            BotCommand("website", "Open the project website"),
            BotCommand("x", "Show X (Twitter) and Telegram links"),
            BotCommand("ca", "Show the CYBER contract address"),
            BotCommand("stats", "On-chain activity digest (default 24h)"),
            BotCommand("whale", "Verify CYBER.sol to join the whales chat"),
            BotCommand("create_token", "(admins) Create a chat reward token"),
            BotCommand("set_rewards_interval", "(admins) Change rewards interval"),
            BotCommand("reward_now", "(admins) Pay rewards immediately"),
        ]
    )
    logger.info("Bot commands published to Telegram")

    # Background loop that announces successful bridge txs in the public chat.
    application.create_task(bridge_announcer_loop(application))
    logger.info(
        f"Bridge announcer started: chat={BRIDGE_ANNOUNCE_CHAT} interval={BRIDGE_POLL_SECONDS}s"
    )

    # Background loop that announces Ritual DEX swaps in the public chat.
    application.create_task(swap_announcer_loop(application))
    logger.info(
        f"Swap announcer started: chat={SWAP_ANNOUNCE_CHAT} "
        f"router={RITUAL_V2_ROUTER} interval={SWAP_POLL_SECONDS}s"
    )

    # Background loop that announces Ritual DEX liquidity add/remove.
    application.create_task(liquidity_announcer_loop(application))
    logger.info(
        f"Liquidity announcer started: chat={LIQUIDITY_ANNOUNCE_CHAT} "
        f"router={RITUAL_V2_ROUTER} interval={LIQUIDITY_POLL_SECONDS}s"
    )

    # Background loop that announces lending supply/withdraw/borrow/repay.
    if LENDING_COMPTROLLER:
        application.create_task(lending_announcer_loop(application))
        logger.info(
            f"Lending announcer started: chat={LENDING_ANNOUNCE_CHAT} "
            f"comptroller={LENDING_COMPTROLLER} interval={LENDING_POLL_SECONDS}s"
        )
    else:
        logger.info("Lending announcer disabled: LENDING_COMPTROLLER not set")

    # Periodic on-chain activity digest.
    if DIGEST_INTERVAL_SECONDS > 0:
        application.create_task(digest_loop(application))
        logger.info(
            f"Digest started: chat={DIGEST_ANNOUNCE_CHAT} "
            f"interval={DIGEST_INTERVAL_SECONDS}s big-event=${BIG_ANNOUNCE_USD:g}"
        )
    else:
        logger.info("Digest disabled: DIGEST_INTERVAL_SECONDS=0")

    # Token prices + DEX pools snapshot for the Laravel /analytics page.
    if MARKET_SNAPSHOT_SECONDS > 0:
        application.create_task(market_snapshot_loop(application))
        logger.info(f"Market snapshot started: every {MARKET_SNAPSHOT_SECONDS}s")
    else:
        logger.info("Market snapshot disabled: MARKET_SNAPSHOT_SECONDS=0")

    # Whales chat gate: invite verified whales and re-check balances/kick.
    if WHALE_CHAT_ID:
        application.create_task(whale_loop(application))
        logger.info(
            f"Whale loop started: chat={WHALE_CHAT_ID} min={WHALE_MIN_CYBER_SOL} "
            f"poll={WHALE_POLL_SECONDS}s recheck={WHALE_RECHECK_SECONDS}s"
        )
    else:
        logger.info("Whale gate disabled: WHALE_CHAT_ID not set")


def run_dispatcher():
    logger.info("Building application...")

    if not TELEGRAM_BOT_TOKEN:
        raise ValueError("TELEGRAM_BOT_TOKEN not set")

    builder = Application.builder().token(TELEGRAM_BOT_TOKEN).post_init(post_init)

    if HTTP_PROXY:
        proxy_url = HTTP_PROXY
        if proxy_url.startswith("http://") or proxy_url.startswith("socks5://"):
            logger.info(f"Using proxy: {proxy_url}")
            builder = builder.request(
                HTTPXRequest(
                    connection_pool_size=10,
                    proxy=proxy_url,
                )
            )
        else:
            logger.warning(f"Unsupported proxy format: {proxy_url}, ignoring")

    application = builder.build()

    application.add_handler(CommandHandler("start", start_command))
    application.add_handler(CommandHandler("help", help_command))
    application.add_handler(CommandHandler("set_wallet", set_wallet_command))
    application.add_handler(CommandHandler("unset_wallet", unset_wallet_command))
    application.add_handler(CommandHandler("wallet", wallet_command))
    application.add_handler(CommandHandler("cancel", cancel_command))
    application.add_handler(CommandHandler("balance", balance_command))
    application.add_handler(CommandHandler("token", token_command))
    application.add_handler(CommandHandler("github", github_command))
    application.add_handler(CommandHandler("website", website_command))
    application.add_handler(CommandHandler("create_token", create_token_command))
    application.add_handler(CommandHandler("set_rewards_interval", set_rewards_interval_command))
    application.add_handler(CommandHandler("reward_now", reward_now_command))
    application.add_handler(CommandHandler("whois", whois_command))
    application.add_handler(CommandHandler("whale", whale_command))
    application.add_handler(CommandHandler("x", x_command))
    application.add_handler(CommandHandler("ca", ca_command))
    application.add_handler(CommandHandler("stats", stats_command))

    # Capture the "next message is the address" reply after a bare /set_wallet
    # in DMs. Restricted to private chats so the bot never hijacks ordinary
    # group messages.
    application.add_handler(
        MessageHandler(
            filters.ChatType.PRIVATE & filters.TEXT & ~filters.COMMAND,
            pending_input_handler,
        )
    )

    # Capture name/interval replies after a bare /create_token in groups.
    application.add_handler(
        MessageHandler(
            (filters.ChatType.GROUPS | filters.ChatType.SUPERGROUP)
            & filters.TEXT & ~filters.COMMAND,
            pending_create_token_handler,
        )
    )

    # Track chat membership on any group message, including commands.
    application.add_handler(
        MessageHandler(
            filters.ChatType.GROUPS | filters.ChatType.SUPERGROUP,
            track_chat_member,
        ),
        group=1,
    )

    # Bare-text quick replies ("x", "ca", …) in any chat type. Lives in its own
    # handler group so it never collides with the wallet/token follow-up
    # handlers above; the Regex filter keeps it from running on ordinary chat.
    application.add_handler(
        MessageHandler(
            filters.TEXT & ~filters.COMMAND & filters.Regex(_QUICK_REPLY_RE),
            quick_reply_handler,
        ),
        group=2,
    )

    # Catch silent leaves/kicks and joins. Requires the bot to be admin in the
    # chat to receive these updates from Telegram. Without admin rights only
    # service messages (handled above) will fire.
    application.add_handler(
        ChatMemberHandler(on_chat_member_update, ChatMemberHandler.CHAT_MEMBER)
    )

    application.add_error_handler(error_handler)

    logger.info("Bot started, polling...")

    try:
        application.run_polling(allowed_updates=["message", "chat_member"])
    except Exception as e:
        logger.error(f"Polling error: {e}")
        raise


def run_snapshot_once() -> None:
    """Refresh the analytics market snapshot (token_prices + dex_pools) once and
    exit. Needs only RPC + the shared SQLite DB — no Telegram token, no polling —
    so it can be run by hand or from cron to populate the /analytics page
    independently of the long-running bot."""
    logging.basicConfig(level=logging.INFO)
    w3 = Web3(Web3.HTTPProvider(RPC_URL))
    pools, priced = _take_market_snapshot(w3)
    logger.info(f"snapshot-once: {pools} pools, {priced} tokens priced -> {DB_PATH}")


if __name__ == "__main__":
    if "--snapshot-once" in sys.argv:
        run_snapshot_once()
    else:
        logger.info("Main entry point")
        run_dispatcher()
