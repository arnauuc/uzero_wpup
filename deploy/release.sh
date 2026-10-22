#!/usr/bin/env bash
# Deploys a tagged version of the tool to /opt/uzero-wpup (first install and
# updates; docs/runbooks/actualitzacio.md). Run as root on the VM.
#
#   deploy/release.sh <tag> [--dry-run]
#
# What it does, in order (every step is safe to repeat):
#   1. Updates the ROOT-OWNED source clone ($WPUP_SRC_DIR, default
#      /usr/local/src/uzero-wpup) to the tag. Git credentials, if the repository
#      is private, live only with root. The service user never touches git and
#      never writes the scripts that root runs.
#   2. Copies the tree to $WPUP_APP_DIR (rsync, without .git, .env, storage,
#      vendor, node_modules), owned by the service user.
#   3. composer install --no-dev, asset build, `make tools` (pinned WP-CLI), as
#      the service user.
#   4. Migrations as wpup_owner (PostgreSQL DDL is transactional: a failed
#      migration leaves the schema as it was).
#   5. Caches, then restarts the workers (horizon:terminate lets the running
#      job finish) and the scheduler, and reloads PHP-FPM.
# Rolling back the code = running this script with the previous tag. It does NOT
# undo migrations: for those, restore the database (docs/runbooks/recuperacio.md).
set -euo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
. "$here/lib/common.sh"
require_root

tag="${1:-}"
[[ -n "$tag" ]] || die "usage: release.sh <tag> [--dry-run]"
dry=0
[[ "${2:-}" == "--dry-run" ]] && dry=1

: "${WPUP_SRC_DIR:=/usr/local/src/uzero-wpup}"
: "${WPUP_REPO_URL:=https://github.com/arnauuc/uzero_wpup.git}"
: "${WPUP_REQUIRE_SIGNED_TAGS:=0}"

as_wpup() { runuser -u "$WPUP_SERVICE_USER" -- env HOME=/var/lib/wpup "$@"; }
run() { if [[ $dry -eq 1 ]]; then info "[dry-run] $*"; else "$@"; fi; }

id "$WPUP_SERVICE_USER" >/dev/null 2>&1 || die "Run provision.sh first (step 30)."
[[ "$tag" =~ ^[A-Za-z0-9._/-]+$ ]] || die "Strange tag name: $tag"

# 1. Source clone owned by root.
if [[ ! -d "$WPUP_SRC_DIR/.git" ]]; then
    run git clone --quiet "$WPUP_REPO_URL" "$WPUP_SRC_DIR"
fi
run git -C "$WPUP_SRC_DIR" fetch --quiet --tags --prune origin
if [[ $dry -eq 0 ]]; then
    git -C "$WPUP_SRC_DIR" rev-parse -q --verify "refs/tags/$tag^{commit}" >/dev/null \
        || die "Tag $tag does not exist in $WPUP_SRC_DIR."
    if [[ "$WPUP_REQUIRE_SIGNED_TAGS" == 1 ]]; then
        git -C "$WPUP_SRC_DIR" verify-tag "$tag" || die "Tag $tag is not signed by a known key."
    fi
    git -C "$WPUP_SRC_DIR" checkout --quiet --detach "$tag"
    commit="$(git -C "$WPUP_SRC_DIR" rev-parse --short HEAD)"
    ok "source at $tag ($commit)"
fi

previous=''
[[ -f "$WPUP_APP_DIR/.release" ]] && previous="$(cat "$WPUP_APP_DIR/.release")"
[[ -z "$previous" ]] || info "currently deployed: $previous"

# 2. Copy the tree. The state of the installation is excluded and protected.
run rsync -a --delete --chown="$WPUP_SERVICE_USER:$WPUP_SERVICE_USER" \
    --exclude='/.git' --exclude='/.env' --exclude='/.release' --exclude='/storage/' \
    --exclude='/vendor/' --exclude='/node_modules/' --exclude='/bootstrap/cache/' \
    --exclude='/deploy/config.env' \
    "$WPUP_SRC_DIR/" "$WPUP_APP_DIR/"
# storage/ and bootstrap/cache keep their data; only create the skeleton once.
run rsync -a --ignore-existing --chown="$WPUP_SERVICE_USER:$WPUP_SERVICE_USER" "$WPUP_SRC_DIR/storage/" "$WPUP_APP_DIR/storage/"
run install -d -m 0750 -o "$WPUP_SERVICE_USER" -g "$WPUP_SERVICE_USER" "$WPUP_APP_DIR/bootstrap/cache"
run chmod 0750 "$WPUP_APP_DIR"
ok "code copied to $WPUP_APP_DIR"

[[ $dry -eq 0 ]] || { info "[dry-run] stopping here: composer, migrations and restarts are not simulated."; exit 0; }

[[ -f "$WPUP_APP_DIR/.env" ]] || die "$WPUP_APP_DIR/.env does not exist (step 54)."

# 3. Dependencies, assets and the pinned WP-CLI, all as the service user.
as_wpup composer install --working-dir="$WPUP_APP_DIR" --no-dev --optimize-autoloader --no-interaction --no-progress
if [[ -f "$WPUP_APP_DIR/package.json" && -f "$WPUP_APP_DIR/vite.config.js" ]]; then
    as_wpup bash -c "cd '$WPUP_APP_DIR' && npm ci --no-audit --no-fund && npm run build"
fi
as_wpup make -C "$WPUP_APP_DIR" tools
ok "dependencies and tools installed"

# APP_KEY (sessions and cookies only, never secrets): generated once on the server.
if ! grep -qE '^APP_KEY=.+' "$WPUP_APP_DIR/.env"; then
    as_wpup php "$WPUP_APP_DIR/artisan" key:generate --force
    ok "APP_KEY generated"
fi

# 4. Migrations as the schema owner (peer auth maps wpup -> wpup_owner).
as_wpup php "$WPUP_APP_DIR/artisan" migrate --database=pgsql_owner --force
ok "migrations applied as wpup_owner"

# 5. Caches, then restart.
as_wpup php "$WPUP_APP_DIR/artisan" optimize:clear
as_wpup php "$WPUP_APP_DIR/artisan" config:cache
as_wpup php "$WPUP_APP_DIR/artisan" route:cache
as_wpup php "$WPUP_APP_DIR/artisan" view:cache
as_wpup php "$WPUP_APP_DIR/artisan" event:cache

printf '%s (%s)\n' "$tag" "$commit" >"$WPUP_APP_DIR/.release"
chown "$WPUP_SERVICE_USER:$WPUP_SERVICE_USER" "$WPUP_APP_DIR/.release"

systemctl daemon-reload
if systemctl is-active --quiet wpup-horizon.service; then
    # Graceful: Horizon finishes the running job, exits and systemd starts it
    # again with the new code (Restart=always).
    as_wpup php "$WPUP_APP_DIR/artisan" horizon:terminate
else
    systemctl start wpup-horizon.service
fi
systemctl restart wpup-scheduler.service
systemctl reload php8.4-fpm.service
ok "workers and scheduler restarted ($tag)"

if [[ -n "$previous" ]]; then
    info "To go back to the previous code: deploy/release.sh <previous tag> ($previous)"
fi
