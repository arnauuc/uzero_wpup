#!/usr/bin/env bash
# Reachability test, run FROM ANOTHER MACHINE (not from the tool VM):
# verifies from where the tool server answers (ADR-0003, UZEROWPUP-126).
#
#   reachability.sh --open  <tailnet-name-or-100.x-ip> [--open ...] \
#                   --closed <lan-ip> [--closed <public-ip> ...]
#
# --open   targets that MUST accept TCP on ports 22 and 443. Run this from a
#          machine in the tailnet that belongs to group:wpup-operators.
# --closed targets that MUST NOT answer on any of the ports (the VM's LAN IP
#          from a LAN machine, its public egress IP from outside, ...). A
#          connection that is refused or times out counts as closed.
#
# Ports tested: 22 and 443 (set WPUP_TEST_PORTS="22 443 5432 6379 8080" to
# widen the list). Needs only bash. Prints OK/FAIL and exits 1 on any failure.
set -uo pipefail

ports="${WPUP_TEST_PORTS:-22 443}"
timeout_s="${WPUP_TEST_TIMEOUT:-5}"
open_targets=() closed_targets=()

while [[ $# -gt 0 ]]; do
    case "$1" in
        --open) open_targets+=("${2:?--open needs a host}"); shift 2 ;;
        --closed) closed_targets+=("${2:?--closed needs a host}"); shift 2 ;;
        -h | --help) sed -n '2,16p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Unknown option: $1" >&2; exit 2 ;;
    esac
done
[[ $((${#open_targets[@]} + ${#closed_targets[@]})) -gt 0 ]] || { echo "Nothing to test (see --help)." >&2; exit 2; }

failures=0

# Returns 0 when the TCP handshake completes within the timeout.
tcp_open() {
    timeout "$timeout_s" bash -c "exec 3<>/dev/tcp/$1/$2" 2>/dev/null
}

for host in "${open_targets[@]}"; do
    for port in $ports; do
        if [[ "$port" == 22 || "$port" == 443 ]]; then
            if tcp_open "$host" "$port"; then
                echo "OK    $host:$port answers (expected)"
            else
                echo "FAIL  $host:$port does not answer, but it should"; failures=$((failures + 1))
            fi
        fi
    done
done

for host in "${closed_targets[@]}"; do
    for port in $ports; do
        if tcp_open "$host" "$port"; then
            echo "FAIL  $host:$port ANSWERS, but it must be closed"; failures=$((failures + 1))
        else
            echo "OK    $host:$port is closed"
        fi
    done
done

exit $((failures > 0))
