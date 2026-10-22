#!/usr/bin/env bash
# Provisions the Uzero wpup tool server (Debian 13). Run as root on the VM.
#
#   ./provision.sh                  run every step in order
#   ./provision.sh --list           list the steps
#   ./provision.sh --only 41        run only the step(s) whose name starts with 41
#   ./provision.sh --from 40        run from step 40 onwards
#
# Every step is idempotent: running it again must not change a correct system.
# Secrets are never stored here: the Tailscale auth key is read at run time
# (TS_AUTHKEY or an interactive prompt) and never written to disk.
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
. "$here/lib/common.sh"

only='' from='' list=0
while [[ $# -gt 0 ]]; do
    case "$1" in
        --only) only="${2:?--only needs a step prefix}"; shift 2 ;;
        --from) from="${2:?--from needs a step prefix}"; shift 2 ;;
        --list) list=1; shift ;;
        -h | --help) sed -n '2,11p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) die "Unknown option: $1" ;;
    esac
done

shopt -s nullglob
steps=("$here"/steps/[0-9][0-9]-*.sh)
[[ ${#steps[@]} -gt 0 ]] || die "No steps found in $here/steps"

if [[ $list -eq 1 ]]; then
    for s in "${steps[@]}"; do basename "$s"; done
    exit 0
fi

require_root

for s in "${steps[@]}"; do
    name="$(basename "$s")"
    [[ -z "$only" || "$name" == "$only"* ]] || continue
    [[ -z "$from" || ! "$name" < "$from" ]] || continue
    log ""
    log "==> $name"
    bash "$s"
done

log ""
log "Provisioning finished. Run deploy/checks/network.sh to verify the network."
