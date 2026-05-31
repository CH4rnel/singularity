---
name: ritual-dex-build-and-swap-runtime
description: DEX (frontend/ritual) build gotchas — yarn-only, @types/react pin, and ReactDOM.render requirement for /swap
metadata:
  type: project
---

`frontend/ritual` (the QuickSwap-fork DEX) has two fragile, non-obvious constraints:

**Build (must use yarn):** It is a **yarn** project (`yarn.lock` tracked). Running `npm install` re-resolves the tree, drifts `@types/react` to 18.3.x (and 19.x for the `*` range), and drops packages like `@web3modal/ui`. @types/react 18.3.x tightens JSX types and breaks `react-redux` v7 `<Provider>` and `react-router` v5 `<Switch>` (`TS2786 cannot be used as a JSX component`) → whole build fails. Fixes baked into package.json: `@types/react` pinned `18.2.79` / `@types/react-dom` `18.2.25` in devDeps + `resolutions`; `.env.production` sets `TSC_COMPILE_ON_ERROR=true` for type-only noise from `node_modules` (viem/ox/abitype). Install with `yarn install --ignore-engines` (node 24 vs required 18) then `npx patch-package`.

**Runtime (/swap):** `src/index.tsx` must mount with legacy `ReactDOM.render`, NOT React 18 `createRoot`. Under `createRoot` (concurrent mode) the lazy-loaded swap form + its swapIndex redirect effect render an **empty body** (header/gear show, no inputs/token selectors) when a wallet is connected. Symptom looked like a merge regression but was the entry point. See [[swap-master-merge-2026-05]].

Also: the bundled token list `public/ritual-tokens.json` uses relative logoURIs (`/CYBER.png`) that fail the strict @uniswap/token-lists schema; `src/utils/getTokenList.ts` skips validation for local (`/`-prefixed) list URLs so tokens load.
