import os
import re
import sys
import asyncio
import logging
from pathlib import Path

from dotenv import load_dotenv
from twikit import Client as TwikitClient
from web3 import Web3
from sqlalchemy import create_engine, text

load_dotenv(Path(__file__).parent / ".env")

# --- Config ---

X_USERNAME = os.environ.get("X_USERNAME")
X_EMAIL = os.environ.get("X_EMAIL")
X_PASSWORD = os.environ.get("X_PASSWORD")
if not all([X_USERNAME, X_EMAIL, X_PASSWORD]):
    raise ValueError("X_USERNAME, X_EMAIL, X_PASSWORD must be set in .env")

DEPLOYER_PK = os.environ.get("DEPLOYER_PK")
if not DEPLOYER_PK:
    raise ValueError("DEPLOYER_PK not set")

HTTP_PROXY = os.environ.get("HTTP_PROXY")
X_MONITOR_ACCOUNT = os.environ.get("X_MONITOR_ACCOUNT", "cyberia_chain")
DB_PATH = os.environ.get("DB_PATH", "/home/lain/random/singularity/backend/laravel/database/database.sqlite")

X_TOKEN_ADDRESS = "0x14207CfF0880067B676B38cd17Ba7B002eeE8672"
TOKEN_ABI = [
    {"inputs": [{"name": "to", "type": "address"}, {"name": "amount", "type": "uint256"}], "name": "mint", "outputs": [], "stateMutability": "nonpayable", "type": "function"},
    {"inputs": [{"name": "account", "type": "address"}], "name": "balanceOf", "outputs": [{"name": "", "type": "uint256"}], "stateMutability": "view", "type": "function"},
]

RPC_URL = "https://rpc.cyberia.church"
CHAIN_ID = 49406
MINT_AMOUNT = Web3.to_wei(1, "ether")
POLL_INTERVAL = 120  # seconds

COOKIES_FILE = Path(__file__).parent / "x_cookies.json"

# --- Logging ---

LOG_FILE = Path(__file__).parent / "x_bot.log"

logging.basicConfig(
    format="%(asctime)s - %(name)s - %(levelname)s - %(message)s",
    level=logging.INFO,
    handlers=[
        logging.FileHandler(LOG_FILE),
        logging.StreamHandler(sys.stdout),
    ],
)

logger = logging.getLogger(__name__)

# --- DB ---

engine = create_engine(f"sqlite:///{DB_PATH}", connect_args={"check_same_thread": False})

with engine.connect() as conn:
    conn.execute(text("""
        CREATE TABLE IF NOT EXISTS x_airdrop_claims (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tweet_id TEXT UNIQUE NOT NULL,
            x_user_id TEXT NOT NULL,
            x_username TEXT NOT NULL,
            wallet_address TEXT NOT NULL,
            tx_hash TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    """))
    conn.commit()

logger.info("Database ready")

# --- Web3 ---

w3 = Web3(Web3.HTTPProvider(RPC_URL))
account = w3.eth.account.from_key(DEPLOYER_PK)
contract = w3.eth.contract(address=Web3.to_checksum_address(X_TOKEN_ADDRESS), abi=TOKEN_ABI)

logger.info(f"Deployer: {account.address}")

# --- Helpers ---

ETH_ADDRESS_RE = re.compile(r"0x[0-9a-fA-F]{40}")


def is_tweet_claimed(tweet_id: str) -> bool:
    with engine.connect() as conn:
        result = conn.execute(
            text("SELECT 1 FROM x_airdrop_claims WHERE tweet_id = :tid"),
            {"tid": tweet_id},
        ).fetchone()
    return result is not None


def has_user_claimed_with_different_wallet(x_user_id: str, wallet: str) -> bool:
    """Returns True if user already claimed with a DIFFERENT wallet."""
    with engine.connect() as conn:
        result = conn.execute(
            text("SELECT wallet_address FROM x_airdrop_claims WHERE x_user_id = :uid LIMIT 1"),
            {"uid": x_user_id},
        ).fetchone()
    if result is None:
        return False
    return result[0].lower() != wallet.lower()


def save_claim(tweet_id: str, x_user_id: str, x_username: str, wallet: str, tx_hash: str):
    with engine.connect() as conn:
        conn.execute(
            text("""
                INSERT INTO x_airdrop_claims (tweet_id, x_user_id, x_username, wallet_address, tx_hash)
                VALUES (:tid, :uid, :xuser, :wallet, :tx_hash)
            """),
            {"tid": tweet_id, "uid": x_user_id, "xuser": x_username, "wallet": wallet, "tx_hash": tx_hash},
        )
        conn.commit()


def mint_token(to_address: str) -> str:
    to = Web3.to_checksum_address(to_address)
    nonce = w3.eth.get_transaction_count(account.address)

    tx = contract.functions.mint(to, MINT_AMOUNT).build_transaction({
        "chainId": CHAIN_ID,
        "from": account.address,
        "nonce": nonce,
        "gas": 100_000,
        "gasPrice": w3.eth.gas_price,
    })

    signed = account.sign_transaction(tx)
    tx_hash = w3.eth.send_raw_transaction(signed.raw_transaction)
    w3.eth.wait_for_transaction_receipt(tx_hash, timeout=30)

    return tx_hash.hex()


# --- Main loop ---

async def run():
    # Login to X
    if HTTP_PROXY:
        logger.info(f"Using proxy: {HTTP_PROXY}")
    client = TwikitClient("en-US", proxy=HTTP_PROXY)

    if COOKIES_FILE.exists():
        import json
        with open(COOKIES_FILE) as f:
            raw = json.load(f)
        # Convert browser export format (array of objects) to twikit format (dict)
        if isinstance(raw, list):
            cookies = {c["name"]: c["value"] for c in raw if "name" in c and "value" in c}
            client.set_cookies(cookies)
        else:
            client.load_cookies(str(COOKIES_FILE))
        logger.info("Loaded cookies from file")
    else:
        logger.info(f"Logging in as @{X_USERNAME}...")
        await client.login(
            auth_info_1=X_USERNAME,
            auth_info_2=X_EMAIL,
            password=X_PASSWORD,
            cookies_file=str(COOKIES_FILE),
        )
        logger.info("Logged in, cookies saved")

    logger.info(f"Polling for mentions of @{X_MONITOR_ACCOUNT} every {POLL_INTERVAL}s")

    while True:
        try:
            tweets = await client.search_tweet(f"@{X_MONITOR_ACCOUNT}", "Latest", count=20)
            logger.info(f"Found {len(tweets)} tweets")

            for tweet in tweets:
                tweet_id = tweet.id
                user = tweet.user
                x_user_id = user.id
                x_username = user.screen_name
                tweet_text = tweet.text or ""

                if is_tweet_claimed(tweet_id):
                    continue

                # Extract wallet
                match = ETH_ADDRESS_RE.search(tweet_text)
                if not match:
                    logger.info(f"Tweet {tweet_id} by @{x_username}: no wallet, skipping")
                    continue

                wallet = match.group(0)
                logger.info(f"Tweet {tweet_id} by @{x_username}: wallet {wallet}")

                # One wallet per user
                if has_user_claimed_with_different_wallet(x_user_id, wallet):
                    logger.info(f"@{x_username} already claimed with different wallet, skipping")
                    continue

                # Mint
                try:
                    tx_hash = mint_token(wallet)
                    save_claim(tweet_id, x_user_id, x_username, wallet, tx_hash)
                    logger.info(f"Minted 1 X to {wallet} for @{x_username}, tx: {tx_hash}")
                except Exception as e:
                    logger.error(f"Mint failed for @{x_username} ({wallet}): {e}")

            # Re-save cookies periodically
            client.save_cookies(str(COOKIES_FILE))

        except Exception as e:
            logger.error(f"Error in poll loop: {e}")

        await asyncio.sleep(POLL_INTERVAL)


if __name__ == "__main__":
    logger.info("X Airdrop Bot starting...")
    asyncio.run(run())
