#!/bin/bash
# Known-state snapshot/restore of the fixture sites (DB dump + docroot tarball) in docker/fixtures/.snapshots/.
#   snapshot.sh snapshot [site...]   store the current state
#   snapshot.sh restore  [site...]   restore the stored state (sites in parallel)
#   snapshot.sh has      <site>      exit 0 if a snapshot exists
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
snap="$here/.snapshots"
dc() { docker compose -f "$here/compose.yaml" "$@"; }
ALL_SITES="wp74 wp81 wp83"

cmd="${1:?usage: snapshot.sh snapshot|restore|has [site...]}"
shift
sites="${*:-$ALL_SITES}"

in_site() { # in_site <site> <command...>: run as the subscription user
    local site="$1"; shift
    dc exec -T -u "sub_${site}" "$site" "$@"
}

docroot() { echo "/var/www/vhosts/${1}.fixtures.test/httpdocs"; }

snapshot_one() {
    local site="$1" root
    root="$(docroot "$site")"
    in_site "$site" wp --path="$root" db export - | gzip > "$snap/$site.sql.gz.tmp"
    in_site "$site" tar -C "$root" -czf - . > "$snap/$site.tar.gz.tmp"
    mv "$snap/$site.sql.gz.tmp" "$snap/$site.sql.gz"
    mv "$snap/$site.tar.gz.tmp" "$snap/$site.tar.gz"
    echo "[$site] snapshot stored"
}

restore_one() {
    local site="$1" root
    root="$(docroot "$site")"
    [ -f "$snap/$site.tar.gz" ] || { echo "[$site] no snapshot" >&2; return 1; }
    in_site "$site" bash -c "find '$root' -mindepth 1 -delete"
    in_site "$site" tar -C "$root" -xzf - < "$snap/$site.tar.gz"
    in_site "$site" wp --path="$root" db reset --yes >/dev/null
    gunzip -c "$snap/$site.sql.gz" | in_site "$site" wp --path="$root" db import -
    echo "[$site] restored"
}

case "$cmd" in
    has) [ -f "$snap/${1:?site}.tar.gz" ] && [ -f "$snap/$1.sql.gz" ] ;;
    snapshot)
        mkdir -p "$snap"
        for s in $sites; do snapshot_one "$s"; done
        ;;
    restore)
        pids=()
        for s in $sites; do restore_one "$s" & pids+=($!); done
        rc=0
        for p in "${pids[@]}"; do wait "$p" || rc=1; done
        exit $rc
        ;;
    *) echo "unknown command: $cmd" >&2; exit 2 ;;
esac
