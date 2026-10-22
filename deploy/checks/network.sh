#!/usr/bin/env bash
# Network checks that run ON THE TOOL VM, as root (ADR-0003, UZEROWPUP-126).
# Prints one OK / FAIL / SKIP line per check and exits 1 if any check failed.
#
#   sudo deploy/checks/network.sh
#
# Settings (environment, all optional):
#   WPUP_EGRESS_IP          expected public egress IP. Default: read from
#                           WPUP_EGRESS_IP in $WPUP_ENV_FILE.
#   WPUP_ENV_FILE           default /opt/uzero-wpup/.env
#   WPUP_EGRESS_ECHO_URL    service that answers with the caller's IP as plain
#                           text. Default https://api.ipify.org
#   WPUP_TS_TAG             default tag:wpup-server
#   WPUP_ALLOWED_WILDCARD   space separated "proto:port:process" sockets that
#                           may listen on every address (the firewall limits
#                           them to tailscale0). Default: sshd and tailscaled.
#
# This script does not change anything and never prints secrets.
set -uo pipefail

: "${WPUP_ENV_FILE:=/opt/uzero-wpup/.env}"
: "${WPUP_EGRESS_ECHO_URL:=https://api.ipify.org}"
: "${WPUP_TS_TAG:=tag:wpup-server}"
: "${WPUP_ALLOWED_WILDCARD:=tcp:22:sshd udp:41641:tailscaled udp:68:systemd-network}"

failures=0
pass() { printf 'OK    %s\n' "$*"; }
fail() { printf 'FAIL  %s\n' "$*"; failures=$((failures + 1)); }
skip() { printf 'SKIP  %s\n' "$*"; }

if [[ "$(id -u)" -ne 0 ]]; then
    echo "FAIL  run this script as root (it reads sockets, nftables and Docker)" >&2
    exit 1
fi
for c in jq ss ip curl nft; do
    command -v "$c" >/dev/null 2>&1 || { echo "FAIL  missing command: $c" >&2; exit 1; }
done

# --- 1. Tailscale: running, tagged, Funnel off, no exit node -----------------
if ts_json="$(tailscale status --json 2>/dev/null)" && [[ "$(jq -r '.BackendState // ""' <<<"$ts_json")" == "Running" ]]; then
    pass "tailscale is running"
else
    fail "tailscale is not running"
    ts_json='{}'
fi

if jq -e --arg t "$WPUP_TS_TAG" '(.Self.Tags // []) | index($t)' <<<"$ts_json" >/dev/null 2>&1; then
    pass "node has tag $WPUP_TS_TAG"
else
    fail "node does not have tag $WPUP_TS_TAG"
fi

if serve_json="$(tailscale serve status --json 2>/dev/null)"; then
    if jq -e '[(.AllowFunnel // {}) | to_entries[] | select(.value == true)] | length == 0' <<<"$serve_json" >/dev/null; then
        pass "Funnel is off"
    else
        fail "Funnel is ON (ADR-0003 forbids it): run 'tailscale funnel reset'"
    fi
else
    fail "could not read 'tailscale serve status' (is Serve configured?)"
fi

# `tailscale funnel status` is a second opinion: it lists public URLs when on.
if tailscale funnel status 2>/dev/null | grep -qi 'Funnel on'; then
    fail "tailscale funnel status reports Funnel on"
else
    pass "funnel status reports no public URL"
fi

exit_node="$(jq -r '.ExitNodeStatus.ID // empty' <<<"$ts_json" 2>/dev/null)"
prefs_exit="$(tailscale debug prefs 2>/dev/null | jq -r '[.ExitNodeID, .ExitNodeIP] | map(select(. != null and . != "")) | join(",")' 2>/dev/null || true)"
if [[ -z "$exit_node" && -z "$prefs_exit" ]]; then
    pass "no exit node in use"
else
    fail "an exit node is configured (${exit_node:-$prefs_exit}): run 'tailscale set --exit-node='"
fi

# Default route must not go through tailscale0, and the route to the Internet
# must not be one of Tailscale's policy-routing entries.
route="$(ip -o route get 1.1.1.1 2>/dev/null | head -n1)"
dev="$(sed -n 's/.* dev \([^ ]*\).*/\1/p' <<<"$route")"
if [[ -n "$dev" && "$dev" != tailscale0 ]]; then
    pass "default route to the Internet leaves through $dev"
else
    fail "default route to the Internet is '${dev:-unknown}' (must not be tailscale0)"
fi

# --- 2. nftables: input drops by default -------------------------------------
if nft list chain inet wpup input 2>/dev/null | grep -q 'policy drop'; then
    pass "nftables inet wpup input policy is drop"
else
    fail "nftables chain 'inet wpup input' missing or not policy drop"
fi
if nft list chain inet wpup input 2>/dev/null | grep -q 'tailscale0.*dport { 22, 443 }'; then
    pass "only ports 22 and 443 are open on tailscale0"
else
    fail "tailscale0 rule for ports 22 and 443 not found as expected"
fi

# --- 3. sshd effective configuration -----------------------------------------
if command -v sshd >/dev/null 2>&1; then
    eff="$(sshd -T 2>/dev/null)"
    bad=''
    grep -qi '^passwordauthentication no$' <<<"$eff" || bad="$bad passwordauthentication"
    grep -qi '^permitrootlogin no$' <<<"$eff" || bad="$bad permitrootlogin"
    grep -qi '^kbdinteractiveauthentication no$' <<<"$eff" || bad="$bad kbdinteractiveauthentication"
    grep -qi '^allowgroups ' <<<"$eff" || bad="$bad allowgroups"
    if [[ -z "$bad" ]]; then pass "sshd: keys only, no root, AllowGroups set"; else fail "sshd settings not as required:$bad"; fi
else
    fail "sshd not installed"
fi

# --- 4. No socket on every address, except the allowed ones ------------------
allowed_re=''
for entry in $WPUP_ALLOWED_WILDCARD; do allowed_re="$allowed_re|^$entry\$"; done
allowed_re="${allowed_re#|}"
unexpected=''
# Columns of `ss -H -lntup`: netid state recv-q send-q local peer process
while read -r netid _ _ _ local _ proc; do
    [[ -n "${netid:-}" ]] || continue
    addr="${local%:*}"
    port="${local##*:}"
    case "$addr" in
        127.* | '[::1]' | '[::1]%'*) continue ;;                     # loopback
        *%*) continue ;;                                              # bound to one interface (DHCP client)
        '*' | 0.0.0.0 | '[::]' | '::') ;;                              # wildcard: examine
        *) continue ;;                                                # a specific address
    esac
    name="$(sed -n 's/.*users:(("\([^"]*\)".*/\1/p' <<<"${proc:-}")"
    if ! grep -qE "$allowed_re" <<<"${netid%6}:$port:$name"; then
        unexpected="$unexpected ${netid}/${port}(${name:-?})"
    fi
done < <(ss -H -lntup 2>/dev/null)
if [[ -z "$unexpected" ]]; then
    pass "no unexpected socket listens on 0.0.0.0 or ::"
else
    fail "sockets listening on every address:$unexpected"
fi

# --- 5. Docker does not publish to 0.0.0.0 -----------------------------------
if command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1; then
    published="$(docker ps --format '{{.Names}}: {{.Ports}}' | grep -E '(0\.0\.0\.0|\[::\]):[0-9]+->' || true)"
    if [[ -z "$published" ]]; then
        pass "no container publishes a port on 0.0.0.0 or ::"
    else
        fail "containers publishing on every address: $(tr '\n' ';' <<<"$published")"
    fi
    if iptables -S DOCKER-USER 2>/dev/null | grep -q -- '-j DROP'; then
        pass "DOCKER-USER chain drops inbound traffic"
    else
        fail "DOCKER-USER chain has no DROP rule (systemctl status wpup-docker-user)"
    fi
else
    skip "Docker is not installed or not running: no containers to check"
fi

# --- 6. Egress IP ------------------------------------------------------------
expected="${WPUP_EGRESS_IP:-}"
if [[ -z "$expected" && -r "$WPUP_ENV_FILE" ]]; then
    expected="$(sed -n 's/^WPUP_EGRESS_IP=//p' "$WPUP_ENV_FILE" | tail -n1 | tr -d '"'"'"' \r')"
fi
if [[ -z "$expected" ]]; then
    fail "WPUP_EGRESS_IP is not set (neither in the environment nor in $WPUP_ENV_FILE)"
else
    actual="$(curl -fsS --max-time 15 "$WPUP_EGRESS_ECHO_URL" 2>/dev/null | tr -d ' \r\n')"
    if [[ -z "$actual" ]]; then
        fail "could not fetch the egress IP from $WPUP_EGRESS_ECHO_URL"
    elif [[ "$actual" == "$expected" ]]; then
        pass "egress IP matches WPUP_EGRESS_IP"
    else
        fail "egress IP $actual differs from WPUP_EGRESS_IP $expected (update the from= of the SSH keys!)"
    fi
fi

echo
if [[ $failures -eq 0 ]]; then
    echo "All network checks passed."
    exit 0
fi
echo "$failures check(s) failed."
exit 1
