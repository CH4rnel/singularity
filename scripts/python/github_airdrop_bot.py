import os
import re
import sys
import time
import logging
from pathlib import Path

import requests
from dotenv import load_dotenv
from web3 import Web3
from sqlalchemy import create_engine, text

load_dotenv(Path(__file__).parent / ".env")

# --- Config ---

DEPLOYER_PK = os.environ.get("DEPLOYER_PK")
if not DEPLOYER_PK:
    raise ValueError("DEPLOYER_PK not set")

GITHUB_REPO = os.environ.get("GITHUB_REPO", "cyberia-temple/singularity")
GITHUB_ORG = os.environ.get("GITHUB_ORG", "cyberia-temple")
GITHUB_TOKEN = os.environ.get("GITHUB_TOKEN")  # optional, raises rate limit

DB_PATH = os.environ.get("DB_PATH", "/home/lain/random/singularity/backend/laravel/database/database.sqlite")

GITHUB_TOKEN_ADDRESS = "0x5db6EEDa62D04c68332B537c18ec4dfa3cB9aadd"
TOKEN_ABI = [
    {"inputs": [{"name": "to", "type": "address"}, {"name": "amount", "type": "uint256"}], "name": "mint", "outputs": [], "stateMutability": "nonpayable", "type": "function"},
    {"inputs": [{"name": "account", "type": "address"}], "name": "balanceOf", "outputs": [{"name": "", "type": "uint256"}], "stateMutability": "view", "type": "function"},
]

RPC_URL = "http://195.166.164.94:8545"
CHAIN_ID = 49406
MINT_AMOUNT = Web3.to_wei(1, "ether")
POLL_INTERVAL = 300  # 5 minutes

# --- Logging ---

LOG_FILE = Path(__file__).parent / "github_bot.log"

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
        CREATE TABLE IF NOT EXISTS github_wallets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            github_username TEXT UNIQUE NOT NULL,
            wallet_address TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    """))
    conn.execute(text("""
        CREATE TABLE IF NOT EXISTS github_airdrop_claims (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            github_username TEXT NOT NULL,
            action_type TEXT NOT NULL,
            wallet_address TEXT NOT NULL,
            tx_hash TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(github_username, action_type)
        )
    """))
    conn.commit()

logger.info("Database ready")

# --- Web3 ---

w3 = Web3(Web3.HTTPProvider(RPC_URL))
account = w3.eth.account.from_key(DEPLOYER_PK)
contract = w3.eth.contract(address=Web3.to_checksum_address(GITHUB_TOKEN_ADDRESS), abi=TOKEN_ABI)

logger.info(f"Deployer: {account.address}")

# --- GitHub API ---

GH_HEADERS = {"Accept": "application/vnd.github.v3+json"}
if GITHUB_TOKEN:
    GH_HEADERS["Authorization"] = f"token {GITHUB_TOKEN}"


def get_all_pages(url: str) -> list[dict]:
    """Fetch all pages from a paginated GitHub API endpoint."""
    results = []
    page = 1
    while True:
        resp = requests.get(url, headers=GH_HEADERS, params={"per_page": 100, "page": page}, timeout=15)
        resp.raise_for_status()
        data = resp.json()
        if not data:
            break
        results.extend(data)
        page += 1
    return results


def get_stargazers() -> set[str]:
    users = get_all_pages(f"https://api.github.com/repos/{GITHUB_REPO}/stargazers")
    return {u["login"].lower() for u in users}


def get_followers() -> set[str]:
    users = get_all_pages(f"https://api.github.com/users/{GITHUB_ORG}/followers")
    return {u["login"].lower() for u in users}


# --- DB helpers ---

def get_wallet(github_username: str) -> str | None:
    with engine.connect() as conn:
        result = conn.execute(
            text("SELECT wallet_address FROM github_wallets WHERE github_username = :user"),
            {"user": github_username.lower()},
        ).fetchone()
    return result[0] if result else None


def register_wallet(github_username: str, wallet: str):
    with engine.connect() as conn:
        conn.execute(
            text("""
                INSERT INTO github_wallets (github_username, wallet_address)
                VALUES (:user, :wallet)
                ON CONFLICT(github_username) DO UPDATE SET wallet_address = :wallet
            """),
            {"user": github_username.lower(), "wallet": wallet},
        )
        conn.commit()


def is_claimed(github_username: str, action_type: str) -> bool:
    with engine.connect() as conn:
        result = conn.execute(
            text("SELECT 1 FROM github_airdrop_claims WHERE github_username = :user AND action_type = :action"),
            {"user": github_username.lower(), "action": action_type},
        ).fetchone()
    return result is not None


def save_claim(github_username: str, action_type: str, wallet: str, tx_hash: str):
    with engine.connect() as conn:
        conn.execute(
            text("""
                INSERT OR IGNORE INTO github_airdrop_claims (github_username, action_type, wallet_address, tx_hash)
                VALUES (:user, :action, :wallet, :tx_hash)
            """),
            {"user": github_username.lower(), "action": action_type, "wallet": wallet, "tx_hash": tx_hash},
        )
        conn.commit()


# --- Mint ---

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

def run():
    logger.info(f"Monitoring repo: {GITHUB_REPO}, org: {GITHUB_ORG}")
    logger.info(f"Poll interval: {POLL_INTERVAL}s")

    while True:
        try:
            # Fetch stargazers and followers
            stargazers = get_stargazers()
            followers = get_followers()
            logger.info(f"Stargazers: {len(stargazers)}, Followers: {len(followers)}")

            # Process stargazers
            for username in stargazers:
                wallet = get_wallet(username)
                if not wallet:
                    continue
                if is_claimed(username, "star"):
                    continue

                try:
                    tx_hash = mint_token(wallet)
                    save_claim(username, "star", wallet, tx_hash)
                    logger.info(f"Minted 1 GITHUB to {wallet} for @{username} (star), tx: {tx_hash}")
                except Exception as e:
                    logger.error(f"Mint failed for @{username} (star): {e}")

            # Process followers
            for username in followers:
                wallet = get_wallet(username)
                if not wallet:
                    continue
                if is_claimed(username, "follow"):
                    continue

                try:
                    tx_hash = mint_token(wallet)
                    save_claim(username, "follow", wallet, tx_hash)
                    logger.info(f"Minted 1 GITHUB to {wallet} for @{username} (follow), tx: {tx_hash}")
                except Exception as e:
                    logger.error(f"Mint failed for @{username} (follow): {e}")

        except Exception as e:
            logger.error(f"Error in poll loop: {e}")

        time.sleep(POLL_INTERVAL)


if __name__ == "__main__":
    logger.info("GitHub Airdrop Bot starting...")
    run()
