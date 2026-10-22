#!/usr/bin/env bash
# Installs and loads the nftables policy (ADR-0003 section 1) and the journald
# retention for the outbound connection log (section 4).
#
# Danger: after this step only tailscale0 reaches the VM. Refuses to run from an
# SSH session that does not come from the tailnet unless WPUP_FIREWALL_FORCE=1,
# and refuses when Tailscale is not running (nothing would be left to log in
# with except the Proxmox console).
set -euo pipefail
# shellcheck source=../lib/common.sh
. "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/lib/common.sh"
require_root

apt_update_once
apt_install nftables jq

if [[ "${WPUP_FIREWALL_FORCE:-0}" != 1 ]]; then
    if ssh_session_outside_tailnet; then
        die "This SSH session does not come from the tailnet: loading the firewall would cut it. Reconnect through Tailscale, use the Proxmox console, or set WPUP_FIREWALL_FORCE=1."
    fi
    if ! command -v tailscale >/dev/null 2>&1 \
        || ! tailscale status --json 2>/dev/null | jq -e '.BackendState == "Running"' >/dev/null; then
        die "Tailscale is not running. Run step 40 first (or set WPUP_FIREWALL_FORCE=1)."
    fi
fi

install -d -m 0755 /etc/nftables.d
install_file "$FILES_DIR/nftables/wpup.nft" /etc/nftables.d/wpup.nft 0644 root:root || true

# /etc/nftables.conf must only include our file: Debian's default starts with
# "flush ruleset", which would wipe the Docker and Tailscale tables on reload.
conf='#!/usr/sbin/nft -f
# Managed by Uzero wpup (deploy/steps/41-nftables.sh). No "flush ruleset" here:
# each component owns its own table and recreates only that one.
include "/etc/nftables.d/*.nft"'
if [[ "$(cat /etc/nftables.conf 2>/dev/null || true)" != "$conf" ]]; then
    printf '%s\n' "$conf" >/etc/nftables.conf
    chmod 0755 /etc/nftables.conf
    ok "/etc/nftables.conf rewritten"
fi

nft -c -f /etc/nftables.conf || die "nftables syntax check failed."
systemctl enable nftables >/dev/null
nft -f /etc/nftables.d/wpup.nft
ok "table inet wpup loaded"

# Retention of the outbound log (90 days).
if install_file "$FILES_DIR/journald/wpup.conf" /etc/systemd/journald.conf.d/wpup.conf 0644 root:root; then
    systemctl restart systemd-journald
    ok "journald retention set to 90 days"
fi
