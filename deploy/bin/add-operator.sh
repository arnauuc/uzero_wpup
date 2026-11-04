#!/usr/bin/env bash
# Creates (or updates) the Unix user of an operator (ADR-0002, ADR-0003).
#
#   sudo deploy/bin/add-operator.sh <unix_user> <public_key_file> [--admin]
#
# The user gets: no password (locked), the SSH public key, and membership of
# wpup-operators (or wpup-admins with --admin). NOTHING else: no sudo, no
# docker, no adm, and not the groups of the service (wpup, redis, postgres), so
# it cannot read /opt/uzero-wpup, the .env, the credentials or the database.
# The matching row in `users` is created afterwards from the CLI:
#   wpup user:add ...   (the first admin: sudo -u wpup php artisan user:bootstrap <unix_user>)
# Idempotent: running it again only re-applies the key and the group.
set -euo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=../lib/common.sh
. "$here/lib/common.sh"
require_root

user="${1:-}" keyfile="${2:-}" role="${3:-}"
[[ -n "$user" && -n "$keyfile" ]] || die "usage: add-operator.sh <unix_user> <public_key_file> [--admin]"
[[ "$user" =~ ^[a-z][a-z0-9_-]{1,30}$ ]] || die "invalid user name: $user"
[[ "$user" != "$WPUP_SERVICE_USER" && "$user" != root ]] || die "reserved name: $user"
[[ -r "$keyfile" ]] || die "cannot read $keyfile"
ssh-keygen -l -f "$keyfile" >/dev/null 2>&1 || die "$keyfile is not a valid SSH public key."
[[ "$(wc -l <"$keyfile")" -le 1 ]] || die "$keyfile must hold exactly one key."

group="$WPUP_OPERATORS_GROUP"
other="$WPUP_ADMINS_GROUP"
if [[ "$role" == "--admin" ]]; then group="$WPUP_ADMINS_GROUP"; other="$WPUP_OPERATORS_GROUP"; fi

ensure_group "$WPUP_OPERATORS_GROUP"
ensure_group "$WPUP_ADMINS_GROUP"

if ! id "$user" >/dev/null 2>&1; then
    useradd --create-home --shell /bin/bash --groups "$group" "$user"
    passwd --lock "$user" >/dev/null
    ok "user $user created"
else
    usermod --append --groups "$group" "$user"
    if id -nG "$user" | tr ' ' '\n' | grep -qx "$other"; then
        gpasswd --delete "$user" "$other" >/dev/null
    fi
    ok "user $user updated"
fi

home="$(getent passwd "$user" | cut -d: -f6)"
install -d -m 0700 -o "$user" -g "$user" "$home/.ssh"
install -m 0600 -o "$user" -g "$user" "$keyfile" "$home/.ssh/authorized_keys"
chmod 0700 "$home"
ok "key installed in $home/.ssh/authorized_keys"

extra="$(id -nG "$user" | tr ' ' '\n' | grep -vxE "$user|$group" || true)"
[[ -z "$extra" ]] || warn "$user belongs to other groups: $(echo "$extra" | tr '\n' ' ') (operators should not)"
