---
name: blockscout-explorer-nginx-static-upstream
description: Why recreating the Blockscout frontend container alone 502s the explorer
metadata:
  type: project
---

In `services/blockscout/docker-compose/proxy/`, the explorer server blocks proxy to the frontend/backend with a **static** `proxy_pass ${FRONT_PROXY_PASS}` (= `http://frontend:3000`) — a literal hostname with no `resolver` directive. nginx resolves `frontend` to a container IP once at startup and caches it forever. When the frontend container is recreated (e.g. `docker compose up -d --force-recreate frontend`) it gets a **new IP**, nginx keeps the old one → 502, "blockscout ломается".

The `cyberia_church` block in `default.conf.template` does it correctly (`resolver 127.0.0.11; set $upstream ...; proxy_pass http://$upstream;`) — the explorer blocks do not.

Workaround: recreate `proxy` together with `frontend` (`docker compose up -d --force-recreate frontend proxy`) so nginx re-resolves. Durable fix: add a runtime `resolver 127.0.0.11` + variable in `proxy_pass` to the explorer blocks. Related: [[blockscout-logo-external-asset-download]].
