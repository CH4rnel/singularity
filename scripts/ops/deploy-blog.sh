#!/usr/bin/env bash
# Publish the Jekyll blog to blog.cyberia.church.
#
# The blog is static files served straight off the prod nginx
# (proxy/blog.conf.template -> /usr/share/nginx/blog, bind-mounted from
# /root/cyberia-blog). Prod has no ruby, so the site is built here and the
# result is rsynced. Nothing on prod rebuilds it: a new post is only live
# after this script runs.
set -euo pipefail

HOST="${BLOG_DEPLOY_HOST:-root@cyber.main}"
REMOTE_DIR="${BLOG_REMOTE_DIR:-/root/cyberia-blog}"
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../frontend/jekyll" && pwd)"
BUILD="$(mktemp -d)"
trap 'rm -rf "$BUILD"' EXIT

echo "==> building $SRC"
(cd "$SRC" && bundle exec jekyll build --destination "$BUILD")

# A build that produced no index would wipe the live site on the next rsync.
test -s "$BUILD/index.html" || { echo "build produced no index.html, refusing to deploy" >&2; exit 1; }

echo "==> deploying to $HOST:$REMOTE_DIR"
ssh "$HOST" "mkdir -p '$REMOTE_DIR'"
# Explicit modes rather than -a: the build lives in a mktemp dir (0700), and
# preserving that would hand nginx a directory it cannot read.
rsync -rltz --delete --chmod=D755,F644 "$BUILD/" "$HOST:$REMOTE_DIR/"

echo "==> verifying"
code="$(curl -sS -o /dev/null -w '%{http_code}' -m 20 https://blog.cyberia.church/)"
echo "https://blog.cyberia.church/ -> HTTP $code"
test "$code" = "200"
