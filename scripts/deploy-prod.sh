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
    in_container npm run build
fi

if changed backend/laravel/database/migrations; then
    echo "==> migrate"
    in_container php artisan migrate --force
fi

# Config is cached on prod: a stale bootstrap/cache/config.php makes Laravel
# ignore config/*.php AND .env entirely. Always refresh it.
echo "==> config cache"
in_container php artisan config:clear
in_container php artisan config:cache

echo "==> queue restart"
in_container php artisan queue:restart

echo "==> deployed $(git rev-parse --short HEAD)"
