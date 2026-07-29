#!/usr/bin/env bash
#
# Warn before the manually issued *.cyberia.church wildcard expires.
#
# That lineage is deliberately kept out of the webroot `certbot renew` cron
# (Namecheap BasicDNS has no certbot plugin, so it is a manual DNS-01 re-issue
# via scripts/renew-wildcard.sh). Nothing else would notice it running out, and
# every launchpad token subdomain goes dark the moment it does.
#
# Run daily from cron on the prod host.
set -uo pipefail

CERT=/root/certbot/conf/live/cyberia-wildcard/cert.pem
WARN_DAYS=${WARN_DAYS:-21}
ALERT_ENV=/root/acme-hook/alert.env

if [ ! -f "$CERT" ]; then
  echo "$(date -Is) WILDCARD CERT MISSING: $CERT"
  exit 1
fi

if openssl x509 -in "$CERT" -noout -checkend $((WARN_DAYS * 86400)) >/dev/null 2>&1; then
  exit 0
fi

END=$(openssl x509 -in "$CERT" -noout -enddate | cut -d= -f2)
MSG="cyberia.church wildcard TLS cert expires $END (under $WARN_DAYS days). It does NOT auto-renew: run /root/acme-hook/renew-wildcard.sh and add the printed TXT record in Namecheap. Launchpad token subdomains break when it lapses."

echo "$(date -Is) $MSG"

# Optional Telegram ping. Create /root/acme-hook/alert.env with:
#   ALERT_CHAT_ID=<chat id>
# The bot token is reused from the Telegram bot's env; nothing is echoed.
if [ -f "$ALERT_ENV" ]; then
  # shellcheck disable=SC1090
  . "$ALERT_ENV"
  TOKEN=$(grep -E '^TELEGRAM_BOT_TOKEN=' /root/singularity/scripts/python/.env 2>/dev/null | head -1 | cut -d= -f2-)
  if [ -n "${ALERT_CHAT_ID:-}" ] && [ -n "${TOKEN:-}" ]; then
    curl -sS -o /dev/null --max-time 20 \
      -d "chat_id=$ALERT_CHAT_ID" \
      --data-urlencode "text=$MSG" \
      "https://api.telegram.org/bot${TOKEN}/sendMessage" || echo "$(date -Is) telegram alert failed"
  fi
fi
