#!/usr/bin/env bash
# Fills Docker's DOCKER-USER chain so containers never answer to the outside
# (ADR-0003 section 1). Installed as /usr/local/libexec/wpup-docker-user and run
# by wpup-docker-user.service every time Docker (re)starts.
#
# Interplay with nftables: Docker on Debian 13 uses iptables-nft, so the rules
# below end up as nftables rules in Docker's own "ip filter" / "ip6 filter"
# tables, hooked into FORWARD. The "inet wpup" table of this tool is a separate
# table; both are evaluated and a drop in either wins. Docker recreates its
# chains on restart but never empties DOCKER-USER, which is why we flush and
# refill only that chain, in one go, and never touch the others.
#
# Policy: a forwarded packet is accepted to continue (RETURN to Docker's own
# rules) only if it belongs to an existing connection or comes from a Docker
# bridge. Everything else, i.e. a new connection arriving from a physical
# interface or from tailscale0 towards a container, is dropped.
set -euo pipefail

apply() {
    local ipt="$1"
    command -v "$ipt" >/dev/null 2>&1 || return 0
    # Docker has not created the chain (daemon stopped, or ip6tables disabled).
    "$ipt" -n -L DOCKER-USER >/dev/null 2>&1 || return 0

    "$ipt" -F DOCKER-USER
    "$ipt" -A DOCKER-USER -m conntrack --ctstate RELATED,ESTABLISHED -j RETURN
    "$ipt" -A DOCKER-USER -i docker0 -j RETURN
    "$ipt" -A DOCKER-USER -i br-+ -j RETURN
    "$ipt" -A DOCKER-USER -m comment --comment "wpup: no inbound to containers" -j DROP
}

apply iptables
apply ip6tables
