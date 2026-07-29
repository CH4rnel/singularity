#!/usr/bin/env bash
#
# Re-issue the *.cyberia.church wildcard certificate on cyber.main.
#
# Let's Encrypt only issues wildcards over DNS-01, and cyberia.church is on
# Namecheap BasicDNS, which has no certbot plugin. So this lineage is NOT part
# of the webroot `certbot renew` cron (its renewal config is parked in
# /root/certbot/manual-renewal/) and has to be refreshed by hand every ~90 days.
#
# The script prints one TXT record, waits for it to show up on the authoritative
# nameserver, finishes the ACME order and reloads nginx. Run it on the prod host.
#
# Usage: ./renew-wildcard.sh
set -euo pipefail

HOOK_DIR=/root/acme-hook
STATE_DIR="$HOOK_DIR/state"
CERT_NAME=cyberia-wildcard
DOMAIN='*.cyberia.church'
CHALLENGE_NAME=_acme-challenge.cyberia.church
AUTH_NS=dns1.registrar-servers.com
PROXY_COMPOSE_DIR=/root/blockscout/docker-compose

mkdir -p "$STATE_DIR"

cat > "$HOOK_DIR/auth.sh" <<'EOF'
#!/bin/sh
# Publish the challenge value for the operator, then wait for the TXT record.
set -e
D=/hook/state
mkdir -p "$D"
rm -f "$D/go"
printf "%s\n" "$CERTBOT_VALIDATION" > "$D/value.txt"
printf "%s\n" "_acme-challenge.$CERTBOT_DOMAIN" > "$D/name.txt"
i=0
while [ ! -f "$D/go" ] && [ "$i" -lt 420 ]; do
  sleep 5
  i=$((i + 1))
done
[ -f "$D/go" ]
EOF

cat > "$HOOK_DIR/cleanup.sh" <<'EOF'
#!/bin/sh
rm -f /hook/state/value.txt /hook/state/name.txt /hook/state/go
exit 0
EOF

chmod +x "$HOOK_DIR/auth.sh" "$HOOK_DIR/cleanup.sh"
rm -f "$STATE_DIR/value.txt" "$STATE_DIR/go" "$HOOK_DIR/certbot.log"

echo "==> requesting $DOMAIN from Let's Encrypt"
setsid nohup docker run --rm --name certbot-wildcard \
  -v /root/certbot/conf:/etc/letsencrypt \
  -v /root/certbot/www:/var/www/certbot \
  -v "$HOOK_DIR:/hook" \
  certbot/certbot certonly \
  --manual --preferred-challenges dns \
  --manual-auth-hook /hook/auth.sh \
  --manual-cleanup-hook /hook/cleanup.sh \
  --cert-name "$CERT_NAME" \
  -d "$DOMAIN" \
  --key-type ecdsa \
  --non-interactive --agree-tos \
  > "$HOOK_DIR/certbot.log" 2>&1 < /dev/null &

for _ in $(seq 1 60); do
  [ -s "$STATE_DIR/value.txt" ] && break
  sleep 2
done

if [ ! -s "$STATE_DIR/value.txt" ]; then
  echo "certbot did not produce a challenge value:" >&2
  cat "$HOOK_DIR/certbot.log" >&2
  exit 1
fi

VALUE=$(cat "$STATE_DIR/value.txt")
cat <<MSG

  Add this TXT record in the Namecheap dashboard
  (Domain List -> cyberia.church -> Manage -> Advanced DNS -> Add New Record):

      Type   TXT Record
      Host   _acme-challenge
      Value  $VALUE
      TTL    Automatic (or 1 min)

  Remove any older _acme-challenge TXT record first. Waiting for it to go live...

MSG

FOUND=0
for _ in $(seq 1 400); do
  if dig +short TXT "$CHALLENGE_NAME" "@$AUTH_NS" 2>/dev/null | grep -q "$VALUE"; then
    FOUND=1
    break
  fi
  sleep 5
done

if [ "$FOUND" -ne 1 ]; then
  echo "timed out waiting for $CHALLENGE_NAME to serve the challenge value" >&2
  docker kill certbot-wildcard >/dev/null 2>&1 || true
  exit 1
fi

echo "==> TXT record is live, completing the ACME order"
touch "$STATE_DIR/go"
wait || true
cat "$HOOK_DIR/certbot.log"

if ! openssl x509 -in "/root/certbot/conf/live/$CERT_NAME/cert.pem" -noout -checkend 0 >/dev/null 2>&1; then
  echo "certificate was not issued" >&2
  exit 1
fi

# Keep the lineage out of the webroot renew cron: `certbot renew` would block on
# the manual hook for 35 minutes and then fail, skipping the nginx reload.
mkdir -p /root/certbot/manual-renewal
if [ -f "/root/certbot/conf/renewal/$CERT_NAME.conf" ]; then
  mv "/root/certbot/conf/renewal/$CERT_NAME.conf" /root/certbot/manual-renewal/
fi

echo "==> reloading nginx"
docker exec proxy nginx -t
docker exec proxy nginx -s reload

echo "==> done"
openssl x509 -in "/root/certbot/conf/live/$CERT_NAME/cert.pem" -noout -enddate -ext subjectAltName

echo
echo "Delete the _acme-challenge TXT record in Namecheap now; it is no longer needed."
echo "Next renewal is due ~60 days from today (see $PROXY_COMPOSE_DIR for the nginx side)."
