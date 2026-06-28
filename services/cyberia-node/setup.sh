#!/usr/bin/env bash
#
# Prepare the Cyberia SECOND node on 213.135.146.117.
#
# What this does (and ONLY this):
#   1. sanity-checks the genesis + bootnode address
#   2. checks it can reach the production validator's p2p port
#   3. generates THIS node's own fresh keys (unique libp2p identity)
#
# What this deliberately does NOT do:
#   - it does NOT start the node (run `docker compose up -d` when ready)
#   - it does NOT seal / validate
#   - it NEVER touches the production validator or its keys
#
# Re-running is safe: existing secrets are kept, never overwritten.
set -euo pipefail
cd "$(dirname "$0")"

IMAGE="0xpolygon/polygon-edge:latest"
BOOTNODE_IP="2.26.24.177"        # current production validator public IPv4
BOOTNODE_PORT="1337"
NODE_ID="16Uiu2HAmGYqgBskF5GLAgMbYaB1rDyYbVnEWN6qapYZGesUTm9go"
EXPECT_BOOT="/ip4/${BOOTNODE_IP}/tcp/${BOOTNODE_PORT}/p2p/${NODE_ID}"

echo "== 1/3  genesis sanity =="
test -f genesis.json || { echo "  ERROR: genesis.json missing"; exit 1; }
if grep -q "$EXPECT_BOOT" genesis.json; then
  echo "  bootnode OK: $EXPECT_BOOT"
else
  echo "  ERROR: genesis.json bootnode does not match expected validator address"
  echo "         expected: $EXPECT_BOOT"
  exit 1
fi

echo "== 2/3  reachability to the production validator p2p =="
if command -v nc >/dev/null 2>&1; then
  if nc -z -w5 "$BOOTNODE_IP" "$BOOTNODE_PORT"; then
    echo "  ${BOOTNODE_IP}:${BOOTNODE_PORT} reachable"
  else
    echo "  WARN: cannot reach ${BOOTNODE_IP}:${BOOTNODE_PORT} from here."
    echo "        Open outbound to it and inbound 1337 on THIS host before starting,"
    echo "        otherwise the node will have no peer to sync from."
  fi
else
  echo "  (nc not installed — skipping reachability probe)"
fi

echo "== 3/3  node identity (fresh, non-validator) =="
mkdir -p data
if [ -d data/libp2p ] || [ -f data/consensus/validator.key ]; then
  echo "  secrets already present — keeping them (no overwrite)"
else
  echo "  generating new keys for this node..."
  docker run --rm -v "$PWD/data:/data" "$IMAGE" secrets init --data-dir /data
fi

echo
echo "== this node's identity =="
docker run --rm -v "$PWD/data:/data" "$IMAGE" secrets output --data-dir /data || true

cat <<'EOF'

== prepared. NOT started. ==
When you are ready to bring the node up and let it sync:

  docker compose up -d
  docker compose logs -f cyberia-node     # watch height climb from 0

Verify sync progress against production:
  # this node:
  curl -s -X POST -H 'content-type: application/json' \
    --data '{"jsonrpc":"2.0","method":"eth_blockNumber","params":[],"id":1}' \
    http://127.0.0.1:8545
  # production (for comparison):
  curl -s -X POST -H 'content-type: application/json' \
    --data '{"jsonrpc":"2.0","method":"eth_blockNumber","params":[],"id":1}' \
    http://2.26.24.177:8545
EOF
