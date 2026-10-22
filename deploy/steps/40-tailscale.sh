#!/usr/bin/env bash
# Installs Tailscale and joins the tailnet as tag:wpup-server (ADR-0003 section 1).
#
# The auth key must be a ONE-TIME, pre-approved key created with the tag
# tag:wpup-server. It is read from $TS_AUTHKEY or asked on the terminal
# (no echo), handed to tailscale through a 0600 file in RAM (/dev/shm) and
# removed immediately. It is never written to the repository or to the disk.
# A tagged node's key does not expire. If the node is already in the tailnet
# nothing is asked and nothing changes.
set -euo pipefail
# shellcheck source=../lib/common.sh
. "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/lib/common.sh"
require_root

: "${WPUP_TS_TAG:=tag:wpup-server}"
: "${WPUP_TS_HOSTNAME:=wpup}"

# 1. Package (official repository for Debian 13 "trixie").
if ! command -v tailscale >/dev/null 2>&1; then
    apt_update_once
    apt_install ca-certificates curl gnupg
    install -d -m 0755 /usr/share/keyrings
    curl -fsSL https://pkgs.tailscale.com/stable/debian/trixie.noarmor.gpg \
        -o /usr/share/keyrings/tailscale-archive-keyring.gpg
    curl -fsSL https://pkgs.tailscale.com/stable/debian/trixie.tailscale-keyring.list \
        -o /etc/apt/sources.list.d/tailscale.list
    DEBIAN_FRONTEND=noninteractive apt-get update -qq
    apt_install tailscale
    ok "tailscale installed"
else
    ok "tailscale already installed"
fi
apt_install jq

# 2. Same firewall backend as the host.
if install_file "$FILES_DIR/tailscale/tailscaled.default" /etc/default/tailscaled 0644 root:root; then
    systemctl restart tailscaled
    ok "tailscaled configured"
fi
systemctl enable --now tailscaled >/dev/null

# 3. Join (only if not already running).
state="$(tailscale status --json 2>/dev/null | jq -r '.BackendState // empty' || true)"
if [[ "$state" == "Running" ]]; then
    ok "already in the tailnet"
else
    key="${TS_AUTHKEY:-}"
    if [[ -z "$key" ]]; then
        [[ -t 0 ]] || die "No TS_AUTHKEY and no terminal to ask for it."
        read -r -s -p "One-time Tailscale auth key (tskey-auth-..., not shown): " key
        echo
    fi
    [[ "$key" == tskey-* ]] || die "That does not look like a Tailscale auth key."

    keyfile="$(mktemp /dev/shm/wpup-ts.XXXXXX)"
    trap 'shred -u "$keyfile" 2>/dev/null || rm -f "$keyfile"; unset key TS_AUTHKEY' EXIT
    chmod 0600 "$keyfile"
    printf '%s' "$key" >"$keyfile"
    unset key TS_AUTHKEY

    # --ssh=false: OpenSSH is the chosen way in (ADR-0003 option B was dropped).
    # No exit node, no routes, no DNS takeover of the resolver other than MagicDNS.
    tailscale up \
        --auth-key="file:$keyfile" \
        --advertise-tags="$WPUP_TS_TAG" \
        --hostname="$WPUP_TS_HOSTNAME" \
        --ssh=false \
        --exit-node= \
        --accept-routes=false \
        --advertise-exit-node=false
    ok "joined the tailnet as $WPUP_TS_TAG"
fi

# 4. The node must carry the tag, otherwise the ACL does not apply to it.
if tailscale status --json | jq -e --arg t "$WPUP_TS_TAG" '.Self.Tags // [] | index($t)' >/dev/null; then
    ok "node has tag $WPUP_TS_TAG"
else
    die "The node does not have tag $WPUP_TS_TAG. Remove it from the admin console and join again with a tagged key."
fi
