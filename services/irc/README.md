# Cyberia IRC

`irc.cyberia.church`, port **6697**, TLS only. The server is
[Ergo](https://ergo.chat), which bundles NickServ, ChanServ and the message
history into one binary with one embedded database, so this is one container
and one volume.

## Connecting

| Setting | Value |
|---|---|
| Server | `irc.cyberia.church` |
| Port | `6697` |
| TLS | on (a real Let's Encrypt certificate, no exceptions to click through) |
| Main channel | `#cyberia` |

```
/server irc.cyberia.church 6697 -tls        # irssi: /connect -tls irc.cyberia.church 6697
/join #cyberia
/msg NickServ register <password>           # keeps your nick yours
```

There is no plaintext port 6667. It would carry NickServ passwords across the
internet in the clear, and every client in use speaks TLS.

## Layout

| Path | What it is |
|---|---|
| `ircd.yaml` | Ergo 2.19.1's `default.yaml`. Every line changed for Cyberia is marked `cyberia:` |
| `cyberia.motd` | The message of the day |
| `.env` (gitignored) | `ERGO__OPERS__ADMIN__PASSWORD`, the oper password's bcrypt hash |
| volume `irc_data` | `/ircd/ircd.db`: accounts and channel registrations |
| `/root/certbot/conf` → `/tls` | The host's Let's Encrypt store, mounted read-only |

The repo holds no secrets. The oper section of `ircd.yaml` keeps Ergo's
placeholder hash, which no password matches, and the real hash comes from the
environment through Ergo's `allow-environment-overrides`.

## Setup (once, on the host)

1. **Certificate.** Issue it with the same certbot and webroot as every other
   `cyberia.church` cert. The proxy's catch-all `*.cyberia.church` server on
   port 80 already serves `/.well-known/acme-challenge/` for any subdomain:

   ```bash
   cd /root/singularity/services/blockscout/docker-compose
   docker compose run --rm certbot certonly --webroot -w /var/www/certbot \
     -d irc.cyberia.church --non-interactive --agree-tos -m <email>
   ```

   The twice-daily `certbot renew` cron renews it with the others.

2. **Oper password.** Generate a password, hash it, and keep the plaintext
   somewhere this repo never sees:

   ```bash
   cd /root/singularity/services/irc
   umask 077
   PASS=$(openssl rand -base64 24)
   HASH=$(echo "$PASS" | docker run --rm -i --entrypoint /ircd-bin/ergo ghcr.io/ergochat/ergo:v2.19.1 genpasswd | tail -1)
   printf "ERGO__OPERS__ADMIN__PASSWORD='%s'\n" "$HASH" > .env
   ```

3. **Start it.**

   ```bash
   docker compose up -d
   docker compose logs -f ergo
   ```

4. **Reload the certificate after renewal.** Ergo rereads its certificate on
   SIGHUP. A host cron sends one shortly after each renew run:

   ```
   23 3,15 * * * docker kill -s HUP cyberia-irc >/dev/null 2>&1
   ```

   Without this line, Ergo keeps serving the old certificate until it expires.

## Lain

The `lain-bot` container is Lain on IRC. It answers every private query, and
any channel line that names her (`lain: …`, `lain, …`, or `lain`/`лейн` as a
word). It sends the line and the channel's last 20 lines, with nicks, to
Laravel's `POST /api/irc/lain`. Laravel answers with `LainChatService`, the
same tool-less persona as `/lain` and the wallet's room. **It is not the LainOS
daemon.** That one is the operator's personal agent, with a wallet and a shell,
and a public channel must never reach it.

The bot holds no model key and no wallet. It needs two values in `.env`:

- `IRC_LAIN_PASSWORD`: the password of the IRC account `lain`. The bot
  registers the account with NickServ on its first connect and logs in with
  SASL afterwards, so nobody else can hold the nick.
- `IRC_LAIN_TOKEN`: the shared secret for the endpoint. It must equal
  `IRC_LAIN_TOKEN` in the Laravel `.env` (then run `php artisan config:cache`).
  Unset there, the endpoint answers 404.

Replies are cut to six lines and paced under Ergo's flood limit. Each nick
can ask once every three seconds, and at most ten questions wait in the
queue.

## Operating

- **Oper:** `/OPER admin <password>`. Opers are hidden from WHOIS.
- **Config change:** edit `ircd.yaml`, then `/REHASH` as oper or
  `docker kill -s HUP cyberia-irc`. A change to an environment override needs
  `docker compose up -d --force-recreate` instead, because Ergo does not
  rehash those.
- **Upgrading Ergo:** diff the new release's `default.yaml` against this one.
  Carry over the `cyberia:` lines, then bump the image tag in
  `docker-compose.yml`. The datastore upgrades itself (`autoupgrade: true`).
- **Backups:** `ircd.db` on the `irc_data` volume is the only state. Message
  history is in memory and expires after 7 days by design.
