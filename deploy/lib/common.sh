#!/usr/bin/env bash
# Shared helpers for the deployment scripts (UZEROWPUP-126, UZEROWPUP-28).
# Source it; it does not run anything by itself. Every helper is idempotent.
# shellcheck shell=bash

DEPLOY_DIR="${DEPLOY_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
FILES_DIR="$DEPLOY_DIR/files"

# Non-secret settings (group names, paths...). Optional: see config.env.example.
if [[ -f "$DEPLOY_DIR/config.env" ]]; then
    # shellcheck disable=SC1091
    . "$DEPLOY_DIR/config.env"
fi

: "${WPUP_APP_DIR:=/opt/uzero-wpup}"
: "${WPUP_SERVICE_USER:=wpup}"
: "${WPUP_OPERATORS_GROUP:=wpup-operators}"
: "${WPUP_ADMINS_GROUP:=wpup-admins}"
: "${WPUP_WEB_PORT:=8080}"

if [[ -t 1 ]]; then
    C_OK=$'\033[32m' C_WARN=$'\033[33m' C_ERR=$'\033[31m' C_OFF=$'\033[0m'
else
    C_OK='' C_WARN='' C_ERR='' C_OFF=''
fi

log()  { printf '%s\n' "$*"; }
info() { printf '  %s\n' "$*"; }
ok()   { printf '  %sOK%s    %s\n' "$C_OK" "$C_OFF" "$*"; }
warn() { printf '  %sWARN%s  %s\n' "$C_WARN" "$C_OFF" "$*" >&2; }
die()  { printf '  %sFAIL%s  %s\n' "$C_ERR" "$C_OFF" "$*" >&2; exit 1; }

require_root() {
    [[ "$(id -u)" -eq 0 ]] || die "Run this as root (sudo)."
}

require_cmd() {
    local c
    for c in "$@"; do
        command -v "$c" >/dev/null 2>&1 || die "Missing command: $c"
    done
}

apt_install() {
    export DEBIAN_FRONTEND=noninteractive
    apt-get install -y --no-install-recommends "$@" >/dev/null
}

apt_update_once() {
    if [[ -z "${WPUP_APT_UPDATED:-}" ]]; then
        DEBIAN_FRONTEND=noninteractive apt-get update -qq
        export WPUP_APT_UPDATED=1
    fi
}

ensure_group() {
    getent group "$1" >/dev/null || groupadd --system "$1"
}

# install_file SRC DEST MODE OWNER:GROUP
# Copies only when the content or the metadata differ. Returns 0 when the file
# changed, 1 when it was already right (use `if install_file ...; then reload`).
install_file() {
    local src="$1" dest="$2" mode="$3" owner="$4" changed=1
    install -d -m 0755 "$(dirname "$dest")"
    if ! cmp -s "$src" "$dest" 2>/dev/null; then
        install -m "$mode" -o "${owner%%:*}" -g "${owner##*:}" "$src" "$dest"
        changed=0
    elif [[ "$(stat -c '%a %U:%G' "$dest")" != "$(printf '%o' "$((8#$mode))") $owner" ]]; then
        chmod "$mode" "$dest"
        chown "$owner" "$dest"
        changed=0
    fi
    return "$changed"
}

# render_file SRC DEST MODE OWNER:GROUP VAR=value...
# Replaces @VAR@ placeholders, then behaves like install_file.
render_file() {
    local src="$1" dest="$2" mode="$3" owner="$4" tmp pair
    shift 4
    tmp="$(mktemp)"
    cp "$src" "$tmp"
    for pair in "$@"; do
        # '|' is the sed delimiter: values must not contain it.
        sed -i "s|@${pair%%=*}@|${pair#*=}|g" "$tmp"
    done
    if grep -q '@[A-Z_]\+@' "$tmp"; then
        rm -f "$tmp"
        die "Unresolved placeholder in $src"
    fi
    local rc=0
    install_file "$tmp" "$dest" "$mode" "$owner" || rc=$?
    rm -f "$tmp"
    return "$rc"
}

# True when this SSH session comes from outside the tailnet (so closing the
# other doors could cut the session). False when not in an SSH session.
ssh_session_outside_tailnet() {
    [[ -n "${SSH_CONNECTION:-}" ]] || return 1
    local client="${SSH_CONNECTION%% *}"
    case "$client" in
        100.[6-9][0-9].* | 100.1[0-1][0-9].* | 100.12[0-7].* | fd7a:115c:a1e0:*) return 1 ;;
        *) return 0 ;;
    esac
}
