#!/usr/bin/env bash
# Host heartbeat for the service monitor.
#
# Laravel runs inside the cyberia_church container. From in there the docker
# daemon, the tmux sessions holding the Telegram bot and LainOS, the load
# average and the disk are all invisible — which is most of what this project
# actually runs. So the host reports them, once a minute, and the app decides
# what they mean.
#
# This script is deliberately dumb. It reports facts and never judgements:
# which containers exist and in what state, which tmux sessions are alive, how
# many processes match a pattern, how stale each cron's log is. Nothing here
# knows what a "service" is. That lives in backend/laravel/config/monitoring.php,
# so adding or renaming a service never means redeploying this file.
#
# Install (on the host, as root):
#
#   ln -s /root/singularity/scripts/ops/heartbeat.sh /usr/local/bin/cyberia-heartbeat
#   printf 'OPS_HEARTBEAT_TOKEN=%s\nOPS_HEARTBEAT_URL=%s\n' \
#       "$(openssl rand -hex 32)" "https://cyberia.church/api/ops/heartbeat" \
#       > /etc/cyberia-heartbeat.env
#   chmod 600 /etc/cyberia-heartbeat.env
#   # the same token goes into the app's .env as OPS_HEARTBEAT_TOKEN,
#   # then: php artisan config:cache
#   ( crontab -l; echo '* * * * * /usr/local/bin/cyberia-heartbeat >/dev/null 2>&1' ) | crontab -
#
# Exits non-zero only when it could not report. It never fails the cron over
# something it merely could not measure.
set -uo pipefail

ENV_FILE=${ENV_FILE:-/etc/cyberia-heartbeat.env}

if [ -r "$ENV_FILE" ]; then
    # shellcheck disable=SC1090
    . "$ENV_FILE"
fi

URL=${OPS_HEARTBEAT_URL:-https://cyberia.church/api/ops/heartbeat}
TOKEN=${OPS_HEARTBEAT_TOKEN:-}

if [ -z "$TOKEN" ]; then
    echo "OPS_HEARTBEAT_TOKEN is unset (looked in $ENV_FILE). Nothing was sent." >&2
    exit 1
fi

# Processes to count, as name=pattern. The name is what config/monitoring.php
# asks for; the pattern is a pgrep -f regex.
#
# Prefer a systemd unit below where there is one. `pgrep -f` matches full
# command lines, including the command line of whatever is *running this
# script* — a pattern echoed by a wrapper shell counts itself and reports a
# dead daemon as alive, which is the one answer a monitor must never give.
# Empty by default for exactly that reason.
PROCESSES=${OPS_HEARTBEAT_PROCESSES:-""}

# systemd units to report, as name=unit. Both scopes are tried: `--user` first
# (LainOS runs as a user unit) and then system-wide. This is what a supervised
# daemon should be checked with — the supervisor already knows the answer, and
# it cannot be confused by a command line that merely mentions the daemon.
UNITS=${OPS_HEARTBEAT_UNITS:-"lainos=lainos.service"}

# Cron logs to age-check, as name=path. A cron that stops running leaves a log
# that stops growing, which is the only trace it leaves anywhere.
LOG_DIR=${OPS_HEARTBEAT_LOG_DIR:-/root/singularity/logs}
CRON_LOGS=${OPS_HEARTBEAT_CRON_LOGS:-"distribute-chats=$LOG_DIR/distribute_chats.log distribute-tg=$LOG_DIR/distribute.log"}

json_escape() {
    printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g' -e 's/\t/ /g' | tr -d '\000-\037'
}

# ------------------------------------------------------------------ machine --

read -r LOAD1 LOAD5 LOAD15 _ < /proc/loadavg
CPUS=$(nproc 2>/dev/null || echo 1)
UPTIME=$(cut -d. -f1 /proc/uptime)

MEM_TOTAL=$(awk '/^MemTotal:/ {print int($2/1024)}' /proc/meminfo)
MEM_AVAIL=$(awk '/^MemAvailable:/ {print int($2/1024)}' /proc/meminfo)
SWAP_TOTAL=$(awk '/^SwapTotal:/ {print int($2/1024)}' /proc/meminfo)
SWAP_FREE=$(awk '/^SwapFree:/ {print int($2/1024)}' /proc/meminfo)
SWAP_USED=$((SWAP_TOTAL - SWAP_FREE))

DISK_PATH=${OPS_HEARTBEAT_DISK:-/}
DISK_USED=$(df -P "$DISK_PATH" | awk 'NR==2 {gsub("%","",$5); print $5+0}')
DISK_FREE_GB=$(df -P -BG "$DISK_PATH" | awk 'NR==2 {gsub("G","",$4); print $4+0}')

# --------------------------------------------------------------- containers --

# State and restart count are read separately from the human status line,
# because "Restarting (1) 17 seconds ago" is the only outward sign of a crash
# loop and it does not appear in .State alone.
CONTAINERS="[]"

if command -v docker >/dev/null 2>&1; then
    CONTAINERS=$(docker ps -a --format '{{.Names}}\t{{.State}}\t{{.Status}}' 2>/dev/null | \
        while IFS=$'\t' read -r name state status; do
            [ -z "$name" ] && continue
            restarts=$(docker inspect -f '{{.RestartCount}}' "$name" 2>/dev/null || echo 0)
            printf '{"name":"%s","state":"%s","status":"%s","restarts":%s},' \
                "$(json_escape "$name")" \
                "$(json_escape "$state")" \
                "$(json_escape "$status")" \
                "${restarts:-0}"
        done)
    CONTAINERS="[${CONTAINERS%,}]"
fi

# --------------------------------------------------------------------- tmux --

# The bot and LainOS live in tmux with nothing supervising them, so a session
# that ends stays ended until a person notices.
TMUX_SESSIONS=$(tmux ls 2>/dev/null | cut -d: -f1 | while read -r s; do
    [ -z "$s" ] && continue
    printf '"%s",' "$(json_escape "$s")"
done)
TMUX_SESSIONS="[${TMUX_SESSIONS%,}]"

# ---------------------------------------------------------------- processes --

UNITS_JSON=""
for entry in $UNITS; do
    name=${entry%%=*}
    unit=${entry#*=}
    [ "$name" = "$entry" ] && continue

    state=$(systemctl --user is-active "$unit" 2>/dev/null)

    if [ -z "$state" ] || [ "$state" = "inactive" ] || [ "$state" = "unknown" ]; then
        system_state=$(systemctl is-active "$unit" 2>/dev/null)
        [ -n "$system_state" ] && state=$system_state
    fi

    [ -z "$state" ] && state="unknown"
    UNITS_JSON="${UNITS_JSON}\"$(json_escape "$name")\":\"$(json_escape "$state")\","
done
UNITS_JSON="{${UNITS_JSON%,}}"

PROCS=""
for entry in $PROCESSES; do
    name=${entry%%=*}
    pattern=${entry#*=}
    [ "$name" = "$entry" ] && continue
    # pgrep -fc prints 0 *and* exits 1 when nothing matches, so a `|| echo 0`
    # here appends a second zero and produces invalid JSON.
    count=$(pgrep -fc "$pattern" 2>/dev/null)
    count=$(printf '%s' "${count:-0}" | tr -cd '0-9')
    PROCS="${PROCS}\"$(json_escape "$name")\":${count:-0},"
done
PROCS="{${PROCS%,}}"

# --------------------------------------------------------------------- crons --

NOW=$(date +%s)
CRONS=""
for entry in $CRON_LOGS; do
    name=${entry%%=*}
    path=${entry#*=}
    [ "$name" = "$entry" ] && continue
    [ -f "$path" ] || continue
    mtime=$(stat -c %Y "$path" 2>/dev/null || echo "$NOW")
    size_mb=$(( $(stat -c %s "$path" 2>/dev/null || echo 0) / 1048576 ))
    CRONS="${CRONS}\"$(json_escape "$name")\":{\"log_age_seconds\":$((NOW - mtime)),\"log_size_mb\":${size_mb}},"
done
CRONS="{${CRONS%,}}"

# --------------------------------------------------------------------- send --

PAYLOAD=$(cat <<JSON
{
  "host": "$(json_escape "$(hostname)")",
  "uptime_seconds": ${UPTIME:-0},
  "cpus": ${CPUS:-1},
  "load": [${LOAD1:-0}, ${LOAD5:-0}, ${LOAD15:-0}],
  "memory": {"total_mb": ${MEM_TOTAL:-0}, "available_mb": ${MEM_AVAIL:-0}},
  "swap": {"total_mb": ${SWAP_TOTAL:-0}, "used_mb": ${SWAP_USED:-0}},
  "disk": {"path": "$(json_escape "$DISK_PATH")", "used_percent": ${DISK_USED:-0}, "free_gb": ${DISK_FREE_GB:-0}},
  "containers": ${CONTAINERS},
  "tmux": ${TMUX_SESSIONS},
  "processes": ${PROCS},
  "units": ${UNITS_JSON},
  "crons": ${CRONS}
}
JSON
)

# Installing this is the one time anyone reads the payload, so there is a way
# to see it without sending it.
if [ "${OPS_HEARTBEAT_PRINT:-}" = "1" ]; then
    printf '%s\n' "$PAYLOAD"
    exit 0
fi

# --fail so a 404 (wrong or missing token) is an error the cron log records,
# rather than a silent success that leaves the board blind.
if ! curl -sS --fail --max-time 20 \
    -H 'Content-Type: application/json' \
    -H 'Accept: application/json' \
    -H "X-Ops-Token: ${TOKEN}" \
    -d "$PAYLOAD" \
    "$URL" >/dev/null; then
    echo "heartbeat POST to $URL failed" >&2
    exit 1
fi
