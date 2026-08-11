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

## Releases

```bash
git tag app-v1.0.0 && git push origin app-v1.0.0
```

That builds all three platforms and publishes them as a **public GitHub release**
with `SHA256SUMS.txt` attached; https://cyberia.church/download reads that release
and hands each visitor the right file. A manual workflow run builds the same
installers but publishes nothing — workflow artifacts are only reachable by
signed-in GitHub users, which is not distribution.

The tag is the version: CI writes `1.0.0` into `package.json` before building, so
nothing here has to be committed per release.

File names carry **no version** — `Cyberia-Setup-x64.exe`,
`Cyberia-portable-x64.exe`, `Cyberia-mac-arm64.dmg`, `Cyberia-mac-x64.dmg`,
`Cyberia-linux-x86_64.AppImage`, `Cyberia-linux-amd64.deb`. That is what makes
`https://github.com/<owner>/<repo>/releases/latest/download/<name>` a permanent
address. Renaming one is a three-file change: `electron-builder.yml`, the workflow
notes, and `config/downloads.php` in the Laravel app.

## Configuration

| Variable / flag   | Default                  | Purpose                              |
| ----------------- | ------------------------ | ------------------------------------ |
| `CYBERIA_APP_URL`  | `https://cyberia.church` | Site the window renders              |
| `--url=<url>`      | —                        | Same, as a command-line switch       |
| `CYBERIA_APP_PATH` | `/wallet`                | Route the window opens on            |
| `--path=<path>`    | —                        | Same, as a command-line switch       |
| `CYBERIA_PROXY`    | —                        | Proxy for the shell, wins over `*_proxy` |
| `--proxy=<url>`    | —                        | Same, as a command-line switch, and pins this run |
| `--no-proxy`       | —                        | Ignore every proxy, connect directly |

The proxy is also settable inside the app (_File → Proxy…_), which is what a
packaged install has instead of a command line — see [Proxies](#proxies).

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

1. `--no-proxy`, `--proxy=direct`, `--proxy=<url>` — this run was launched with
   an instruction, and it holds for this run.
2. **The setting saved in the app** (_File → Proxy…_, or the button on the
   offline page). It outranks the environment because it is the last thing a
   human chose about this app, and for someone who starts Cyberia from an icon
   it is the only lever that exists.
3. `CYBERIA_PROXY=<url>` or `direct`, then `https_proxy` / `http_proxy` /
   `all_proxy` (either case), with `no_proxy` as the bypass list on top of
   `localhost,127.0.0.1,::1,<local>`.
4. Nothing set — Chromium keeps using the system configuration.

Servers may be written as `host:port` (assumed `http`), `http://`, `https://`,
`socks4://` or `socks5://` (`socks://` is read as SOCKS5). Credentials in the URL
are dropped: Chromium proxy rules cannot carry them.

```bash
CYBERIA_PROXY=http://127.0.0.1:10808 npm start   # xray/v2ray on the usual port
npm start -- --no-proxy                          # straight out, ignore the desktop
```

The startup line `[cyberia] <url> via proxy <rules> (<source>)` says what was
picked and where it came from.

#### The proxy window

`src/proxy.html`, opened from _File → Proxy…_ and from the **Proxy settings**
button on the offline page — the one place it is needed most, since a packaged
app has no command line to relaunch with. System, direct, or an address typed by
hand; the wallet offers the same button when a network read fails inside it.

A setting is applied to the live session, then **checked** with one request to
the site before it is saved to `proxy.json` in `userData`. A proxy that does not
answer is rolled back to the previous one and reported with its error code —
otherwise a typo would be saved over a working connection and survive the
restart, leaving the app permanently offline with its own settings window behind
the same dead tunnel. Nothing needs restarting when it works: the session is
re-pinned and the site reloads.

A run launched with `--proxy=` / `--no-proxy` shows the window read-only and says
so: the flag is this run's instruction, and the window would be lying if it
pretended to override it.

Reading or changing the proxy lives behind that window's own preload
(`src/preload-proxy.js`), which no remote page is ever loaded into. All the site
can do is ask for the window to be raised.

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
  itself once the connection returns. It also opens the proxy window, so a
  network that blocks Cyberia is answerable from the screen that reports it.
- **A proxy of its own** — chosen in the app, checked before it is kept, and
  pinned onto the session ahead of the desktop's own setting.
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
