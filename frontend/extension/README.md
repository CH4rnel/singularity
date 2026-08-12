# Cyberia Wallet — browser extension

A Manifest V3 wallet for Chrome, Brave and Edge. It holds its own encrypted
vault, injects an EIP-1193 provider into the sites you grant it, and signs
transactions and messages for them.

**This is the one build that is not a shell around cyberia.church.** The desktop
and mobile apps render the live site, so a deploy updates them; an extension
cannot do that — a dapp expects `window.ethereum` in its own page, and the code
that answers it has to be installed. The seed phrase is the bridge: import the
phrase you already use in the wallet on the site and both surfaces derive the
same accounts, because both derive `m/44'/60'/0'/0/{index}`.

EVM only, on purpose. A dapp speaks EIP-1193, and EIP-1193 is EVM; Solana,
Monero and the Bitcoin family live in the wallet on the site, where they have
screens instead of a provider.

## Commands

```bash
npm install
npm test             # the rules worth pinning: derivation, permissions, calldata, vault
npm run build        # dist/ — load this with "Load unpacked"
npm run zip          # Cyberia-extension.zip, the file /download hands out
```

## Installing a build

```text
chrome://extensions → Developer mode → Load unpacked → select dist/
```

Nothing is published to the Chrome Web Store yet, so a release is a zip. Point
the browser at the unpacked directory during development — `npm run build`
rewrites `dist/` and the extension reloads with one click.

## What it does, and what it refuses to

- **The page never touches the vault.** A content script bridges the page to a
  service worker over a port; the sealed vault and the signer live in the worker.
  A malicious page can ask — it cannot read.
- **Permissions are per origin.** There is no `<all_urls>` content script.
  `src/background/injection.js` registers the provider at runtime for the origins
  you granted and nowhere else, so a random tab cannot fingerprint you by asking
  whether a wallet exists. Revoking an origin unregisters it on the next load and
  rejects anything it left waiting.
- **Every signature stops at a human.** `PASSTHROUGH_METHODS` is the complete
  list of calls that reach the chain without asking you, and a test asserts no
  signing method appears in it. Approvals open in their own window: a toolbar
  popup closes when you click the page behind it, and a prompt that vanishes
  mid-read teaches people to click before reading.
- **An unlimited approval is named as one.** `src/shared/tx.js` reads the
  calldata and says `UNLIMITED` where a dapp asked for an infinite allowance,
  instead of rendering a 78-digit number nobody parses.
- **`eth_sign` is not supported.** It signs 32 arbitrary bytes that can be a
  transaction hash; there is no preview that makes that safe.
- **No vendor server.** No analytics, no crash reporter, no remote config, no
  hosted token list. Balances and tokens come from the chain's own RPC and its
  keyless Blockscout index; USD quotes come from this project's own endpoint and
  a quote that cannot be read stays `null` and renders as a dash.
- **No fonts are fetched.** The stylesheet declares the same stack as the site
  (`Space Grotesk`, `IBM Plex Mono`) and falls back to what the machine has —
  asking a font CDN on every popup open would announce each time you looked at
  your balance.

## The relay

`chrome.proxy` gives an extension **one proxy setting for the whole browser**,
not a private route for its own requests. The relay screen says so rather than
implying a wallet-only tunnel: turning it on routes every tab, and the popup
carries that sentence permanently.

Four routes — direct, a SOCKS5 daemon you name, Tor (`127.0.0.1:9050`) and I2P
(`127.0.0.1:4444`). Nothing is bundled and nothing is started; the extension only
points at a daemon that is already running. `fixed_servers` fails closed: when
the daemon stops answering, requests fail instead of falling back to your own
line. Localhost always bypasses, so a relay never hides a node on this machine.

`proxy` and `privacy` are **optional permissions**, asked for in the click that
turns the relay on and never at install time.

## Layout

```text
manifest.json          MV3: no content_scripts, narrow host_permissions
build.mjs              esbuild bundle + static copy + a deterministic zip writer
src/shared/            pure: chains, origins/grants, formatting, calldata reading
src/background/        service worker: vault, keyring, rpc, permissions, requests, relay
src/content/bridge.js  isolated world; the only thing that may hold a port
src/inpage/provider.js page world; EIP-1193 + EIP-6963, holds nothing
src/popup/             the 348px popup: home, receive, send, accounts, sites, relay,
                       connect/sign approvals, locked
src/onboarding/        create or import a phrase, in a tab
```

The vault is AES-256-GCM under a PBKDF2-SHA-256 key (310k iterations), byte-for-
byte the format `backend/laravel/resources/js/lib/wallet/vault.ts` writes, so one
audit covers both. An unlocked session keeps the **key** in
`chrome.storage.session` — memory that survives the service worker being evicted
but not the browser closing — and the phrase is decrypted for the length of one
signature and dropped. Auto-lock is 15 minutes by default.

## Releases

The zip ships with the apps, from the same tag:

```bash
git tag app-v1.0.0 && git push origin app-v1.0.0
```

`.github/workflows/apps.yml` runs the tests, writes the tag into `package.json`
(and from there into the manifest), packs `Cyberia-extension.zip` and attaches it
to the GitHub release that https://cyberia.church/download reads. The file name
carries no version, so `/releases/latest/download/Cyberia-extension.zip` and
`https://cyberia.church/download/extension` are permanent addresses. Renaming it
means editing `build.mjs`, the workflow **and** `backend/laravel/config/downloads.php`
together.

The zip is written without timestamps, so two builds of the same source are the
same bytes and the published checksum means something.

## Known limits

- **Chromium only.** Firefox MV3 has no `background.service_worker` and requires
  signing to install a permanent extension; supporting it means a second
  manifest, not a flag.
- **No hardware wallets, no WalletConnect, no token approvals screen.** The
  extension signs what a dapp asks for; managing existing allowances is the
  site's job.
- **A pending request dies with the service worker.** The port from the page
  keeps the worker alive while it waits, and if the browser tears everything down
  anyway the page gets a rejection rather than a promise that never settles.
