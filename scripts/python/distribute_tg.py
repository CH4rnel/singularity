import os
import logging
from datetime import datetime

from sqlalchemy import create_engine, text
from web3 import Web3

logging.basicConfig(
    format="%(asctime)s - %(name)s - %(levelname)s - %(message)s",
    level=logging.INFO,
)
logger = logging.getLogger(__name__)

DB_PATH = os.environ.get("DB_PATH", "/root/singularity/backend/laravel/database/database.sqlite")
DEPLOYER_PK = os.environ.get("DEPLOYER_PK")
if not DEPLOYER_PK:
    raise ValueError("DEPLOYER_PK not set")

TOKEN_ADDRESS = "0x3d32FE83ad0C1157fdDCA0a3280764c495cdAD6D"
RPC_URL = "https://rpc.cyberia.church"
CHAIN_ID = 49406

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
        "inputs": [{"name": "account", "type": "address"}],
        "name": "balanceOf",
        "outputs": [{"name": "", "type": "uint256"}],
        "stateMutability": "view",
        "type": "function",
    },
]

engine = create_engine(f"sqlite:///{DB_PATH}", connect_args={"check_same_thread": False})
w3 = Web3(Web3.HTTPProvider(RPC_URL))
account = w3.eth.account.from_key(DEPLOYER_PK)
contract = w3.eth.contract(address=Web3.to_checksum_address(TOKEN_ADDRESS), abi=TOKEN_ABI)


def ensure_schema():
    """Create the tg_wallets table if it does not exist."""
    with engine.begin() as conn:
        conn.execute(text("""
            CREATE TABLE IF NOT EXISTS tg_wallets (
                user_id  INTEGER PRIMARY KEY,
                address  TEXT NOT NULL,
                created_at TEXT DEFAULT (datetime('now'))
            )
        """))


def mint_and_distribute():
    logger.info(f"Starting distribution at {datetime.now()}")

    ensure_schema()

    with engine.connect() as conn:
        wallets = conn.execute(text("SELECT user_id, address FROM tg_wallets")).fetchall()

    if not wallets:
        logger.info("No wallets to distribute to")
        return

    logger.info(f"Distributing to {len(wallets)} wallets")

    nonce = w3.eth.get_transaction_count(account.address, "pending")
    success_count = 0
    failed_count = 0
    amount = 1 * 10**18

    for user_id, address in wallets:
        try:
            checksum_addr = Web3.to_checksum_address(address)
            # Preflight the call first so ABI/ownership mismatches fail before broadcast.
            mint_fn = contract.functions.mint(checksum_addr, amount)
            mint_fn.call({"from": account.address})
            estimated_gas = mint_fn.estimate_gas({"from": account.address})

            tx = mint_fn.build_transaction({
                "from": account.address,
                "nonce": nonce,
                "gas": max(int(estimated_gas * 1.2), 150000),
                "gasPrice": w3.eth.gas_price,
                "chainId": CHAIN_ID,
            })

            signed = account.sign_transaction(tx)
            tx_hash = w3.eth.send_raw_transaction(signed.raw_transaction)
            receipt = w3.eth.wait_for_transaction_receipt(tx_hash)

            if receipt.status != 1:
                failed_count += 1
                logger.error(
                    "Mint failed for %s (tx: %s, block: %s, status: %s)",
                    address,
                    tx_hash.hex(),
                    receipt.blockNumber,
                    receipt.status,
                )
            else:
                success_count += 1
                logger.info(
                    "Minted 1 TG to %s (tx: %s, block: %s)",
                    address,
                    tx_hash.hex(),
                    receipt.blockNumber,
                )
            nonce += 1

        except Exception as e:
            failed_count += 1
            logger.error(f"Failed to mint to {address}: {e}")
            nonce += 1

    logger.info(
        "Distribution complete: %d succeeded, %d failed",
        success_count,
        failed_count,
    )


if __name__ == "__main__":
    mint_and_distribute()
