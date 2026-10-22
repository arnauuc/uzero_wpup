#!/usr/bin/env bash
# Preflight (ADR-0002): root, Debian 13, and a virtual TPM 2.0 on the VM.
# The TPM must exist BEFORE the KEK is created, so this stops early. The second
# half of the TPM check (`systemd-creds has-tpm2`) needs the TPM libraries and
# runs in step 20, after the packages are installed.
set -euo pipefail
# shellcheck source=../lib/common.sh
. "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/lib/common.sh"
require_root

# shellcheck disable=SC1091
. /etc/os-release
if [[ "${ID:-}" != debian || "${VERSION_ID:-}" != 13 ]]; then
    die "This is for Debian 13 (found: ${PRETTY_NAME:-unknown})."
fi
ok "Debian 13"

[[ -d /run/systemd/system ]] || die "systemd is not the init system."
ok "systemd present"

if [[ ! -c /dev/tpmrm0 ]]; then
    die "/dev/tpmrm0 does not exist. Add a TPM 2.0 to the VM in Proxmox (tpmstate0, version v2.0, OVMF/UEFI firmware) BEFORE creating the KEK, and reboot. See docs/runbooks/desplegament.md, section 'Proxmox'."
fi
ok "/dev/tpmrm0 present"

if [[ -r /sys/class/tpm/tpm0/tpm_version_major ]]; then
    [[ "$(cat /sys/class/tpm/tpm0/tpm_version_major)" == 2 ]] || die "The TPM is not version 2.0."
    ok "TPM version 2.0"
fi

[[ -d /sys/firmware/efi ]] || warn "The VM does not boot with UEFI (OVMF): the ADR requires it for the TPM."
