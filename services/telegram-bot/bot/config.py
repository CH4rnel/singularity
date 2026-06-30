"""Configuration: environment variables, contract ABIs/topics, logging.

All runtime tunables are read from the environment here so the rest of the
package can `from bot.config import NAME`. The .env file is discovered from a
few candidate locations so both the clean `python -m bot` launch and the legacy
scripts/python shim find the right one.
"""
import os
import sys
import logging
from pathlib import Path

from dotenv import load_dotenv
from web3 import Web3

_PKG_DIR = Path(__file__).resolve().parent           # services/telegram-bot/bot
_SVC_DIR = _PKG_DIR.parent                            # services/telegram-bot

# .env discovery: first existing wins. BOT_ENV_FILE overrides everything; the
# legacy scripts/python/.env keeps the prod shim working unchanged.
for _candidate in (
    os.environ.get("BOT_ENV_FILE"),
    _SVC_DIR / ".env",
    _PKG_DIR / ".env",
    _SVC_DIR.parents[1] / "scripts" / "python" / ".env",
):
    if _candidate and Path(_candidate).is_file():
        load_dotenv(_candidate)
        break


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

# CYBER.sol -> native CYBER converter (CyberSolSwap) announcer. The fixed-rate
# redeemer emits Swapped(user, amountIn, amountOut) on every conversion at the
# hard 1000 CYBER.sol : 1 CYBER ratio. Default is the deployed redeemer; blank
# disables the loop. The CYBER.sol input token is CYBER_CA_EVM (both 18 decimals).
CYBERSOL_SWAP_ADDRESS = (os.environ.get(
    "CYBERSOL_SWAP_ADDRESS", "0x69b1614B088F5670E49bcC6fE33F28F2544F7415"
) or "").strip()
CYBERSOL_SWAP_ANNOUNCE_CHAT = os.environ.get("CYBERSOL_SWAP_ANNOUNCE_CHAT", BRIDGE_ANNOUNCE_CHAT)
CYBERSOL_SWAP_POLL_SECONDS = int(os.environ.get("CYBERSOL_SWAP_POLL_SECONDS", str(SWAP_POLL_SECONDS)))
CYBERSOL_SWAP_MAX_BLOCK_RANGE = int(os.environ.get("CYBERSOL_SWAP_MAX_BLOCK_RANGE", str(SWAP_MAX_BLOCK_RANGE)))

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

# Ecosystem links surfaced as inline URL buttons under /start and /help.
# Defaults derive the marketplace/pixel-battle pages from the project website.
SWAP_URL = os.environ.get("SWAP_URL", "https://swap.cyberia.church")
NFT_MARKET_URL = os.environ.get("NFT_MARKET_URL", PROJECT_WEBSITE_URL.rstrip("/") + "/market")
PIXEL_BATTLE_URL = os.environ.get("PIXEL_BATTLE_URL", PROJECT_WEBSITE_URL.rstrip("/") + "/pixels")

# Contract addresses surfaced by "ca". CYBER.sol is the community pump.fun token
# (also gates the whales chat); its EVM counterpart is the bridged CYBER.sol on
# Cyberia (matches PRICE_RELAY_TOKENS). Blank values are simply omitted.
CYBER_CA_SOLANA = (os.environ.get("CYBER_CA_SOLANA", CYBER_SOL_MINT) or "").strip()
CYBER_CA_EVM = (os.environ.get(
    "CYBER_CA_EVM", "0x7DcDa19Cf984ca708E5fA228AC148e7d82D508BA"
) or "").strip()

# --- NFT from channel posts ---------------------------------------------------
# When the bot is an admin of a PUBLIC channel it receives that channel's posts;
# each new post is minted into the shared CyberiaNFT collection with ERC-721 +
# IPFS metadata so it shows up on the Cyberia NFT marketplace. The NFT is owned
# by the deployer/treasury wallet (mint -> msg.sender) but the marketplace lists
# the whole collection by nextId, so it is visible regardless.
CYBERIA_NFT_ADDRESS = (os.environ.get(
    "CYBERIA_NFT_ADDRESS", "0x546462FAbf30734E63b64f32B30EC8ADD9B6EBa7"
) or "").strip()

# Master switch. Defaults on once both the NFT address and a deployer key exist;
# set NFT_FROM_POSTS=0 to disable.
NFT_FROM_POSTS = (os.environ.get("NFT_FROM_POSTS", "1").strip().lower()
                  not in ("0", "false", "no", "off")) and bool(CYBERIA_NFT_ADDRESS)

# Pin metadata to IPFS but skip the on-chain mint (no gas) — for testing.
NFT_FROM_POSTS_DRYRUN = os.environ.get(
    "NFT_FROM_POSTS_DRYRUN", "0"
).strip().lower() in ("1", "true", "yes", "on")

# Local Kubo HTTP API used to pin images + metadata JSON. Same env name and
# default as the Laravel NFTController so prod config carries over.
IPFS_API_URL = (os.environ.get("IPFS_API_URL", "http://127.0.0.1:5001") or "").rstrip("/")

# Cap the NFT description (post text) length kept in metadata.
NFT_MAX_DESC_CHARS = int(os.environ.get("NFT_MAX_DESC_CHARS", "1500"))

# Public base for the post link surfaced as the NFT's external_url.
TELEGRAM_POST_BASE = os.environ.get("TELEGRAM_POST_BASE", "https://t.me").rstrip("/")

CYBERIA_NFT_ABI = [
    {
        "inputs": [{"name": "uri", "type": "string"}],
        "name": "mint",
        "outputs": [{"name": "tokenId", "type": "uint256"}],
        "stateMutability": "nonpayable",
        "type": "function",
    },
    {
        "inputs": [],
        "name": "nextId",
        "outputs": [{"name": "", "type": "uint256"}],
        "stateMutability": "view",
        "type": "function",
    },
    {
        "inputs": [
            {"name": "from", "type": "address"},
            {"name": "to", "type": "address"},
            {"name": "tokenId", "type": "uint256"},
        ],
        "name": "safeTransferFrom",
        "outputs": [],
        "stateMutability": "nonpayable",
        "type": "function",
    },
    {
        "anonymous": False,
        "inputs": [
            {"indexed": True, "name": "tokenId", "type": "uint256"},
            {"indexed": True, "name": "creator", "type": "address"},
            {"indexed": False, "name": "uri", "type": "string"},
        ],
        "name": "Minted",
        "type": "event",
    },
]

MINTED_TOPIC = Web3.keccak(text="Minted(uint256,address,string)").hex()


MARKET_SNAPSHOT_SECONDS = int(os.environ.get("MARKET_SNAPSHOT_SECONDS", "300"))

# --- Logging ---------------------------------------------------------------
LOG_FILE = Path(os.environ.get("BOT_LOG_FILE", str(_SVC_DIR / "bot.log")))


class SecurityFilter(logging.Filter):
    """Redact the bot token from any log record."""

    def filter(self, record):
        if TELEGRAM_BOT_TOKEN and isinstance(record.msg, str):
            record.msg = record.msg.replace(TELEGRAM_BOT_TOKEN, "***")
        return True


_log_handlers = [logging.FileHandler(LOG_FILE), logging.StreamHandler(sys.stdout)]
for _h in _log_handlers:
    _h.addFilter(SecurityFilter())

logging.basicConfig(
    format="%(asctime)s - %(name)s - %(levelname)s - %(message)s",
    level=logging.INFO,
    handlers=_log_handlers,
)
logging.getLogger("httpx").setLevel(logging.WARNING)
logging.getLogger("telegram").setLevel(logging.WARNING)
