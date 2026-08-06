# Cyberia Desktop

Electron shell that ships the Cyberia site (`backend/laravel`) as a desktop
application for Linux, Windows, and macOS.

The site is a server-driven Inertia app, so the shell does not bundle a copy of
it: it renders `https://cyberia.church` in a persistent session. That keeps the
app permanently in sync with production — a deploy is a shipped update — and
means the whole shell is three small files in `src/`.

**The app is the Cyberia wallet.** The window opens on `/wallet`, which renders
without the site header and footer inside a native shell and fills the frame
instead. The rest of Cyberia is a link away in the wallet's masthead and under
_File → Cyberia Site_; every other route keeps the normal site chrome. The
wallet needs no Cyberia account — the keys are generated and encrypted in the
browser — so the app is usable straight after install.

## Commands

```bash
npm install
npm start            # run the shell against production
npm test             # navigation rules (no display or Electron binary needed)
npm run dist:linux   # dist/Cyberia-<version>.AppImage + .deb
npm run dist:win     # dist/*.exe (NSIS installer + portable)
npm run dist:mac     # dist/*.dmg + .zip
npm run pack         # unpacked build, for a quick look at the bundle
```

`dist:win` builds from Linux only with Wine installed; `dist:mac` needs macOS.
Use the release workflow (`.github/workflows/apps.yml`) to produce all three from
one tag.

## Configuration

| Variable / flag   | Default                  | Purpose                              |
| ----------------- | ------------------------ | ------------------------------------ |
| `CYBERIA_APP_URL`  | `https://cyberia.church` | Site the window renders              |
| `--url=<url>`      | —                        | Same, as a command-line switch       |
| `CYBERIA_APP_PATH` | `/wallet`                | Route the window opens on            |
| `--path=<path>`    | —                        | Same, as a command-line switch       |
| `CYBERIA_PROXY`    | —                        | Proxy for the shell, wins over `*_proxy` |
| `--proxy=<url>`    | —                        | Same, as a command-line switch       |
| `--no-proxy`       | —                        | Ignore every proxy, connect directly |

```bash
CYBERIA_APP_URL=http://localhost:8000 npm start
npm start -- --url=http://localhost:8000 --path=/swap
```

Anything that is not `http`/`https` falls back to production, so a bad override
cannot point the app at a local file. `CYBERIA_APP_PATH` must be a same-origin
absolute path: a full URL or `//host` falls back to `/wallet` rather than moving
the whole app to another origin.

### Proxies

Chromium does **not** read `http_proxy`/`https_proxy` on a desktop that publishes
its own proxy settings — GNOME, Cinnamon and KDE hand it their system entry
instead. A stale entry there (a port nothing listens on) fails every request with
`ERR_PROXY_CONNECTION_FAILED`, even while `curl` tunnels happily through the proxy
exported in the shell.

So the shell resolves the proxy itself and pins it onto the session before the
first load:

1. `--no-proxy`, `--proxy=direct`, `CYBERIA_PROXY=direct` — explicit direct mode,
   the only way to override a broken system entry.
2. `--proxy=<url>` / `CYBERIA_PROXY=<url>` — one server for every scheme.
3. `https_proxy` / `http_proxy` / `all_proxy` (either case) — per scheme, with
   `no_proxy` as the bypass list on top of `localhost,127.0.0.1,::1,<local>`.
4. Nothing set — Chromium keeps using the system configuration.

Servers may be written as `host:port` (assumed `http`), `http://`, `https://`,
`socks4://` or `socks5://` (`socks://` is read as SOCKS5). Credentials in the URL
are dropped: Chromium proxy rules cannot carry them.

```bash
CYBERIA_PROXY=http://127.0.0.1:10808 npm start   # xray/v2ray on the usual port
npm start -- --no-proxy                          # straight out, ignore the desktop
```

The startup line `[cyberia] <url> via proxy <rules>` says what was picked, and the
offline page names the failing proxy when a load dies with a proxy error.

## What the shell adds over a browser tab

- **Persistent session** — cookies and local storage live in the `persist:cyberia`
  partition, so a login survives restarts.
- **Link discipline** — only `cyberia.church`, its subdomains, and the OAuth
  providers the site redirects through may take over the window. Everything else
  (`target="_blank"`, explorer links, `wc:`/`metamask:` wallet schemes) is handed
  to the system browser or the registered app.
- **Offline fallback** — `src/offline.html` replaces the network error page (in
  English or Russian, following the system language), names the failure —
  including the proxy that refused the connection — and reloads the site by
  itself once the connection returns.
- **`cyberia://` deep links** — `cyberia://profile?tab=xp` focuses the running
  window and navigates it. Registered by the installers; `npm start` registers
  the dev binary.
- **Remembered geometry** — window size and position are restored, and revalidated
  against the displays that currently exist.
- **User agent** — `CyberiaDesktop/<version>` is appended so the site can tell it
  is running inside the app (`resources/js/lib/native.ts`).

## Known limits

- Browser extensions do not exist in Electron, so there is no injected MetaMask.
  Connect wallets over WalletConnect (QR) — that path is already live on the site.
- Camera, microphone, geolocation, WebHID, and WebUSB are refused by
  `src/main.js`. Hardware wallets therefore need the WalletConnect route too.
- Installers are unsigned. macOS Gatekeeper and Windows SmartScreen will warn
  until a Developer ID / Authenticode certificate is wired into the build.
