# Monero bridge wallet

The XMR corridor (`xmr_to_evm`, `evm_to_xmr`) is the one place in this project
where a chain cannot be read by asking a third party. There is no explorer that
can tell this server whether a deposit arrived, and no public RPC that can
report a balance: a Monero address discloses nothing without its view key. So
the bridge holds a wallet, and this directory runs it.

## What it is

| Container | What it does | Exposed |
|---|---|---|
| `monero-wallet-rpc` | The bridge's wallet: subaddresses, balances, payouts | the app's container, by name |
| `monerod` | Optional (`--profile fullnode`): syncs the chain, relays transactions | p2p `18080` only |

**The production shape is a thin client**: the wallet here, the chain
somebody else's. `cyber.main` has ~120 GB of disk holding a chain node, an
explorer, two databases and the site, and a pruned Monero chain is ~60 GB of
that — so `monerod` sits behind a compose profile and is not started. The
wallet keeps its own keys and scans blocks itself with its view key; a remote
node only serves it blocks. What that costs is written down under
[Running without a node](#running-without-a-node), and it is a real cost, not
a formality.

`monero-wallet-rpc` holds **spend keys**. Anyone who can reach port 18083 and
holds its login can empty it, so reachability is kept to two places: the host's
own loopback (for an operator with a shell) and one two-member docker network
shared with the app. Do not put it behind a public reverse proxy, do not raise
the publish address to `0.0.0.0`, and do not attach it to the stack network
where the explorer and IPFS live.

## Setup

1. **Create the wallet once, by hand.** The compose file deliberately cannot:

   ```bash
   docker compose run --rm --entrypoint monero-wallet-cli wallet \
     --generate-new-wallet /wallet/bridge --daemon-address "$MONERO_DAEMON_ADDRESS"
   ```

   Use the same daemon the RPC will use — a remote one on a thin-client host,
   `monerod:18081` where the node runs — and see step 6: a wallet created
   against an unreachable daemon starts life hundreds of thousands of blocks
   in the past. Write the 25-word seed down offline. It is the only copy. The RPC is
   started with `--wallet-file` rather than `--wallet-dir`, so it cannot
   create, open or switch wallets — it can only operate this one.

2. **Give the RPC its password and its login.** Neither belongs in the repo,
   so both live in files git ignores — `wallet/password.txt` (what the wallet
   is encrypted with) and `.env` beside this README (what callers authenticate
   with):

   ```bash
   umask 077
   openssl rand -base64 32 | tr -d '\n' > ./wallet/password.txt
   printf 'MONERO_RPC_LOGIN=bridge:%s\n' "$(openssl rand -hex 24)" > ./.env
   ```

   `docker compose` refuses to start without `MONERO_RPC_LOGIN`, on purpose: a
   wallet RPC with no login is a wallet anyone on the host can spend.

3. **Start it.** A plain `up -d` is the thin client — the wallet alone,
   against the node named in `MONERO_DAEMON_ADDRESS`:

   ```bash
   docker compose up -d
   docker compose logs -f wallet
   ```

   With a local chain instead (hours to days to sync, ~60 GB), say so in
   `./.env` first — neither line has a default, because the wrong guess is
   either an unwanted chain sync or a stranger's node marked trusted:

   ```bash
   # in ./.env
   MONERO_DAEMON_ADDRESS=monerod:18081
   MONERO_DAEMON_TRUST=--trusted-daemon

   docker compose --profile fullnode up -d
   docker compose exec monerod monerod status
   ```

4. **Point Laravel at it** (`backend/laravel/.env`). Laravel runs in its own
   container, where a host loopback port does not exist, so the two meet on a
   network and the wallet is addressed by name:

   ```
   BRIDGE_XMR_WALLET_RPC_URL=http://cyberia-monero-wallet:18083
   BRIDGE_XMR_WALLET_RPC_USER=bridge
   BRIDGE_XMR_WALLET_RPC_PASSWORD=...
   ```

   That network is `cyberia-bridge-monero`, and it has exactly two members:
   this wallet and the app. It is declared by the **site's** compose (which
   creates it) and joined as `external` by this one (which needs it), so the
   site still starts on a host that never ran this stack, and the wallet — an
   endpoint that can spend — is never reachable from the explorer front end,
   IPFS or the proxy.

   Do not wire it with `docker network connect` instead. A hand-run link does
   not survive the container being recreated: on 2026-09-13 a log-rotation
   pass recreated this wallet, the link vanished with it, and for ten hours
   every XMR deposit answered *"Could not reach the Monero wallet"* while the
   wallet was healthy and synced.

   Prod caches its config, so `php artisan config:clear && php artisan
   config:cache` inside the app container or none of this is read.

   With no URL set, both XMR routes disappear from the bridge entirely —
   `BridgeConfigService` treats an unattached wallet as a corridor that does
   not exist, rather than one that accepts deposits it cannot see.

5. **Open the corridor in two steps, not one.** Deposits mint a wrapper the
   relayer creates on demand, so that half needs no float and can open as soon
   as the wallet is synced. Payouts spend real XMR, so that half stays shut
   until the wallet holds some — a corridor that cannot deliver must not
   advertise that it can:

   ```
   BRIDGE_CHAIN_XMR_ENABLED=true
   BRIDGE_ROUTE_XMR_TO_EVM_COMING_SOON=false   # deposits in
   BRIDGE_ROUTE_EVM_TO_XMR_COMING_SOON=true    # until the wallet is funded
   ```

6. **Create the wallet with the daemon reachable.** A wallet created while the
   daemon is unreachable records monero's built-in *estimate* of the chain
   height instead of the real one — on a 2024 binary that is ~560k blocks in
   the past, and the wallet then rescans two years of chain it has no
   transactions in, starving its own single-threaded RPC the whole time. Check
   it afterwards: `get_height` should answer within a few of the real tip.

## Running without a node

This is how `cyber.main` runs, so it is worth being exact about what is given
up. The wallet holds the keys and scans with its own view key either way — a
remote node never sees which outputs are yours and can never spend. What it
does get, and what it can do:

- **It sees what you ask for.** The node learns this server's IP and which
  blocks it pulls. It does not learn which of them matter.
- **It can lie by omission.** A node that withholds a block hides a deposit,
  and the bridge then reads that deposit as *not yet arrived* — a user waits,
  and the sweeper credits it the moment a truthful answer comes back. That is
  the safe direction of the same lie, and it is why nothing here treats an
  unreadable wallet as an empty one.
- **It can stall.** If the node goes away, the wallet stays up and stops
  advancing. `get_height` is the check: compare it with a public explorer's
  tip. The wallet RPC is single-threaded, so a dead daemon also makes calls
  queue behind a refresh that will not finish.
- **It cannot be trusted for fees or tip.** Hence `--untrusted-daemon`, which
  is the default in the compose file: only a node on this host earns
  `--trusted-daemon`.

```bash
# in ./.env
MONERO_DAEMON_ADDRESS=<host>:18089
MONERO_DAEMON_TRUST=--untrusted-daemon    # the default; say it anyway
docker compose up -d                      # monerod is behind --profile fullnode
```

Pick a node run by someone who has no idea this bridge exists, and change it if
it starts answering slowly — the swap of one line and a `docker compose up -d`
is the whole migration, because nothing about the wallet lives in the node.

## Three things that will bite

- **The images already pass some flags.** `monerod`'s entrypoint prepends
  `--non-interactive`; the wallet's prepends that plus `--rpc-bind-ip=0.0.0.0`
  and `--confirm-external-bind`. Monero refuses any flag given twice and exits
  before it binds, so nothing in `command:` may repeat them.
- **`--no-initial-sync` is load-bearing.** Without it the wallet opens,
  refreshes against the daemon and only *then* listens. For those minutes
  Laravel reads an unreachable wallet and hides the corridor — which looks
  exactly like a broken bridge rather than a wallet catching up.
- **The RPC is single-threaded.** A refresh that blocks (a slow or unreachable
  daemon) queues every other call behind it, so `get_height` can hang for
  minutes while `get_balance` answers instantly in a gap. If the wallet seems
  half-alive, look at the daemon connection first — that is the cause, and
  `--log-level=2` prints what it is doing (`net.http Reconnecting...`).

## How the bridge uses it

- **Bridge in.** `POST /bridge/prepare` asks the wallet for a fresh
  subaddress (`create_address`, labelled `bridge:<request id>`) and commits the
  recipient before anything is sent. A subaddress and not an integrated
  address: a payment id is something the *sender's* wallet has to carry, and a
  sender who drops it produces a deposit nobody can attribute.
- **Crediting.** `bridge:sweep-deposits` (scheduled every two minutes) reads
  `get_transfers` filtered to each watched subaddress and credits whatever has
  reached `BRIDGE_XMR_MIN_CONFIRMATIONS` (10 by default, ~20 minutes). The
  "check now" button on the bridge does the same thing on demand. Nothing about
  crediting depends on a browser staying open.
- **Bridge out.** The relayer sends `transfer` for the exact net amount and
  tags it with `bridge:<request id>` (`set_tx_notes`). The Monero network fee
  is charged to the wallet on top of the amount sent — that is what
  `BRIDGE_MONERO_PAYOUT_FEE_XMR` withholds upstream and what
  `BRIDGE_MONERO_FEE_RESERVE_XMR` keeps back from the advertised capacity.
- **Never twice.** A `transfer` whose HTTP answer is lost is the only way this
  corridor could double-pay. Before sending, the relayer asks the wallet what
  it has already sent for this request (by note, and — only for a request whose
  own attempt is already recorded — by destination and exact amount).

## Operating it

```bash
docker compose logs -f wallet                 # what the wallet is doing
docker compose exec monerod monerod status    # sync height (fullnode profile only)
php artisan bridge:sweep-deposits             # credit deposits now, verbosely
php artisan bridge:relay <id>                 # retry one request
```

Capacity is read live from the wallet's **unlocked** balance: an output is
locked for ten blocks after it arrives, so a wallet that was just topped up
reports less than it holds for twenty minutes. That is not a bug to work
around — it is what the wallet can actually spend.
