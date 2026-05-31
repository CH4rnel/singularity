---
name: blockscout-logo-external-asset-download
description: How the Blockscout explorer logo/icon are actually set, and why the obvious env edits do nothing
metadata:
  type: project
---

The prod block explorer UI is the **prebuilt** `ghcr.io/blockscout/frontend:latest` image (a Next.js SPA), NOT the `services/blockscout/apps/block_scout_web` Elixir source in the repo. So:

- Editing logo/icon files under `services/blockscout/apps/...`, or the legacy `LOGO=` var in `envs/common-blockscout.env`, has **no effect** — that's the old block_scout_web UI, which isn't served. Setting `LOGO` to an existing asset (e.g. `dai_logo.svg`) and seeing no change is the proof you're on the new frontend.
- Frontend branding is controlled only by `NEXT_PUBLIC_*` in `envs/common-frontend.env`: `NEXT_PUBLIC_NETWORK_LOGO` / `_LOGO_DARK` (header logo), `NEXT_PUBLIC_NETWORK_ICON` / `_ICON_DARK` (collapsed-nav icon & favicons — separate from the logo).

**The key gotcha:** logo/icon are read via `getExternalAssetFilePath()`, not plain env. At container start the frontend **downloads** the URL in `NEXT_PUBLIC_NETWORK_LOGO` into `/app/public/assets/configs/network_logo.<ext>` and the config points at that local path (served same-origin, so CSP/own-origin tricks are irrelevant). If the download fails, the path is empty → it renders the sprite placeholder (`#networks/logo-placeholder`, which looks like the Blockscout logo) and the browser never even requests the logo. `window.__envs.NEXT_PUBLIC_NETWORK_LOGO` still shows the raw URL — that's the env, not the resolved asset.

So the URL **must be fetchable from inside the frontend container at startup**. Pointing it at the explorer's own domain (`https://explorer.cyberia.church/...`) **times out** — hairpin through the same nginx proxy. Use an independent host (e.g. `cyberia.church` if reachable from the container, or a GitHub raw / CDN URL).

Verify after a change: `docker compose exec frontend sh -c 'ls -la /app/public/assets/configs/'` (should contain `network_logo.*`) and `docker compose logs frontend | grep NETWORK_LOGO` (`[+]` success vs `[-] Failed to download`).

Applying env changes needs `docker compose up -d --force-recreate frontend proxy` (plain `restart` keeps old env; recreating the frontend also gives it a new IP, and the explorer nginx blocks use a **static** `proxy_pass ${FRONT_PROXY_PASS}` with no runtime `resolver`, so the proxy caches the stale IP and 502s unless recreated/restarted too). See [[blockscout-explorer-nginx-static-upstream]].
