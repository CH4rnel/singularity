#!/bin/sh
set -ex
# Kubo binds its API and gateway to 127.0.0.1 inside the container by default,
# which Docker's published ports cannot reach (DNAT targets the container's
# eth0, not its loopback). Rebind both to all interfaces. They are only
# published to the host on 127.0.0.1 (see docker-compose.yml), so they stay
# private to this machine and the compose network.
#
# NOTE: these are the CONTAINER ports (API 5001, gateway 8080). The host-side
# 8881 in docker-compose is only where the gateway is *published* — the
# container still serves the gateway on 8080. Do not put 8881 here.
ipfs config Addresses.API /ip4/0.0.0.0/tcp/5001
ipfs config Addresses.Gateway /ip4/0.0.0.0/tcp/8080
