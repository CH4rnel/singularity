import os
import re
import sys
import time
import logging
from datetime import datetime, timedelta
from pathlib import Path

from dotenv import load_dotenv

from telegram import BotCommand, Update
from telegram.ext import Application, CommandHandler, MessageHandler, filters, ContextTypes
from telegram.error import TelegramError
from telegram.request import HTTPXRequest

from sqlalchemy import create_engine, text

load_dotenv(Path(__file__).parent / ".env")

TELEGRAM_BOT_TOKEN = os.environ.get("TELEGRAM_BOT_TOKEN")
if not TELEGRAM_BOT_TOKEN:
    raise ValueError("TELEGRAM_BOT_TOKEN not set")

CHAT_ID = os.environ.get("TELEGRAM_CHAT_ID")
HTTP_PROXY = os.environ.get("HTTP_PROXY")

DB_PATH = os.environ.get("DB_PATH", "/home/lain/random/singularity/backend/laravel/database/database.sqlite")

TOKEN_ADDRESS = "0x4A3B5919e60fd290172955753DdA9216E396170A"
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

TELEGRAM_TOKEN_FACTORY = os.environ.get("TELEGRAM_TOKEN_FACTORY")
DEPLOYER_PK = os.environ.get("DEPLOYER_PK")

FACTORY_ABI = [
    {
        "inputs": [
            {"name": "name_", "type": "string"},
            {"name": "symbol_", "type": "string"},
            {"name": "chatId", "type": "int64"},
            {"name": "tokenOwner", "type": "address"},
        ],
        "name": "createToken",
        "outputs": [{"name": "token", "type": "address"}],
        "stateMutability": "nonpayable",
        "type": "function",
    },
    {
        "inputs": [{"name": "", "type": "int64"}],
        "name": "tokenOfChat",
        "outputs": [{"name": "", "type": "address"}],
        "stateMutability": "view",
        "type": "function",
    },
    {
        "anonymous": False,
        "inputs": [
            {"indexed": True, "name": "chatId", "type": "int64"},
            {"indexed": True, "name": "token", "type": "address"},
            {"indexed": True, "name": "owner", "type": "address"},
            {"indexed": False, "name": "name", "type": "string"},
            {"indexed": False, "name": "symbol", "type": "string"},
        ],
        "name": "TokenCreated",
        "type": "event",
    },
]

MIN_REWARDS_INTERVAL_SECONDS = int(os.environ.get("MIN_REWARDS_INTERVAL_SECONDS", "60"))
MAX_REWARDS_INTERVAL_SECONDS = int(os.environ.get("MAX_REWARDS_INTERVAL_SECONDS", str(30 * 24 * 3600)))

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
    cleaned = re.sub(r"[^A-Za-z0-9]+", "", transliterate_name(name)).upper()
    if not cleaned:
        return f"CHAT{abs(chat_id)}"
    return cleaned[:8]


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


ensure_chat_token_schema()


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


def get_chat_token(chat_id: int):
    with engine.connect() as conn:
        row = conn.execute(
            text("SELECT chat_id, name, symbol, token_address, rewards_interval, reward_amount "
                 "FROM chat_tokens WHERE chat_id = :c"),
            {"c": chat_id},
        ).fetchone()
    return row


async def start_command(update: Update, context: ContextTypes.DEFAULT_TYPE):
    await update.message.reply_text(
        "Hi! I'll send you 1 TG token every hour on Cyberia (49406). "
        "Use /set_wallet <address> to register.\n\n"
        "Example: /set_wallet 0x1234567890abcdef1234567890abcdef12345678"
    )


async def help_command(update: Update, context: ContextTypes.DEFAULT_TYPE):
    await update.message.reply_text(
        "Commands:\n"
        "/start - start receiving TG\n"
        "/set_wallet <address> - link your wallet once, receive from every chat you join\n"
        "/stop - stop receiving\n"
        "/balance - check balance\n"
        "/create_token <name> <interval> - (admins) create a chat reward token\n"
        "   e.g. /create_token MyChatToken 1h\n"
        "/set_rewards_interval <interval> - (admins) change payout interval\n"
        "/reward_now - (admins) trigger an extra payout right now\n"
        "/github <username> <address> - link GitHub for GITHUB token airdrop\n"
        "/website - project website"
    )


async def website_command(update: Update, context: ContextTypes.DEFAULT_TYPE):
    await update.message.reply_text("https://cyberia-temple.github.io")


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


async def create_token_command(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """
    /create_token <name> <rewards_interval>
    Creates a per-chat ERC20Votes reward token via the on-chain factory and
    wires it to this chat. Only chat admins can run it; must be used from the
    target group/supergroup.
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

    if not await is_chat_admin(update, context):
        await update.message.reply_text("Only chat admins can create a token.")
        return

    args = context.args or []
    if len(args) < 2:
        await update.message.reply_text(
            "Usage: /create_token <name> <rewards_interval>\n"
            "Example: /create_token MyChatToken 1h\n"
            "Intervals: 30s, 15m, 1h, 2d, 1w"
        )
        return

    # name may contain spaces if the user passes them; last token is always the interval
    name = " ".join(args[:-1]).strip()
    interval_raw = args[-1]

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

    existing = get_chat_token(chat.id)
    if existing is not None:
        await update.message.reply_text(
            f"This chat already has a token: {existing[1]} ({existing[2]})\n"
            f"Address: {existing[3] or 'pending'}\n"
            f"Interval: {format_interval(existing[4])}"
        )
        return

    if not TELEGRAM_TOKEN_FACTORY or not DEPLOYER_PK:
        await update.message.reply_text(
            "Token creation is not configured on this bot "
            "(TELEGRAM_TOKEN_FACTORY / DEPLOYER_PK missing). Please contact the operator."
        )
        return

    symbol = slugify_symbol(name, chat.id)

    # Persist the request first so we never lose it if the on-chain call blows up.
    try:
        with engine.begin() as conn:
            conn.execute(
                text("""
                    INSERT INTO chat_tokens
                        (chat_id, name, symbol, rewards_interval, created_by)
                    VALUES
                        (:chat_id, :name, :symbol, :interval, :user_id)
                """),
                {
                    "chat_id": chat.id,
                    "name": name,
                    "symbol": symbol,
                    "interval": rewards_interval,
                    "user_id": user.id,
                },
            )
    except Exception as e:
        logger.error(f"Error persisting chat_token row for chat {chat.id}: {e}")
        await update.message.reply_text("Internal error saving token. Try again.")
        return

    status_msg = await update.message.reply_text(
        f"Deploying token {name} ({symbol})... this may take a few seconds."
    )

    # On-chain deployment
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
                name, symbol, int(chat.id), acct.address
            ).estimate_gas({"from": acct.address})
        except Exception as est_err:
            logger.warning(f"estimate_gas failed, falling back to 5_000_000: {est_err}")
            estimated = 5_000_000
        gas_limit = int(estimated * 1.25) + 50_000
        tx = factory.functions.createToken(
            name, symbol, int(chat.id), acct.address
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

        # Try to pull token address from event; fall back to view call.
        token_address = None
        try:
            events = factory.events.TokenCreated().process_receipt(receipt)
            if events:
                token_address = events[0]["args"]["token"]
        except Exception:
            token_address = None

        if not token_address:
            token_address = factory.functions.tokenOfChat(int(chat.id)).call()

        token_address = Web3.to_checksum_address(token_address)
    except Exception as e:
        logger.error(f"On-chain createToken failed for chat {chat.id}: {e}")
        # Roll back the DB row so the user can retry.
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
        "Token created!\n"
        f"Name: {name}\n"
        f"Symbol: {symbol}\n"
        f"Address: {token_address}\n"
        f"Rewards interval: {format_interval(rewards_interval)}\n\n"
        "Members: run /set_wallet <address> here to join the airdrop."
    )


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
    """Return chat members with wallet addresses from the global tg_wallets table."""
    with engine.connect() as conn:
        return conn.execute(
            text("""
                SELECT cm.user_id, wallets.address
                FROM chat_members cm
                JOIN tg_wallets wallets ON wallets.user_id = cm.user_id
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
            "No eligible recipients: no chat members have a wallet in tg_wallets."
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


async def set_wallet_command(update: Update, context: ContextTypes.DEFAULT_TYPE):
    args = context.args
    if not args:
        await update.message.reply_text("Usage: /set_wallet <address>\nExample: /set_wallet 0x1234...")
        return

    address = args[0].strip()
    user_id = update.effective_user.id

    if not is_valid_eth_address(address):
        await update.message.reply_text("Invalid address format. Expected 0x...")
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

        await update.message.reply_text(
            f"Wallet saved: {address}\n"
            "You will receive rewards from every chat token in groups you participate in, "
            "and 1 TG every hour from the global airdrop."
        )
    except Exception as e:
        logger.error(f"Error in set_wallet: {e}")
        await update.message.reply_text("Error saving address. Try again.")


async def stop_command(update: Update, context: ContextTypes.DEFAULT_TYPE):
    user_id = update.effective_user.id

    try:
        with engine.connect() as conn:
            conn.execute(
                text("DELETE FROM tg_wallets WHERE user_id = :user_id"),
                {"user_id": user_id},
            )
            conn.commit()

        await update.message.reply_text("You have been removed from the airdrop list.")
    except Exception as e:
        logger.error(f"Error in stop: {e}")
        await update.message.reply_text("Error removing you from list.")


async def balance_command(update: Update, context: ContextTypes.DEFAULT_TYPE):
    from web3 import Web3

    user_id = update.effective_user.id
    try:
        with engine.connect() as conn:
            result = conn.execute(
                text("SELECT address FROM tg_wallets WHERE user_id = :user_id"),
                {"user_id": user_id},
            ).fetchone()

        if not result:
            await update.message.reply_text("You are not registered. Use /set_wallet <address>.")
            return

        address = result[0]

        w3 = Web3(Web3.HTTPProvider(RPC_URL))
        contract = w3.eth.contract(address=Web3.to_checksum_address(TOKEN_ADDRESS), abi=TOKEN_ABI)

        balance = contract.functions.balanceOf(Web3.to_checksum_address(address)).call()
        decimals = 18
        balance_tg = balance / (10**decimals)

        await update.message.reply_text(f"Balance {address[:6]}...{address[-4:]}: {balance_tg} TG")
    except Exception as e:
        logger.error(f"Error in balance: {e}")
        await update.message.reply_text("Error fetching balance. Try again later.")


async def track_chat_member(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Record (chat_id, user_id) for any message in a group. Used by the payout
    script to know who is a member of a chat that has its own reward token."""
    chat = update.effective_chat
    user = update.effective_user
    if chat is None or user is None:
        return
    if chat.type not in ("group", "supergroup"):
        return
    if user.is_bot:
        return
    try:
        _record_chat_member(chat.id, user.id)
    except Exception as e:
        logger.debug(f"track_chat_member failed: {e}")


async def error_handler(update: Update, context: ContextTypes.DEFAULT_TYPE):
    logger.error(f"Update {update} caused error {context.error}")


async def post_init(application: Application):
    await application.bot.set_my_commands(
        [
            BotCommand("start", "Start receiving TG"),
            BotCommand("help", "Show available commands"),
            BotCommand("set_wallet", "Set your wallet address"),
            BotCommand("stop", "Stop receiving TG"),
            BotCommand("balance", "Check your TG balance"),
            BotCommand("github", "Link GitHub for GITHUB airdrop"),
            BotCommand("website", "Open the project website"),
            BotCommand("create_token", "(admins) Create a chat reward token"),
            BotCommand("set_rewards_interval", "(admins) Change rewards interval"),
            BotCommand("reward_now", "(admins) Pay rewards immediately"),
        ]
    )
    logger.info("Bot commands published to Telegram")


def run_dispatcher():
    logger.info("Building application...")

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
    application.add_handler(CommandHandler("stop", stop_command))
    application.add_handler(CommandHandler("balance", balance_command))
    application.add_handler(CommandHandler("github", github_command))
    application.add_handler(CommandHandler("website", website_command))
    application.add_handler(CommandHandler("create_token", create_token_command))
    application.add_handler(CommandHandler("set_rewards_interval", set_rewards_interval_command))
    application.add_handler(CommandHandler("reward_now", reward_now_command))

    # Track chat membership on any group message, including commands.
    application.add_handler(
        MessageHandler(
            filters.ChatType.GROUPS | filters.ChatType.SUPERGROUP,
            track_chat_member,
        ),
        group=1,
    )

    application.add_error_handler(error_handler)

    logger.info("Bot started, polling...")

    try:
        application.run_polling(allowed_updates=["message"])
    except Exception as e:
        logger.error(f"Polling error: {e}")
        raise


if __name__ == "__main__":
    logger.info("Main entry point")
    run_dispatcher()
