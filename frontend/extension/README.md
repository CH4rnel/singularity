# Cyberia Wallet — browser extension

A Manifest V3 wallet for Chrome, Brave, Edge and Firefox 128+. It holds its own
encrypted vault, injects an EIP-1193 provider into the sites you grant it, and
signs transactions and messages for them.

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
npm run build        # dist/ (Chromium) and dist-firefox/ (Gecko)
npm run zip          # both zips, the files /download hands out
```

## Two builds, one source

The engines disagree about exactly three things, and `manifest.mjs` plus one
esbuild `define` is the whole of it:

| | Chromium | Firefox |
|---|---|---|
| background | `service_worker` | `scripts` + `"type": "module"` (event page) |
| namespace | `chrome.*` | `browser.*` — promises, which is what this code awaits |
| identity | id from the key | `browser_specific_settings.gecko.id`, needed to sign |

Everything else — vault, provider, popup, tests — is the same bytes. The source
carries no `if (firefox)` branch except where the two genuinely offer different
capabilities, which is the relay below.

## Installing a build

```text
Chrome · Brave · Edge   chrome://extensions → Developer mode → Load unpacked → dist/
Firefox 128+            about:debugging#/runtime/this-firefox → Load Temporary
                        Add-on → dist-firefox/manifest.json
```

Nothing is in a web store yet, so a release is a zip. On Chromium an unpacked
extension stays installed; **Firefox drops an unsigned add-on when it closes**,
which is a real limitation and not a bug — see _Signing for Firefox_ below.
During development point the browser at the unpacked directory: `npm run build`
rewrites both and the extension reloads with one click.

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

This is the one place where the browser you use changes what the wallet can
honestly offer, so the popup says which one you are getting:

- **Firefox** has `proxy.onRequest`, which asks the extension about each request
  individually. So the default is what the design wanted: the wallet's own RPC,
  token-index and price traffic goes through the relay, and the rest of the
  browser is untouched. A second toggle extends it to every tab if you want that.
- **Chromium** has only `proxy.settings`, which is one setting for the *entire
  browser*. There is no per-extension route in MV3, so turning the relay on
  routes every tab — and the popup carries that sentence permanently instead of
  implying a private tunnel.

Four routes — direct, a SOCKS5 daemon you name, Tor (`127.0.0.1:9050`) and I2P
(`127.0.0.1:4444`). Nothing is bundled and nothing is started; the extension only
points at a daemon that is already running. Either engine fails closed: when the
daemon stops answering, requests fail instead of falling back to your own line.
Localhost always bypasses, so a relay never hides a node on this machine, and on
Firefox names are resolved through the relay (`proxyDNS`) — a DNS lookup that
leaves directly announces every host the wallet talks to.

`proxy` and `privacy` are **optional permissions**, asked for in the click that
turns the relay on and never at install time. WebRTC is closed with
`webRTCIPHandlingPolicy` on Chromium and `peerConnectionEnabled` on Firefox,
which is the same question in the two dialects.

## Layout

```text
manifest.json          MV3: no content_scripts, narrow host_permissions
manifest.mjs           the Gecko dialect of it, and the two build targets
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
(and from there into both manifests), packs `Cyberia-extension.zip` and
`Cyberia-extension-firefox.zip`, and attaches them to the GitHub release that
https://cyberia.church/download reads. The names carry no version, so
`/releases/latest/download/Cyberia-extension.zip` and
`https://cyberia.church/download/extension` are permanent addresses. Renaming one
means editing `manifest.mjs`, the workflow **and**
`backend/laravel/config/downloads.php` together.

The zip is written without timestamps, so two builds of the same source are the
same bytes and the published checksum means something.

## Signing for Firefox

Release Firefox installs an add-on permanently only if Mozilla signed it. That
does **not** require a public listing: an *unlisted* submission to AMO returns a
signed `.xpi` that installs from anywhere.

```bash
npm run build
npx web-ext sign --source-dir=dist-firefox --channel=unlisted \
    --api-key="$AMO_JWT_ISSUER" --api-secret="$AMO_JWT_SECRET"
```

The credentials come from https://addons.mozilla.org/developers/addon/api/key/
and belong in the environment, never in this repository. The signed file keeps
`wallet@cyberia.church` as its id, so a signed build updates an unsigned one that
was loaded temporarily rather than appearing beside it.

Until that runs, Firefox users load the add-on temporarily and it goes away with
the browser — the vault survives, because it is keyed to that id and stays in the
profile, but the add-on has to be loaded again.

`npx web-ext lint --source-dir=dist-firefox --self-hosted` runs the same
addons-linter AMO does. It reports zero errors; the two `innerHTML` warnings are
the popup's own escaped rendering (`esc()` on every interpolation), which an
unlisted signature does not review.

## Known limits

- **Firefox 128 or newer.** `world: "MAIN"` for a registered content script
  landed there, and without it the provider cannot reach the page at all — so
  `strict_min_version` refuses an older Firefox instead of installing a wallet
  that silently never connects.
- **An unsigned Firefox build is temporary** (above). Chromium keeps an unpacked
  extension across restarts; Gecko does not.
- **No hardware wallets, no WalletConnect, no token approvals screen.** The
  extension signs what a dapp asks for; managing existing allowances is the
  site's job.
- **A pending request dies with the service worker.** The port from the page
  keeps the worker alive while it waits, and if the browser tears everything down
  anyway the page gets a rejection rather than a promise that never settles.
