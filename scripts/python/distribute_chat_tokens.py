"""
Distribute per-chat Telegram reward tokens.

For each row in `chat_tokens`:
  * skip if `token_address` is NULL (deployment in-flight)
  * skip if `last_payout_at + rewards_interval` is still in the future
  * otherwise mint `reward_amount` to every registered wallet in `tg_wallets`
    for members of that chat, then bump `last_payout_at`.

Intended to be run by cron / systemd timer as frequently as the shortest
desired rewards interval (e.g. every minute).
"""

import logging
import os
from datetime import datetime, timezone
from pathlib import Path

from dotenv import load_dotenv
from sqlalchemy import create_engine, text
from web3 import Web3

load_dotenv(Path(__file__).parent / ".env")

logging.basicConfig(
    format="%(asctime)s - %(name)s - %(levelname)s - %(message)s",
    level=logging.INFO,
)
logger = logging.getLogger(__name__)

DB_PATH = os.environ.get(
    "DB_PATH", "/home/lain/random/singularity/backend/laravel/database/database.sqlite"
)
DEPLOYER_PK = os.environ.get("DEPLOYER_PK")
if not DEPLOYER_PK:
    raise ValueError("DEPLOYER_PK not set")

RPC_URL = os.environ.get("RPC_URL", "https://rpc.cyberia.church")
CHAIN_ID = int(os.environ.get("CHAIN_ID", "49406"))

CHAT_TOKEN_ABI = [
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
]

engine = create_engine(f"sqlite:///{DB_PATH}", connect_args={"check_same_thread": False})
w3 = Web3(Web3.HTTPProvider(RPC_URL))
account = w3.eth.account.from_key(DEPLOYER_PK)


def _parse_ts(value):
    if value is None:
        return None
    # SQLite DATETIME default returns 'YYYY-MM-DD HH:MM:SS' in UTC.
    try:
        return datetime.fromisoformat(value).replace(tzinfo=timezone.utc)
    except ValueError:
        return None


def distribute():
    with engine.connect() as conn:
        tokens = conn.execute(
            text("""
                SELECT chat_id, name, symbol, token_address, rewards_interval,
                       reward_amount, last_payout_at
                FROM chat_tokens
                WHERE token_address IS NOT NULL
            """)
        ).fetchall()

    if not tokens:
        return

    now = datetime.now(timezone.utc)
    started = False

    for chat_id, name, symbol, token_address, interval, reward_amount, last_payout_at in tokens:
        last = _parse_ts(last_payout_at)
        if last is not None and (now - last).total_seconds() < interval:
            next_due = last.timestamp() + interval - now.timestamp()
            logger.debug(
                "Skipping chat %s (%s): next payout in %ds", chat_id, symbol, int(next_due)
            )
            continue

        # Two cohorts:
        #   wallets  -- chat members with a linked wallet (mint on-chain now)
        #   pending  -- chat members without a wallet (credit pending_rewards
        #               so they can claim everything as soon as they /set_wallet)
        # `chat_members` is maintained by the bot, `tg_wallets` is global.
        with engine.connect() as conn:
            wallets = conn.execute(
                text("""
                    SELECT cm.user_id, w.address
                    FROM chat_members cm
                    JOIN tg_wallets w ON w.user_id = cm.user_id
                    WHERE cm.chat_id = :c
                """),
                {"c": chat_id},
            ).fetchall()
            pending_members = conn.execute(
                text("""
                    SELECT cm.user_id
                    FROM chat_members cm
                    LEFT JOIN tg_wallets w ON w.user_id = cm.user_id
                    WHERE cm.chat_id = :c AND w.user_id IS NULL
                """),
                {"c": chat_id},
            ).fetchall()

        amount = int(reward_amount)

        # Credit pending balances first; this is cheap and unconditional, so it
        # happens even if the on-chain mint half later fails.
        if pending_members:
            with engine.begin() as conn:
                for (uid,) in pending_members:
                    conn.execute(
                        text("""
                            INSERT INTO pending_rewards (chat_id, user_id, amount, updated_at)
                            VALUES (:c, :u, :amt, datetime('now'))
                            ON CONFLICT(chat_id, user_id) DO UPDATE SET
                                amount = CAST(CAST(amount AS INTEGER) + :amt_int AS TEXT),
                                updated_at = datetime('now')
                        """),
                        {
                            "c": chat_id,
                            "u": uid,
                            "amt": str(amount),
                            "amt_int": amount,
                        },
                    )
            logger.info(
                "chat %s (%s): credited pending %s to %d wallet-less members",
                chat_id, symbol, reward_amount, len(pending_members),
            )

        if not wallets:
            # Bump timestamp so we do not re-evaluate every second; pending was
            # already credited above (if any).
            with engine.begin() as conn:
                conn.execute(
                    text("UPDATE chat_tokens SET last_payout_at = :t WHERE chat_id = :c"),
                    {"t": now.strftime("%Y-%m-%d %H:%M:%S"), "c": chat_id},
                )
            continue

        if not started:
            logger.info("Starting chat-token distribution")
            started = True

        logger.info(
            "chat %s (%s): minting to %d wallets (%s each)",
            chat_id, symbol, len(wallets), reward_amount,
        )

        contract = w3.eth.contract(
            address=Web3.to_checksum_address(token_address), abi=CHAT_TOKEN_ABI
        )
        # `amount` is already set above (same int(reward_amount) used for pending).
        nonce = w3.eth.get_transaction_count(account.address, "pending")
        success_count = 0
        failed_count = 0

        for user_id, address in wallets:
            try:
                to = Web3.to_checksum_address(address)
                mint_fn = contract.functions.mint(to, amount)
                mint_fn.call({"from": account.address})
                estimated_gas = mint_fn.estimate_gas({"from": account.address})
                tx = mint_fn.build_transaction({
                    "from": account.address,
                    "nonce": nonce,
                    "gas": max(int(estimated_gas * 1.2), 180_000),
                    "gasPrice": w3.eth.gas_price,
                    "chainId": CHAIN_ID,
                })
                signed = account.sign_transaction(tx)
                tx_hash = w3.eth.send_raw_transaction(signed.raw_transaction)
                receipt = w3.eth.wait_for_transaction_receipt(tx_hash, timeout=120)
                if receipt.status != 1:
                    failed_count += 1
                    logger.error(
                        "chat %s: mint failed %s (%s) -> %s tx=%s block=%s status=%s",
                        chat_id,
                        reward_amount,
                        symbol,
                        address,
                        tx_hash.hex(),
                        receipt.blockNumber,
                        receipt.status,
                    )
                else:
                    success_count += 1
                    logger.info(
                        "chat %s: minted %s (%s) -> %s tx=%s block=%s",
                        chat_id,
                        reward_amount,
                        symbol,
                        address,
                        tx_hash.hex(),
                        receipt.blockNumber,
                    )
                nonce += 1
            except Exception as e:
                failed_count += 1
                logger.error("chat %s: mint to %s failed: %s", chat_id, address, e)
                nonce += 1

        if success_count > 0:
            with engine.begin() as conn:
                conn.execute(
                    text("UPDATE chat_tokens SET last_payout_at = :t WHERE chat_id = :c"),
                    {"t": now.strftime("%Y-%m-%d %H:%M:%S"), "c": chat_id},
                )

        logger.info(
            "chat %s (%s): distribution complete, %d succeeded, %d failed",
            chat_id,
            symbol,
            success_count,
            failed_count,
        )

    if started:
        logger.info("Distribution complete")


if __name__ == "__main__":
    distribute()
