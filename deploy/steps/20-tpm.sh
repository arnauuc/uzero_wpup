#!/usr/bin/env bash
# Second half of the TPM check (ADR-0002): systemd must be able to use the TPM.
# Stops the deployment if it cannot. Never continue without it: the KEK would
# be sealed only with the host key (or not at all).
set -euo pipefail
# shellcheck source=../lib/common.sh
. "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/lib/common.sh"
require_root

[[ -c /dev/tpmrm0 ]] || die "/dev/tpmrm0 does not exist (see step 00)."

if ! systemd-creds has-tpm2 >/dev/null 2>&1; then
    systemd-creds has-tpm2 || true
    die "'systemd-creds has-tpm2' failed: systemd cannot use the TPM (see the lines above: firmware, driver or libraries)."
fi
ok "systemd-creds has-tpm2: yes"

# Real test: seal and open something with exactly the mode used for the KEK.
probe="$(mktemp /dev/shm/wpup-tpm.XXXXXX)"
trap 'rm -f "$probe" "$probe.cred"' EXIT
head -c 16 /dev/urandom | base64 >"$probe"
systemd-creds encrypt --with-key=tpm2+host --name=wpup-probe "$probe" "$probe.cred" \
    || die "Could not seal with --with-key=tpm2+host."
systemd-creds decrypt --name=wpup-probe "$probe.cred" - | cmp -s - "$probe" \
    || die "Sealed credential does not decrypt back to the same value."
ok "seal/unseal round trip with tpm2+host works"
