# Cyberia Desktop

Electron shell that ships the Cyberia site (`backend/laravel`) as a desktop
application for Linux, Windows, and macOS.

The site is a server-driven Inertia app, so the shell does not bundle a copy of
it: it renders `https://cyberia.church` in a persistent session. That keeps the
app permanently in sync with production — a deploy is a shipped update — and
means the whole shell is three small files in `src/`.

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

| Variable / flag  | Default                 | Purpose                        |
| ---------------- | ----------------------- | ------------------------------ |
| `CYBERIA_APP_URL` | `https://cyberia.church` | Site the window renders        |
| `--url=<url>`     | —                       | Same, as a command-line switch |

```bash
CYBERIA_APP_URL=http://localhost:8000 npm start
npm start -- --url=http://localhost:8000
```

Anything that is not `http`/`https` falls back to production, so a bad override
cannot point the app at a local file.

## What the shell adds over a browser tab

- **Persistent session** — cookies and local storage live in the `persist:cyberia`
  partition, so a login survives restarts.
- **Link discipline** — only `cyberia.church`, its subdomains, and the OAuth
  providers the site redirects through may take over the window. Everything else
  (`target="_blank"`, explorer links, `wc:`/`metamask:` wallet schemes) is handed
  to the system browser or the registered app.
- **Offline fallback** — `src/offline.html` replaces the network error page and
  reloads the site by itself once the connection returns.
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
