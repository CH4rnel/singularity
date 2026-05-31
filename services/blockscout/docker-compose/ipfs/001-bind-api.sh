#!/bin/sh
set -ex
# Kubo binds its API to 127.0.0.1 inside the container by default, which is
# unreachable from sibling containers (e.g. the Laravel cyberia_church app).
# Rebind to all interfaces; the API port is only published to the host on
# 127.0.0.1, so it stays private to this machine and the compose network.
ipfs config Addresses.API /ip4/0.0.0.0/tcp/5001
ipfs config Addresses.Gateway /ip4/0.0.0.0/tcp/8080
