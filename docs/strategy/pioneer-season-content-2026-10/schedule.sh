#!/usr/bin/env bash
# Queue the X calendar in Buffer through Lain OS backend's press:schedule.
# Run from lain-labs/backend. DRY RUN by default; pass --send to call the APIs.
# A post whose gate is not open (see README.md → Gates) must be commented out first.
set -euo pipefail
DIR="${PACK_DIR:-$(dirname "$0")}"
SEND="${1:-}"
q() {
  if grep -q "\[\[FILL" "$DIR/posts/$2.txt"; then echo "skip $2: has [[FILL]]"; return; fi
  php artisan press:schedule --buffer --file="$DIR/posts/$2.txt" --due="$1" $SEND
}

# Week 1
q 2026-10-21T14:00:00Z w1-01-asset-map   # gate G0
q 2026-10-22T14:00:00Z w1-02-wallet   # gate G0
q 2026-10-23T14:00:00Z w1-03-l1   # gate G0
q 2026-10-24T14:00:00Z w1-04-launchpad   # gate G0
q 2026-10-25T14:00:00Z w1-05-telegram   # gate G1
q 2026-10-26T14:00:00Z w1-06-open-source   # gate G0
q 2026-10-27T14:00:00Z w1-07-shiplog-1   # gate G0
# Week 2
q 2026-10-28T14:00:00Z w2-01-lock   # gate G2
q 2026-10-29T14:00:00Z w2-02-convert   # gate G3
q 2026-10-30T14:00:00Z w2-03-dao   # gate G0
q 2026-10-31T14:00:00Z w2-04-nocarrier   # gate G0
q 2026-11-01T14:00:00Z w2-05-season-rules   # gate G0
q 2026-11-02T14:00:00Z w2-06-domains   # gate G0
q 2026-11-03T14:00:00Z w2-07-shiplog-2   # gate G0
# Week 3
q 2026-11-04T14:00:00Z w3-01-season-open   # gate G4
q 2026-11-05T14:00:00Z w3-02-stocks   # gate G0
q 2026-11-06T14:00:00Z w3-03-mission-use   # gate G4
q 2026-11-07T14:00:00Z w3-04-lainos   # gate G0
q 2026-11-08T14:00:00Z w3-05-chaosfm   # gate G5
q 2026-11-09T14:00:00Z w3-06-bounties   # gate G6
q 2026-11-10T14:00:00Z w3-07-shiplog-3   # gate G0
# Week 4
q 2026-11-11T14:00:00Z w4-01-mission-return   # gate G4
q 2026-11-12T14:00:00Z w4-02-predictions   # gate G0
q 2026-11-13T14:00:00Z w4-03-chaos-mcp   # gate G7
q 2026-11-14T14:00:00Z w4-04-extension   # gate G8
q 2026-11-15T14:00:00Z w4-05-receipts   # gate G6
q 2026-11-16T14:00:00Z w4-06-board   # gate G0
q 2026-11-17T14:00:00Z w4-07-scoreboard   # gate G0
