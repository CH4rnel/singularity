# The tracker: releases that exist because somebody minted them

A BitTorrent tracker where a release is a token. One rule shapes everything
below it:

```
no token  ──▶  no release
```

Nothing is listed that was not minted, and every field on a listing is read by
this server off the chain and out of the document the token points at. There is
no form field a publisher fills in that the index takes their word for.

Paths are relative to `backend/laravel/` unless stated otherwise.

---

## 1. What the parts are

```
   the publisher                    the chain                     this host
┌──────────────────────┐      ┌──────────────────────┐     ┌────────────────────┐
│ desktop shell        │      │ CyberiaNFT (49406)   │     │ /announce, /scrape │
│  makes the torrent   │      │  tokenURI = ipfs://… │     │  bencode, peers    │
│  seeds it            │      │  ownerOf  = who      │     │                    │
│         │            │      └──────────▲───────────┘     │ tracker_releases   │
│         ▼            │                 │                 │ tracker_peers      │
│ wallet: pin + mint   │─────────────────┘                 │                    │
│         │            │                                   │ /tracker  (public) │
│         └────────────┼── POST /api/tracker/releases ────▶│ reads the chain    │
│            {chain_id, token_id}                          └────────────────────┘
└──────────────────────┘
```

- **`App\Support\Bencode`** — the encoding BitTorrent speaks. Four types,
  dictionary keys emitted bytewise sorted, strings that are bytes rather than
  text. `slice()` returns the *original* bytes of one key, which is what makes
  an info hash computable: SHA-1 is taken over the info dictionary exactly as
  its writer wrote it, and re-encoding a decoded one is a guess.
- **`TrackerAnnounceService`** — swarm bookkeeping. Peers are rows that are true
  for the next quarter of an hour and are deleted rather than remembered.
- **`ReleaseRegistrar`** — turns a minted token into a listing.
- **`ReleaseMetadata`** — pure: given the JSON a CID names, the row or the
  reason there is none.
- **`ReleaseIndex`** — one query builder for the three readers (site page,
  wallet screen, JSON API), because three would be three slightly different
  answers to "what is on this tracker".

---

## 2. The announce endpoint

`/announce` and `/scrape` are registered in `routes/tracker.php`, outside every
middleware group (`bootstrap/app.php`, the `then:` callback). Torrent clients
call them several times an hour and have no use for a session — a session
cookie per announce is a session record per announce, written for a client that
discards it before the next request.

Three things about them are not style:

**Everything answers 200, including every refusal.** The protocol's only way to
say no is a `failure reason` inside the bencoded dictionary. A client that gets
a 4xx reports "tracker is down" and never shows the sentence to anybody.

**The query string is read raw.** An announce carries two twenty-byte *binary*
values — the info hash and the peer id — percent-encoded. Reading them through
the framework's input handling trims a hash whose first byte happens to be
`0x20` or `0x0a`, which silently loses one torrent in a few hundred with no
error anywhere. `AnnounceRequest::pairs()` decodes `QUERY_STRING` itself, with
`rawurldecode` (a `+` in a hash is the byte `0x2B`, not a space).

**An unregistered swarm is refused.** An open tracker is a stranger's
infrastructure by lunchtime, and the point of this one is that everything on it
traces back to a token that says who put it there.

Peers come back in compact form (BEP 23 — four bytes of address, two of port,
big-endian), with IPv6 peers in `peers6` (BEP 7) since they do not fit in six
bytes. A leecher is given seeders first; a swarm of leechers pointed at each
other is a swarm where nothing finishes.

Expiry is opportunistic *and* scheduled: every announce sweeps its own swarm,
and `tracker:prune` (hourly) sweeps the ones nobody is announcing to any more —
a release everybody left is never announced to again, so without it the row
would keep showing the seeders it had the day the last client closed.

---

## 3. Publishing

`POST /api/tracker/releases` takes **two fields**: `chain_id` and `token_id`.
That is the whole request body, and it is why there is nothing to lie in. The
server then:

1. reads `ownerOf(tokenId)` and `tokenURI(tokenId)` from the chain named in
   `config/tracker.php` (Cyberia only — the point of the check is that this host
   can perform it),
2. fetches the document (an `ipfs://` through the read gateway, or https; one
   timeout, one size cap, no other scheme),
3. parses it with `ReleaseMetadata`,
4. refuses an info hash that is already a release under a different token.

The metadata is ordinary ERC-721 JSON with one extra key, so a marketplace that
never heard of this tracker still renders the name, the cover and the
description:

```json
{
  "name": "…",
  "description": "…",
  "image": "ipfs://…",
  "animation_url": "ipfs://…",
  "torrent": {
    "info_hash": "40 hex characters",
    "name": "…",
    "length": 0,
    "files": [{ "path": "a/b.flac", "length": 0 }],
    "magnet": "magnet:?xt=urn:btih:…",
    "category": "video|audio|image|software|text|other"
  }
}
```

The same three facts are mirrored into `attributes`, for readers that render
only those. A magnet naming a *different* torrent than the token does is
discarded and rebuilt from the info hash — that mismatch is the one way a
listing could show one thing and hand out another.

Re-registering the same token is allowed and picks up its new owner; a token is
transferable, and a sold release moves with it. `tracker:sync` (daily) does the
same for rows nobody has touched.

**Takedown is `hidden_at`, never a delete.** The token cannot be unminted, and a
deleted row could be re-registered by anyone the same minute. The index is a
view over the chain and says so by keeping the token's address on every row.

---

## 4. What each machine can honestly do

| | browser tab | desktop shell |
|---|---|---|
| read the index | yes | yes |
| mint and register | yes | yes |
| read a `.torrent` for its info hash | yes | yes |
| **create** a torrent and seed it | no | yes |
| join a swarm | no | yes |
| play a file out of a swarm | no | yes |
| play the pinned sample | yes | yes |

The middle row is the reason the desktop shell exists at all: the mainline DHT
is UDP and peers are TCP, and a page has neither. What a page *can* reach —
WebRTC peers behind WSS trackers — is a different swarm that most magnets have
no members in, so offering it would be a client that finds nobody.

So `frontend/desktop` gained three capabilities (engine version 2,
`src/torrent-rules.js`):

- `seed(mode)` — the page asks for a *picker*, never a path. The shell opens a
  native dialog, hashes what was chosen, writes its own site's `/announce` into
  the torrent (derived from `APP_URL`, never accepted from the page) and seeds
  it in place. Nothing is uploaded.
- `stream(infoHash, index)` — a URL a media element can load.
- `openFile(infoHash, index)` — hands one file to the system's player, which is
  the answer for Matroska.

### Why streaming needs a scheme of its own

The wallet is served over https. A media element on an https page **cannot**
load `http://127.0.0.1:port` — that is mixed content, blocked with no visible
error and no event. So the shell registers `cyberia-media://` as a privileged
scheme (`secure`, `standard`, `stream`, `supportFetchAPI`) before the app is
ready, handles it on the shell session only, and proxies it to WebTorrent's own
loopback server, forwarding `Range` (without which seeking does not exist).

The page never learns a port, and only files inside torrents the client already
holds are addressable. The server is bound to `127.0.0.1` and set to refuse any
request whose `Host` header is not that, which is what stops a page in any
browser on the machine from reaching it by DNS rebinding.

Reading the file off disk instead would not work: a download in progress is a
sparse file full of holes, and playing it gives silence and garbage.
WebTorrent's server waits for the pieces the player is asking for, which is what
makes seeking work before a download has finished.

---

## 5. The player

`components/wallet/WalletPlayer.vue` with the pure parts in
`lib/wallet/player.ts`. One `<video>` element for both kinds — a video element
plays audio perfectly well, and two elements would mean two volumes and a gap of
silence every time a playlist crossed from a film to its soundtrack.

The controls are drawn rather than native for one reason: the native ones cannot
say *why* nothing is playing. Three states look identical to a browser and are
not the same problem —

- a stream still being asked for from a swarm,
- a container this engine never decoded (`formatSupport()` says so **before**
  anything is tried; `MEDIA_ERR_SRC_NOT_SUPPORTED` says so after),
- a file whose peers have not arrived.

A track's URL is resolved when it is played, not when the list is drawn: asking
for a stream tells the client that file is urgent, and doing that for forty
files at once downloads none of them.

Playlists come from two places and are never mixed up. `tracksFromRelease()`
returns only what the publisher pinned to IPFS, which plays anywhere with no
client at all. `tracksFromTorrent()` reads the files from the *engine* rather
than from the release's metadata — the index a stream is asked for has to be an
index into the torrent this client actually holds, and "the published file list
is usually identical" is how a player ends up streaming track four while
displaying track three.

---

## 6. Configuration

`config/tracker.php`:

| key | what it decides |
|---|---|
| `announce_url` | the URL written into every torrent created here; absolute, because the torrent outlives this deploy |
| `interval` / `min_interval` | how often a client announces, and therefore how long a peer row lives (`interval × 2`) |
| `numwant` / `max_numwant` | peers per answer, and the ceiling on asking for more |
| `chains` | where a release may be minted: rpc, collection, explorer |
| `metadata` | timeout, size cap and file-count cap when fetching a stranger's tokenURI |
| `categories` | the closed list a release may be filed under |

Scheduled: `tracker:prune` hourly, `tracker:sync` daily at 05:20.

---

## 7. Verification

```bash
cd backend/laravel
php -d memory_limit=1G vendor/bin/pest --compact tests/Unit/BencodeTest.php \
  tests/Feature/TrackerAnnounceTest.php tests/Feature/TrackerRegisterTest.php
npm run test:frontend                    # WalletTrackerTest.mjs
cd ../../frontend/desktop && npm test    # seeding/streaming rules
```

The announce tests build every query string by hand rather than through
`http_build_query`, because that is the bug the endpoint exists to survive: it
writes a space as `+`, and a tracker that reads its hashes through it drops
every torrent whose hash starts with `0x20`.
