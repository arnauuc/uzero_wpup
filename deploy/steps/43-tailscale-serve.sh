#!/usr/bin/env bash
# Publishes the web panel (and /horizon, same Laravel app) to the tailnet with
# Tailscale Serve (ADR-0003 section 3): HTTPS 443 on the tailnet name ->
# http://127.0.0.1:$WPUP_WEB_PORT. Funnel stays off and is checked.
#
# Needs MagicDNS and HTTPS certificates enabled in the tailnet admin console.
set -euo pipefail
# shellcheck source=../lib/common.sh
. "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/lib/common.sh"
require_root
require_cmd tailscale jq

backend="http://127.0.0.1:${WPUP_WEB_PORT}"

# `serve reset` first: it clears any previous mapping (and any Funnel flag),
# so the final state is exactly one mapping, whatever was there before.
tailscale serve reset
tailscale serve --bg --yes --https=443 "$backend"

# Never allow Funnel (it would publish the panel to the Internet).
tailscale funnel reset >/dev/null 2>&1 || true
if tailscale serve status --json | jq -e '[.AllowFunnel // {} | to_entries[] | select(.value == true)] | length > 0' >/dev/null; then
    die "Funnel is ON. It must never be enabled (ADR-0003)."
fi
ok "Serve: https://<node>.<tailnet>.ts.net -> $backend (Funnel off)"
