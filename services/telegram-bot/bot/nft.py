"""Mint channel posts into the shared CyberiaNFT collection.

When the bot is an admin of a public channel it receives that channel's posts as
`channel_post` updates. Each new post is turned into an NFT: the image (if any)
and an ERC-721 metadata JSON are pinned to IPFS, then `CyberiaNFT.mint(uri)` is
signed with DEPLOYER_PK. The metadata shape matches the Laravel NFTController so
the token renders on the marketplace (resources/js/pages/Market.vue), which
lists the whole collection by nextId.
"""
import json
import uuid
import asyncio
import logging
import urllib.request
from datetime import timezone

from web3 import Web3

from telegram import Update
from telegram.ext import ContextTypes

from bot.config import (
    CYBERIA_NFT_ADDRESS, CYBERIA_NFT_ABI, MINTED_TOPIC,
    NFT_FROM_POSTS, NFT_FROM_POSTS_DRYRUN,
    IPFS_API_URL, NFT_MAX_DESC_CHARS, TELEGRAM_POST_BASE,
    RPC_URL, CHAIN_ID, DEPLOYER_PK,
)
from bot.db import _post_nft_seen, _record_post_nft

logger = logging.getLogger(__name__)

# (chat_id, message_id) and (chat_id, media_group_id) claimed by an in-flight
# mint. Belt-and-suspenders against album items racing past the DB dedup before
# the first item's row is written.
_inflight: set = set()


# --------------------------------------------------------------------------- #
# IPFS pinning (mirrors NFTController::pinToIpfs)                              #
# --------------------------------------------------------------------------- #
def _pin_bytes_to_ipfs(content: bytes, filename: str, mime: str) -> str:
    """Pin raw bytes to the local Kubo node and return `ipfs://CID`. Blocking —
    call through asyncio.to_thread from the event loop."""
    endpoint = f"{IPFS_API_URL}/api/v0/add?pin=true&cid-version=1"
    boundary = uuid.uuid4().hex
    pre = (
        f"--{boundary}\r\n"
        f'Content-Disposition: form-data; name="file"; filename="{filename}"\r\n'
        f"Content-Type: {mime}\r\n\r\n"
    ).encode()
    post = f"\r\n--{boundary}--\r\n".encode()
    body = pre + content + post
    req = urllib.request.Request(
        endpoint,
        data=body,
        headers={"Content-Type": f"multipart/form-data; boundary={boundary}"},
        method="POST",
    )
    with urllib.request.urlopen(req, timeout=60) as resp:
        raw = resp.read().decode()
    return _parse_ipfs_add(raw)


def _parse_ipfs_add(raw: str) -> str:
    """Kubo streams one JSON object per pinned file; the last line is the root.
    Returns `ipfs://<Hash>`."""
    lines = [ln for ln in raw.strip().splitlines() if ln.strip()]
    if not lines:
        raise RuntimeError("Kubo add returned an empty body")
    decoded = json.loads(lines[-1])
    cid = decoded.get("Hash")
    if not cid:
        raise RuntimeError("Kubo add returned no Hash")
    return f"ipfs://{cid}"


def _pin_json_to_ipfs(obj: dict) -> str:
    body = json.dumps(obj, ensure_ascii=False, separators=(",", ":")).encode()
    return _pin_bytes_to_ipfs(body, "metadata.json", "application/json")


# --------------------------------------------------------------------------- #
# Metadata + mint                                                             #
# --------------------------------------------------------------------------- #
def _build_post_metadata(chat, message, image_uri: str | None, text_body: str) -> dict:
    """ERC-721 metadata for one channel post, in the shape Market.vue renders."""
    username = chat.username
    title = chat.title or (f"@{username}" if username else "channel")
    description = text_body[:NFT_MAX_DESC_CHARS]

    metadata: dict = {
        "name": f"{title} #{message.message_id}",
        "description": description,
    }
    if image_uri:
        metadata["image"] = image_uri
    if username:
        metadata["external_url"] = f"{TELEGRAM_POST_BASE}/{username}/{message.message_id}"

    attributes = []
    if username:
        attributes.append({"trait_type": "Channel", "value": f"@{username}"})
    if message.date is not None:
        posted = message.date.astimezone(timezone.utc).strftime("%Y-%m-%d %H:%M UTC")
        attributes.append({"trait_type": "Posted", "value": posted})
    if attributes:
        metadata["attributes"] = attributes
    return metadata


def _mint_nft(token_uri: str) -> tuple[int | None, str]:
    """Mint one CyberiaNFT with `token_uri`. Returns (token_id, tx_hash).
    Blocking — call through asyncio.to_thread."""
    w3 = Web3(Web3.HTTPProvider(RPC_URL))
    acct = w3.eth.account.from_key(DEPLOYER_PK)
    nft = w3.eth.contract(
        address=Web3.to_checksum_address(CYBERIA_NFT_ADDRESS), abi=CYBERIA_NFT_ABI
    )

    nonce = w3.eth.get_transaction_count(acct.address, "pending")
    try:
        estimated = nft.functions.mint(token_uri).estimate_gas({"from": acct.address})
    except Exception as est_err:
        logger.warning(f"nft: estimate_gas failed, using 500000: {est_err}")
        estimated = 500_000
    gas_limit = int(estimated * 1.25) + 50_000

    tx = nft.functions.mint(token_uri).build_transaction({
        "from": acct.address,
        "nonce": nonce,
        "gas": gas_limit,
        "gasPrice": w3.eth.gas_price,
        "chainId": CHAIN_ID,
    })
    signed = acct.sign_transaction(tx)
    tx_hash = w3.eth.send_raw_transaction(signed.raw_transaction)
    receipt = w3.eth.wait_for_transaction_receipt(tx_hash, timeout=180)
    tx_hex = tx_hash.hex()
    if not tx_hex.startswith("0x"):
        tx_hex = "0x" + tx_hex

    if receipt.status != 1:
        raise RuntimeError(f"mint tx reverted: {tx_hex}")

    token_id = None
    for log in receipt.logs:
        if log.get("address", "").lower() != nft.address.lower():
            continue
        topics = log.get("topics") or []
        if not topics or topics[0].hex().lower() != MINTED_TOPIC.lower():
            continue
        try:
            event = nft.events.Minted().process_log(log)
            token_id = int(event["args"]["tokenId"])
        except Exception as e:
            logger.debug(f"nft: Minted decode failed: {e}")
        break
    if token_id is None:
        try:
            token_id = int(nft.functions.nextId().call())
        except Exception:
            token_id = None
    return token_id, tx_hex


# --------------------------------------------------------------------------- #
# Handler                                                                     #
# --------------------------------------------------------------------------- #
async def channel_post_handler(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Turn one new public-channel post into an NFT. Never raises into the loop."""
    if not NFT_FROM_POSTS:
        return
    msg = update.channel_post or update.effective_message
    chat = update.effective_chat
    if msg is None or chat is None or chat.type != "channel":
        return
    username = chat.username
    if not username:  # public channels only
        return
    if not DEPLOYER_PK and not NFT_FROM_POSTS_DRYRUN:
        return

    mgid = msg.media_group_id
    key = (chat.id, msg.message_id)
    gkey = (chat.id, mgid) if mgid else None

    try:
        if _post_nft_seen(chat.id, msg.message_id, mgid):
            return
    except Exception as e:
        logger.error(f"nft: dedup check failed: {e}")
        return
    if key in _inflight or (gkey is not None and gkey in _inflight):
        return

    _inflight.add(key)
    if gkey is not None:
        _inflight.add(gkey)
    try:
        text_body = (msg.text or msg.caption or "").strip()

        image_uri = None
        if msg.photo:
            try:
                photo = msg.photo[-1]  # largest size
                tg_file = await context.bot.get_file(photo.file_id)
                buf = await tg_file.download_as_bytearray()
                image_uri = await asyncio.to_thread(
                    _pin_bytes_to_ipfs, bytes(buf),
                    f"post_{msg.message_id}.jpg", "image/jpeg",
                )
            except Exception as e:
                logger.warning(
                    f"nft: image pin failed for @{username}/{msg.message_id}: {e}"
                )

        if not text_body and image_uri is None:
            logger.info(f"nft: skipping content-less post @{username}/{msg.message_id}")
            return

        metadata = _build_post_metadata(chat, msg, image_uri, text_body)
        post_link = f"{TELEGRAM_POST_BASE}/{username}/{msg.message_id}"
        try:
            token_uri = await asyncio.to_thread(_pin_json_to_ipfs, metadata)
        except Exception as e:
            # IPFS unreachable: fall back to the bare post link so an NFT still
            # gets created (CyberiaNFT accepts any non-empty string).
            token_uri = post_link
            logger.warning(
                f"nft: metadata pin failed, using link uri for @{username}/{msg.message_id}: {e}"
            )

        if NFT_FROM_POSTS_DRYRUN:
            logger.info(
                f"nft[dryrun]: @{username}/{msg.message_id} -> {token_uri}"
            )
            _record_post_nft(
                chat.id, msg.message_id, f"@{username}", mgid, None, token_uri, None
            )
            return

        token_id, tx_hash = await asyncio.to_thread(_mint_nft, token_uri)
        _record_post_nft(
            chat.id, msg.message_id, f"@{username}", mgid, token_id, token_uri, tx_hash
        )
        logger.info(
            f"nft: minted token_id={token_id} tx={tx_hash} "
            f"for @{username}/{msg.message_id} uri={token_uri}"
        )
    except Exception as e:
        logger.error(f"nft: failed to mint post @{username}/{msg.message_id}: {e}")
    finally:
        _inflight.discard(key)
        if gkey is not None:
            _inflight.discard(gkey)
