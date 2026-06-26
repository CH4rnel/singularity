#!/usr/bin/env python3
"""Compatibility shim — the Telegram airdrop bot moved to services/telegram-bot/.

This keeps the historical prod launch working unchanged. Both
    cd scripts/python && python telegram_airdrop_bot.py [--snapshot-once]
    python -m scripts.python.telegram_airdrop_bot
delegate to the relocated package (`bot.app.main`), forwarding sys.argv.
"""
import sys
from pathlib import Path

# services/telegram-bot/ (the package import root) relative to this file:
# parents[2] == repo root.
sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "services" / "telegram-bot"))

from bot.app import main  # noqa: E402

if __name__ == "__main__":
    main()
