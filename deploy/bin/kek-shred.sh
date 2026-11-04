#!/usr/bin/env bash
# Destroys the raw copy of a KEK once the custodian has stored it (ADR-0002 2c).
#
#   sudo deploy/bin/kek-shred.sh <version>      (default 1)
#
# The raw KEK (base64) is created by deploy/steps/52-kek.sh in RAM
# (/dev/shm/wpup-kek-v<N>.txt, mode 0600). The custodian copies it to his
# KeePassXC (docs/runbooks/desplegament.md) and only then runs this script. It
# asks for the word "guardada" and, if given, shreds and removes the file.
# The sealed credential in /etc/credstore.encrypted is NOT touched.
set -euo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=../lib/common.sh
. "$here/lib/common.sh"
require_root

version="${1:-1}"
[[ "$version" =~ ^[0-9]+$ ]] || die "usage: kek-shred.sh <version>"
raw="/dev/shm/wpup-kek-v${version}.txt"

if [[ ! -e "$raw" ]]; then
    ok "no raw copy of KEK v$version left on this machine"
    exit 0
fi
[[ -t 0 ]] || die "run this from a terminal."

read -r -p "Has the custodian saved KEK v$version in KeePassXC and checked it? Type 'guardada' to destroy the raw copy: " answer
[[ "$answer" == "guardada" ]] || die "cancelled; the raw copy is still in $raw."
shred -u "$raw"
ok "raw copy of KEK v$version destroyed"
