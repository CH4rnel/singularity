import os
import sys
import time
import logging
from datetime import datetime, timedelta
from pathlib import Path

from dotenv import load_dotenv

from telegram import Update
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

TOKEN_ADDRESS = "0x02Bad7dCaD174D92FCE2baBBd0cE1A653b487f04"
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

RPC_URL = "http://195.166.164.94:8545"
CHAIN_ID = 49406

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
        "/set_wallet <address> - set your wallet\n"
        "/stop - stop receiving\n"
        "/balance - check balance\n"
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
        with engine.connect() as conn:
            result = conn.execute(
                text("SELECT address FROM tg_wallets WHERE user_id = :user_id"),
                {"user_id": user_id},
            ).fetchone()

            if result:
                conn.execute(
                    text("UPDATE tg_wallets SET address = :address WHERE user_id = :user_id"),
                    {"address": address, "user_id": user_id},
                )
                msg = f"Address updated: {address}"
            else:
                conn.execute(
                    text("INSERT INTO tg_wallets (user_id, address) VALUES (:user_id, :address)"),
                    {"user_id": user_id, "address": address},
                )
                msg = f"Address saved: {address}. You'll receive 1 TG every hour!"

            conn.commit()

        await update.message.reply_text(msg)
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


async def error_handler(update: Update, context: ContextTypes.DEFAULT_TYPE):
    logger.error(f"Update {update} caused error {context.error}")


def run_dispatcher():
    logger.info("Building application...")

    builder = Application.builder().token(TELEGRAM_BOT_TOKEN)

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
    application.add_handler(CommandHandler("website", website_command))

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