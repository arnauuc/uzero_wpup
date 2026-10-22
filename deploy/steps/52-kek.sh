#!/usr/bin/env bash
# Generates and seals the first KEK (ADR-0002 sections 2 and 2b).
#
# - 32 random bytes, base64 (the format of the custodian's KeePassXC entry).
# - Sealed with `systemd-creds encrypt --with-key=tpm2+host` (never `auto`: if
#   the TPM fails, this fails, it never downgrades silently) into
#   /etc/credstore.encrypted/wpup-kek-v1, root only.
# - The RAW key is written only to RAM (/dev/shm/wpup-kek-v1.txt, 0600) for the
#   custodian to copy to KeePassXC. It is destroyed with deploy/bin/kek-shred.sh
#   after the custodian confirms. Re-running this step never creates a second
#   KEK and never overwrites an existing one (that would orphan every secret).
#
# Rotations to v2+ are done by hand (docs/runbooks/rotacio-kek.md).
#
# Recovery mode (docs/runbooks/recuperacio.md): with WPUP_RECOVERY=1 it does NOT
# generate anything. It asks (no echo) for the raw KEK that the custodian keeps in
# KeePassXC and seals THAT key with the new VM's TPM. Use WPUP_KEK_VERSION=<n>
# for each version the restored database still uses (default 1).
set -euo pipefail
# shellcheck source=../lib/common.sh
. "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/lib/common.sh"
require_root

version="${WPUP_KEK_VERSION:-1}"
[[ "$version" =~ ^[0-9]+$ ]] || die "WPUP_KEK_VERSION must be a number."
recovery="${WPUP_RECOVERY:-0}"
name="wpup-kek-v$version"
sealed="/etc/credstore.encrypted/$name"
raw="/dev/shm/$name.txt"

# Both halves of the TPM check, again: the KEK must never be sealed without them.
[[ -c /dev/tpmrm0 ]] || die "/dev/tpmrm0 does not exist: add the virtual TPM in Proxmox first."
systemd-creds has-tpm2 >/dev/null 2>&1 || die "systemd-creds has-tpm2 failed: cannot seal the KEK."

install -d -m 0700 -o root -g root /etc/credstore.encrypted

if [[ -e "$sealed" ]]; then
    # Prove that it still opens and holds 32 bytes (44 base64 characters).
    len="$(systemd-creds decrypt --name="$name" "$sealed" - | tr -d '\n' | wc -c)"
    [[ "$len" -eq 44 ]] || die "$sealed exists but does not decrypt to a base64 32-byte key (length $len). Do NOT overwrite it without reading docs/runbooks/recuperacio.md."
    ok "$name already sealed and readable"
    if [[ -e "$raw" ]]; then
        warn "the raw copy is still in $raw: after the custodian saves it, run deploy/bin/kek-shred.sh"
    fi
    exit 0
fi

# Refuse if the database already holds secrets sealed by a KEK we no longer have.
if [[ "$recovery" != 1 ]] && command -v psql >/dev/null 2>&1 && runuser -u postgres -- psql -X -tA -d wpup -c "select to_regclass('public.secrets') is not null" 2>/dev/null | grep -qx t; then
    count="$(runuser -u postgres -- psql -X -tA -d wpup -c 'select count(*) from secrets' 2>/dev/null || echo 0)"
    if [[ "${count:-0}" -gt 0 ]]; then
        die "The database already has $count secrets but there is no KEK: this is a recovery, follow docs/runbooks/recuperacio.md (the raw KEK comes from KeePassXC). Do not generate a new one."
    fi
fi

umask 077
if [[ "$recovery" == 1 ]]; then
    [[ -t 0 ]] || die "Recovery mode needs a terminal to read the KEK."
    read -r -s -p "Raw KEK v$version from KeePassXC (44 characters, not shown): " key
    echo
    trap 'unset key; shred -u "$raw" 2>/dev/null || true' EXIT
    [[ "${#key}" -eq 44 ]] || die "The KEK must have 44 characters (base64 of 32 bytes)."
    [[ "$(printf '%s' "$key" | base64 -d 2>/dev/null | wc -c)" -eq 32 ]] || die "That is not base64 of 32 bytes."
    printf '%s' "$key" >"$raw"
    unset key
else
    openssl rand -base64 32 | tr -d '\n' >"$raw"
fi
chmod 0600 "$raw"

systemd-creds encrypt --with-key=tpm2+host --name="$name" "$raw" "$sealed.part"
chmod 0600 "$sealed.part"
chown root:root "$sealed.part"

# Verify before putting it in place.
systemd-creds decrypt --name="$name" "$sealed.part" - | cmp -s - "$raw" \
    || { rm -f "$sealed.part"; die "The sealed KEK does not decrypt to the generated one."; }
mv "$sealed.part" "$sealed"
ok "$name sealed with tpm2+host in $sealed"

if [[ "$recovery" == 1 ]]; then
    shred -u "$raw"
    ok "recovery: KEK v$version re-sealed with this VM's TPM; the raw copy was destroyed"
    exit 0
fi

cat <<EOF

  IMPORTANT: the raw KEK is in $raw (RAM, root only).
  The custodian must now:
    1. read it:   sudo cat $raw
    2. save it as a new entry in his KeePassXC (see docs/runbooks/desplegament.md, step 'Custòdia')
    3. destroy it: sudo deploy/bin/kek-shred.sh $version
  Until then the KEK exists only in this VM's TPM.

EOF
