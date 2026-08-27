#!/usr/bin/env bash
# Prod deploy for the Laravel app (cyberia.church).
#
# Runs ON the prod host. Pulls master, then rebuilds/reconfigures inside the
# cyberia_church container: the repo is bind-mounted into it, and node/php exist
# only inside the container, never on the host.
#
# Usage: bash /root/singularity/scripts/deploy-prod.sh
#
# CI invokes exactly that path over SSH (see the deploy job in
# .github/workflows/ci.yml). Because the script pulls itself, edits to this file
# take effect on the deploy AFTER the one that pulls them.
set -euo pipefail

REPO_DIR=${REPO_DIR:-/root/singularity}
CONTAINER=${CONTAINER:-cyberia_church}
APP_DIR=${APP_DIR:-/var/www/html}

in_container() {
    docker exec -w "$APP_DIR" "$CONTAINER" "$@"
}

in_docs_container() {
    docker exec -w /singularity/docs "$CONTAINER" "$@"
}

cd "$REPO_DIR"

before=$(git rev-parse HEAD)
git pull --ff-only
after=$(git rev-parse HEAD)

if [ "$before" = "$after" ]; then
    echo "==> no new commits ($after) — running the full deploy anyway"
    # Manual re-deploys must still do something useful, so treat everything as changed.
    changed() { return 0; }
else
    echo "==> deploying $before -> $after"
    git --no-pager log --oneline "$before..$after"
    changed() { git diff --name-only "$before" "$after" -- "$@" | grep -q .; }
fi

if changed backend/laravel/composer.lock; then
    echo "==> composer install"
    in_container composer install --no-interaction --no-dev --optimize-autoloader
fi

if changed backend/laravel/package-lock.json; then
    echo "==> npm ci"
    in_container npm ci
fi

if changed backend/laravel/resources backend/laravel/package.json backend/laravel/vite.config.ts backend/laravel/routes; then
    echo "==> npm run build"
    # A stale route cache makes wayfinder generate @/routes from the OLD route
    # table, so new pages fail the vite build with UNLOADABLE_DEPENDENCY.
    in_container php artisan route:clear
    in_container npm run build
fi

if changed docs; then
    echo "==> build docs.cyberia.church"
    # The Laravel container already carries the production Node toolchain and
    # bind-mounts the whole repository at /singularity. The host intentionally
    # has no Node installation of its own.
    in_docs_container npm ci
    in_docs_container npm run build
    test -s "$REPO_DIR/docs/.vitepress/dist/index.html" || {
        echo "docs build produced no index.html, refusing to deploy" >&2
        exit 1
    }
fi

if changed backend/laravel/database/migrations; then
    echo "==> migrate"
    in_container php artisan migrate --force
fi

# Config is cached on prod: a stale bootstrap/cache/config.php makes Laravel
# ignore config/*.php AND .env entirely. Always refresh it. Routes are cached
# too, so rebuild that cache as well or new routes 404.
echo "==> config cache"
in_container php artisan config:clear
in_container php artisan config:cache
in_container php artisan route:cache

echo "==> queue restart"
in_container php artisan queue:restart

# nginx templates are expanded only when the container starts. Recreate just
# the proxy when its template or mounts changed; application-only deploys do
# not disturb it. The documentation output itself is a bind mount and needs no
# reload after subsequent content-only builds.
if changed services/blockscout/docker-compose/proxy services/blockscout/docker-compose/services/nginx.yml; then
    echo "==> recreate proxy"
    docker compose \
        -f "$REPO_DIR/services/blockscout/docker-compose/docker-compose.yml" \
        up -d --no-deps --force-recreate proxy
    docker exec proxy nginx -t
fi

if changed docs services/blockscout/docker-compose/proxy services/blockscout/docker-compose/services/nginx.yml; then
    echo "==> verify docs.cyberia.church"
    curl --fail --silent --show-error --retry 5 --retry-delay 2 \
        --max-time 20 https://docs.cyberia.church/ >/dev/null
fi

echo "==> deployed $(git rev-parse --short HEAD)"
