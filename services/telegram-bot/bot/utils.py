"""Pure formatting / parsing helpers with no I/O."""
import re

from bot.config import MIN_REWARDS_INTERVAL_SECONDS, MAX_REWARDS_INTERVAL_SECONDS


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
def _fmt_usd(value: float) -> str:
    if value >= 1000:
        return f"${value:,.0f}"
    if value >= 1:
        return f"${value:,.2f}"
    return f"${value:.4f}"
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
def _format_window(seconds: float) -> str:
    seconds = int(seconds)
    if seconds >= 48 * 3600:
        return f"{round(seconds / 86400)}d"
    if seconds >= 3600:
        return f"{round(seconds / 3600)}h"
    return f"{max(1, seconds // 60)}m"
