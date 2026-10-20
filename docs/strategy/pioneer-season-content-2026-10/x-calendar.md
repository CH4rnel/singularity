# X calendar (single posts)

Generated from one list; each post also lives in `posts/<id>.txt` for `press:schedule --file`.
`[[FILL: …]]` must be replaced before scheduling, because `schedule.sh` refuses any file that still contains it.
Gates are in `README.md`. Length is counted in characters; X counts a link as 23.


## Week 1

### 2026-10-21 · `w1-01-asset-map` · gate G0 · 264 chars

```text
There are three CYBERs. One map:

CYBER.sol: the token on Solana. Mint E67WWiQY4s9SZbCyFVTh2CEjorEYbhuVJQUZb3Mbpump
CYBER: the native coin of Cyberia, an EVM L1 (chain 49406)
wrapped CYBER.sol: CYBER.sol bridged onto Cyberia

How they connect: cyberia.church/cyber
```

### 2026-10-22 · `w1-02-wallet` · gate G0 · 247 chars

```text
One recovery phrase. Eight networks.

Cyberia, Robinhood Chain, BNB, Base, Solana, Bitcoin, Litecoin, Monero. Same EVM address on all four EVM chains.

Generated in your browser and never sent to us. Open it, and it's ready.

cyberia.church/wallet
```

### 2026-10-23 · `w1-03-l1` · gate G0 · 211 chars

```text
Cyberia is its own chain, not a token with a whitepaper.

Chain ID 49406
RPC rpc.cyberia.church
Explorer explorer.cyberia.church

Add it to any EVM wallet in 30 seconds, or just open ours. Every block is public.
```

### 2026-10-24 · `w1-04-launchpad` · gate G0 · 254 chars

```text
The Cyberia launchpad has no presale, no allocation and no vesting.

The coin that pays for a launch goes into locked liquidity. Every token gets its own DAO the moment it launches, and the launch lands in the feed for everyone.

cyberia.church/launchpad
```

### 2026-10-25 · `w1-05-telegram` · gate G1 · 249 chars

```text
The wallet now lives in Telegram too.

Open @cyberia_network_bot and press Open. It's the same wallet as on the site and in the desktop app: one vault, synced, and the keys stay on your device.

Telegram tells you when someone writes to your wallet.
```

### 2026-10-26 · `w1-06-open-source` · gate G0 · 230 chars

```text
All of Cyberia is in one public repository: the chain config, bridge, DEX, wallet, launchpad, DAO, bots and games.

The backend suite alone is 1,400+ tests. Read the code before you trust it.

github.com/cyberia-temple/singularity
```

### 2026-10-27 · `w1-07-shiplog-1` · gate G0 · 172 chars

```text
Ship log, week of Oct 21:

• [[FILL: 3–5 lines from git log --since=2026-10-21, one user-visible change per line]]

Everything is in the changelog: cyberia.church/changelog
```


## Week 2

### 2026-10-28 · `w2-01-lock` · gate G2 · 285 chars

```text
The CYBER.sol team allocation is locked on Streamflow until March 10, 2027.

Contract: app.streamflow.finance/contract/solana/mainnet/GCvmxEr4cAiSVeye9iD2tF8GsTHDyM4CB3n9AUrjkmFd

Our public roadmap runs at least to that date. Check the lock yourself instead of taking our word for it.
```

### 2026-10-29 · `w2-02-convert` · gate G3 · 245 chars

```text
CYBER.sol → native CYBER at a fixed 1000:1.

No order book, no slippage. The bridged CYBER.sol you convert is burned on Cyberia (sent to 0x…dEaD) by the CyberSolBurnSwap contract, and you get the native coin of the chain.

cyberia.church/convert
```

### 2026-10-30 · `w2-03-dao` · gate G0 · 235 chars

```text
You can vote in Cyberia DAOs straight from the wallet.

Sign once and your vote counts as your address, weighted by voting power, not by head count. You can propose too: pick 1, 3, 7 or 14 days and publish.

cyberia.church/wallet → DAO
```

### 2026-10-31 · `w2-04-nocarrier` · gate G0 · 282 chars

```text
Happy Halloween from the wired.

NO CARRIER is a first-person netstalking horror game we built in Godot. You dial into dead networks and decode anomalies. In the browser build you can mint what you find as an NFT on Cyberia.

cyberia.church/game/nocarrier (sign in with your wallet)
```

### 2026-11-01 · `w2-05-season-rules` · gate G0 · 266 chars

```text
Pioneer Season rules, before it opens:

✓ counts: what you do on chain (bridge, swap, LP, stake)
✓ counts: coming back another day
✗ doesn't: buying, volume, follows, reposts, replies

Rewards are status and on-chain badges. No tokens.

cyberia.church/pioneer-season
```

### 2026-11-02 · `w2-06-domains` · gate G0 · 277 chars

```text
.cyber domains are NFTs, and the holder writes the DNS.

A launchpad token named .<zone> with a DOT<ZONE> ticker opens its own zone. Names there are paid in that token, and the payment is burned.

Our DNS won't serve any zone the real root delegates, so nobody can hijack .com.
```

### 2026-11-03 · `w2-07-shiplog-2` · gate G0 · 174 chars

```text
Ship log, week of Oct 28:

• [[FILL: 3–5 user-visible changes]]

Creator fees this week: [[FILL: received / spent / reserve, SOL]], and the full ledger is pinned in Telegram.
```


## Week 3

### 2026-11-04 · `w3-01-season-open` · gate G4 · 248 chars

```text
Pioneer Season 1 is open, Nov 4 to Dec 1.

Five checkpoints, all verified on chain by the server. Each one adds to your Pioneer badge on CyberiaProfile.

There's no buy requirement and no leaderboard for volume. Rules: cyberia.church/pioneer-season
```

### 2026-11-05 · `w3-02-stocks` · gate G0 · 280 chars

```text
53 tokenized stocks and funds on Robinhood Chain (NVDA, TSLA, SPY, GLD…) are in the Cyberia wallet.

Addresses come from Robinhood's own registry, because "NVDA" alone matches nine contracts on that chain. The price shown is the issuer's quote, and the pool quotes what you'd pay.
```

### 2026-11-06 · `w3-03-mission-use` · gate G4 · 280 chars

```text
Pioneer checkpoint 3: a real swap on Cyberia, above the campaign minimum.

The server reads it from the chain's indexer, so a screenshot proves nothing and a real transaction proves everything. Checkpoint 4 is the next step after it: add liquidity or stake.

cyberia.church/wallet
```

### 2026-11-07 · `w3-04-lainos` · gate G0 · 268 chars

```text
Lain is our open-source AI agent framework. It runs as a daemon with tools and a Cyberia chain plugin.

Every wish it forges and every watch it fires shows up on our public ops board, so you can see what the machine did, not only what we said.

github.com/lain-labs/os
```

### 2026-11-08 · `w3-05-chaosfm` · gate G5 · 211 chars

```text
chaos.fm is back. It's an imageboard in the old chaos.fm style, and you sign in with your wallet.

You don't need an email or a phone number. Your key is your name, or you can post with no name at all.

chaos.fm
```

### 2026-11-09 · `w3-06-bounties` · gate G6 · 271 chars

```text
Pioneer bounties are open, paid in SOL out of CYBER.sol creator fees and nothing else.

• a reproduced bug
• a translation
• a guide or 60s demo
• an integration or token site

Every payout goes in a public ledger. List: [[FILL: GitHub issues link, label pioneer-bounty]]
```

### 2026-11-10 · `w3-07-shiplog-3` · gate G0 · 163 chars

```text
Ship log, week of Nov 4:

• [[FILL: 3–5 user-visible changes]]

Pioneer Season so far: [[FILL: verified pioneers / completed checkpoint 3 / came back another day]]
```


## Week 4

### 2026-11-11 · `w4-01-mission-return` · gate G4 · 187 chars

```text
Pioneer checkpoint 5 is the hardest: come back.

Do another qualifying on-chain action on a later UTC day. One visit is curiosity, and a second one is the signal we're actually measuring.
```

### 2026-11-12 · `w4-02-predictions` · gate G0 · 266 chars

```text
Cyberia prediction markets settle themselves.

A question ends with a tag like [px:SOL>80@coingecko]. After close, the server reads the first quote and resolves it. If a question can't be settled in time, it's cancelled with full refunds.

cyberia.church/predictions
```

### 2026-11-13 · `w4-03-chaos-mcp` · gate G7 · 219 chars

```text
chaos-mcp is one MCP server for Cyberia, Lain OS and the market around them.

Plug it into any MCP client and ask your agent about the chain, the launchpad or pump.fun. You authenticate with a Lain OS key.

chaos.fm/mcp
```

### 2026-11-14 · `w4-04-extension` · gate G8 · 257 chars

```text
The Cyberia wallet extension is out for Chrome and Firefox.

It connects only to sites you grant, one origin at a time. Every signature opens its own approval window and waits for you, and it never injects into every page.

cyberia.church/download/extension
```

### 2026-11-15 · `w4-05-receipts` · gate G6 · 196 chars

```text
Bounty receipts, week 1:

[[FILL: one line each: what was done → SOL paid → tx link]]

Paid from creator fees: [[FILL]] SOL. Kept in reserve: [[FILL]] SOL. Ledger: [[FILL: pinned TG message link]]
```

### 2026-11-16 · `w4-06-board` · gate G0 · 210 chars

```text
Every launchpad token can have its own homepage, pinned to IPFS, with a hit counter that can't take the page down.

It's the underground: a small neocities for the tokens on Cyberia.

underground.cyberia.church
```

### 2026-11-17 · `w4-07-scoreboard` · gate G0 · 224 chars

```text
Four weeks of building in public:

holders [[FILL]] → [[FILL]]
verified pioneers [[FILL]]
came back another day [[FILL]]
creator fees received / spent [[FILL]]

Next stop on the roadmap: [[FILL: one phase from /whitepaper]].
```

