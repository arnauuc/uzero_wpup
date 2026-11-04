#!/usr/bin/env bash
# Users, groups and directories (ADR-0002 section 1).
#
# - Service user `wpup`: owns /opt/uzero-wpup (0750). It runs Horizon, the
#   scheduler, PHP-FPM and the CLI. No login shell.
# - Operators: one Unix user each (deploy/bin/add-operator.sh), members of
#   wpup-operators or wpup-admins and of nothing else. They cannot read the app,
#   the .env, the credentials or the database.
set -euo pipefail
# shellcheck source=../lib/common.sh
. "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/lib/common.sh"
require_root

ensure_group "$WPUP_OPERATORS_GROUP"
ensure_group "$WPUP_ADMINS_GROUP"

if ! id "$WPUP_SERVICE_USER" >/dev/null 2>&1; then
    useradd --system --user-group --home-dir /var/lib/wpup --no-create-home \
        --shell /usr/sbin/nologin --comment "Uzero wpup service" "$WPUP_SERVICE_USER"
    ok "service user $WPUP_SERVICE_USER created"
else
    ok "service user $WPUP_SERVICE_USER exists"
fi

# Home and state of the service user (also created by StateDirectory=wpup).
install -d -m 0700 -o "$WPUP_SERVICE_USER" -g "$WPUP_SERVICE_USER" /var/lib/wpup

# Application directory: only the service user (0750).
install -d -m 0750 -o "$WPUP_SERVICE_USER" -g "$WPUP_SERVICE_USER" "$WPUP_APP_DIR"
ok "$WPUP_APP_DIR is 0750 $WPUP_SERVICE_USER"

# Root-only directories for the sealed credentials.
install -d -m 0700 -o root -g root /etc/credstore.encrypted

# Operators must not be able to enter the service's world even by mistake.
for g in "$WPUP_SERVICE_USER" redis postgres docker adm sudo; do
    getent group "$g" >/dev/null || continue
    for member in $(getent group "$g" | cut -d: -f4 | tr ',' ' '); do
        if id -nG "$member" | tr ' ' '\n' | grep -qxE "$WPUP_OPERATORS_GROUP|$WPUP_ADMINS_GROUP"; then
            warn "operator $member is in group $g: operators should not be (remove with: gpasswd -d $member $g)"
        fi
    done
done
ok "users and directories ready"
