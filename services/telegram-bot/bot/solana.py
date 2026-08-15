"""Solana JSON-RPC for the bot, with failover across endpoints.

One URL is one point of failure, and it failed: on 2026-08-14 the keyed
provider started answering `401 Unauthorized`, and both Solana surfaces went
quiet in the same minute — the pump.fun buy bot stopped posting buys, the
whales gate stopped re-reading balances — because they call the same URL. The
public cluster answers a *server* perfectly well (it is browsers it refuses,
over the Origin header), so it sits last in the list as the floor under every
key rather than as a plan.

Endpoints are tried in order per call. One that refuses — an HTTP status that
describes the endpoint rather than the request, a transport error, or a
rate-limit JSON-RPC code — is parked for SOLANA_RPC_COOLDOWN_SECONDS so a dead
key costs one call and not one call per request; when every endpoint is parked
the parks are dropped and the list is walked again, since trying is cheaper
than being certainly silent. A method error (bad params, an unknown signature)
is raised as it arrives: the next endpoint would answer exactly the same way.

Only hosts are ever logged. These URLs carry api keys.
"""
import json
import logging
import time
import urllib.error
import urllib.request
from urllib.parse import urlsplit

from bot.config import (
    SOLANA_RPC_URLS, SOLANA_RPC_TIMEOUT, SOLANA_RPC_COOLDOWN_SECONDS,
)

logger = logging.getLogger(__name__)

# Statuses that say "this endpoint, right now" rather than "this request": a
# revoked or exhausted key, a rate limit, a provider having a bad minute.
_RETRY_STATUS = {401, 402, 403, 408, 409, 425, 429, 500, 502, 503, 504}

# -32005 is the code providers use for their rate limit; the rest of the
# refusals arrive as prose ("max usage reached", "credits exhausted"), so the
# message is read too.
_RETRY_RPC_CODES = {-32005, -32029, -32097}
_RETRY_RPC_TEXT = (
    "rate limit", "too many requests", "max usage", "exceeded",
    "unauthorized", "forbidden", "credit", "quota",
)

# url → monotonic time it may be tried again.
_parked: dict[str, float] = {}


def endpoint_host(url: str) -> str:
    """Host only. An endpoint URL carries an api key and must never be logged."""
    try:
        return urlsplit(url).hostname or "?"
    except ValueError:
        return "?"


def endpoint_hosts() -> list[str]:
    """The configured chain, for a startup line that shows the fallback exists."""
    return [endpoint_host(url) for url in SOLANA_RPC_URLS]


def _live_endpoints() -> list[str]:
    now = time.monotonic()
    live = [url for url in SOLANA_RPC_URLS if _parked.get(url, 0.0) <= now]
    if live:
        return live
    # Everything is parked: either a real outage, or a cooldown that outlived
    # the problem. Forget the parks and walk the whole list again.
    _parked.clear()
    return list(SOLANA_RPC_URLS)


def _park(url: str, reason: str) -> None:
    _parked[url] = time.monotonic() + SOLANA_RPC_COOLDOWN_SECONDS
    logger.warning(
        "solana rpc: %s refused (%s); parked %.0fs, falling through",
        endpoint_host(url), reason, SOLANA_RPC_COOLDOWN_SECONDS,
    )


def _retryable_rpc_error(error) -> bool:
    if not isinstance(error, dict):
        return False
    code = error.get("code")
    if isinstance(code, int) and code in _RETRY_RPC_CODES:
        return True
    message = str(error.get("message") or "").lower()
    return any(token in message for token in _RETRY_RPC_TEXT)


def solana_rpc(method: str, params: list, timeout: float | None = None):
    """One JSON-RPC call, tried across the endpoint list. Blocking — call it
    through asyncio.to_thread. Raises the last failure if none answered."""
    payload = json.dumps(
        {"jsonrpc": "2.0", "id": 1, "method": method, "params": params}
    ).encode()
    wait = SOLANA_RPC_TIMEOUT if timeout is None else timeout
    last: Exception | None = None

    for url in _live_endpoints():
        try:
            request = urllib.request.Request(
                url, data=payload, headers={"Content-Type": "application/json"}
            )
            with urllib.request.urlopen(request, timeout=wait) as resp:
                data = json.loads(resp.read().decode())
        except urllib.error.HTTPError as e:
            last = e
            if e.code in _RETRY_STATUS:
                _park(url, f"HTTP {e.code}")
                continue
            raise
        except Exception as e:  # timeout, DNS, reset connection, non-JSON body
            last = e
            _park(url, f"{type(e).__name__}: {e}")
            continue

        error = data.get("error")
        if error:
            if _retryable_rpc_error(error):
                _park(url, f"rpc {error}")
                last = RuntimeError(f"Solana RPC error: {error}")
                continue
            raise RuntimeError(f"Solana RPC error: {error}")
        return data.get("result")

    raise last or RuntimeError("Solana RPC: no endpoint configured")
