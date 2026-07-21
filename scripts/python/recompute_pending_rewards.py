"""
One-shot remediation for over-credited pending_rewards.

distribute_chat_tokens.py used to bump last_payout_at only after a successful
on-chain mint. During minter-EOA outages ("insufficient funds for gas") every
cron run (each minute) re-credited pending_rewards, so wallet-less members
accrued up to 1440 ticks/day instead of 24. This script clamps every
pending_rewards row down to its fair value:

    fair = floor((end - start) / rewards_interval) * reward_amount
    start = max(chat_members.first_seen, chat_tokens.created_at)
    end   = now (current member) or pending_rewards.updated_at (member gone)
    new   = min(current, fair)   -- never increases anyone

Small legitimate extras (thank-you rewards, which also land in pending) are
clipped too; at ~1 token each that is noise next to the thousands of bogus
ticks being removed.

Usage:
    python recompute_pending_rewards.py           # dry run, prints the plan
    python recompute_pending_rewards.py --apply   # write the clamped values
"""

import os
import sys
from datetime import datetime, timezone

from sqlalchemy import create_engine, text

DB_PATH = os.environ.get(
    "DB_PATH", "/root/singularity/backend/laravel/database/database.sqlite"
)

engine = create_engine(f"sqlite:///{DB_PATH}", connect_args={"check_same_thread": False})


def _parse_ts(value):
    if not value:
        return None
    try:
        return datetime.fromisoformat(str(value)).replace(tzinfo=timezone.utc)
    except ValueError:
        return None


def _amount_to_int(value) -> int:
    if value in (None, ""):
        return 0
    s = str(value)
    try:
        return int(s)
    except ValueError:
        try:
            return int(float(s))
        except (ValueError, OverflowError):
            return 0


def main():
    apply = "--apply" in sys.argv
    now = datetime.now(timezone.utc)

    with engine.connect() as conn:
        tokens = {
            row[0]: row
            for row in conn.execute(
                text("""
                    SELECT chat_id, symbol, created_at, rewards_interval, reward_amount
                    FROM chat_tokens
                """)
            ).fetchall()
        }
        pending = conn.execute(
            text("SELECT chat_id, user_id, amount, updated_at FROM pending_rewards")
        ).fetchall()
        members = {
            (row[0], row[1]): row[2]
            for row in conn.execute(
                text("SELECT chat_id, user_id, first_seen FROM chat_members")
            ).fetchall()
        }

    changes = []
    for chat_id, user_id, amount, updated_at in pending:
        token = tokens.get(chat_id)
        if token is None:
            continue
        _cid, symbol, created_at, interval, reward_amount = token
        created = _parse_ts(created_at) or now
        reward = _amount_to_int(reward_amount)
        interval = int(interval) or 3600

        first_seen = _parse_ts(members.get((chat_id, user_id)))
        if first_seen is not None:
            start = max(first_seen, created)
            end = now
        else:
            # Not a member anymore: accrual stopped at the last credit.
            start = created
            end = _parse_ts(updated_at) or now

        ticks = max(int((end - start).total_seconds() // interval), 0)
        fair = ticks * reward
        current = _amount_to_int(amount)
        if current > fair:
            changes.append((chat_id, symbol, user_id, current, fair))

    if not changes:
        print("Nothing to clamp: all pending rewards are at or below fair value.")
        return

    total_removed = 0
    for chat_id, symbol, user_id, current, fair in changes:
        total_removed += current - fair
        print(
            f"chat {chat_id} ({symbol}) user {user_id}: "
            f"{current / 1e18:g} -> {fair / 1e18:g}"
        )
    print(
        f"\n{len(changes)} rows to clamp, "
        f"{total_removed / 1e18:g} tokens removed in total."
    )

    if not apply:
        print("Dry run. Re-run with --apply to write.")
        return

    with engine.begin() as conn:
        for chat_id, _symbol, user_id, _current, fair in changes:
            conn.execute(
                text("""
                    UPDATE pending_rewards
                    SET amount = :a, updated_at = datetime('now')
                    WHERE chat_id = :c AND user_id = :u
                """),
                {"a": str(fair), "c": chat_id, "u": user_id},
            )
    print("Applied.")


if __name__ == "__main__":
    main()
