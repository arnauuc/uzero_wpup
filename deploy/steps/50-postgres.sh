#!/usr/bin/env bash
# PostgreSQL 17 (ADR-0004): Unix socket only, peer authentication, no password,
# roles wpup_owner (migrations) and wpup_app (runtime), database wpup.
set -euo pipefail
# shellcheck source=../lib/common.sh
. "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/lib/common.sh"
require_root

id "$WPUP_SERVICE_USER" >/dev/null 2>&1 || die "Run step 30 first (service user)."

ver="$(pg_lsclusters -h | awk '$2 == "main" {print $1; exit}')"
[[ -n "$ver" && "$ver" -ge 17 ]] || die "No PostgreSQL 17+ 'main' cluster found (pg_lsclusters)."
conf_dir="/etc/postgresql/$ver/main"

restart=0
install -d -m 0755 "$conf_dir/conf.d"
if install_file "$FILES_DIR/postgres/90-wpup.conf" "$conf_dir/conf.d/90-wpup.conf" 0644 postgres:postgres; then restart=1; fi

# pg_hba.conf and pg_ident.conf are replaced as a whole. Keep the original once.
for f in pg_hba.conf pg_ident.conf; do
    [[ -e "$conf_dir/$f.orig" ]] || cp -p "$conf_dir/$f" "$conf_dir/$f.orig"
    if install_file "$FILES_DIR/postgres/$f" "$conf_dir/$f" 0640 postgres:postgres; then restart=1; fi
done

if [[ $restart -eq 1 ]]; then
    systemctl restart "postgresql@${ver}-main"
    ok "PostgreSQL $ver configured (socket only, peer, log_statement=ddl)"
else
    ok "PostgreSQL configuration unchanged"
fi
systemctl enable postgresql >/dev/null

# Roles, database and default privileges (idempotent).
runuser -u postgres -- psql -X -q -v ON_ERROR_STOP=1 -d postgres -f - <"$FILES_DIR/postgres/roles.sql" >/dev/null
ok "roles wpup_owner and wpup_app, database wpup"

# The service user must reach the socket and be refused anywhere else.
if ! runuser -u "$WPUP_SERVICE_USER" -- psql -X -tA -h /var/run/postgresql -U wpup_app -d wpup -c 'select current_user' | grep -qx wpup_app; then
    die "$WPUP_SERVICE_USER cannot connect as wpup_app over the socket."
fi
ok "$WPUP_SERVICE_USER connects as wpup_app (peer, no password)"

if ss -H -ltn | awk '{print $4}' | grep -qE ':5432$'; then
    die "PostgreSQL still listens on TCP 5432."
fi
ok "no TCP listener on 5432"
