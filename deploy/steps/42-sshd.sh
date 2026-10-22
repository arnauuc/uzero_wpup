#!/usr/bin/env bash
# sshd hardening drop-in (ADR-0003 section 2): keys only, no root, AllowGroups.
#
# Anti-lockout: refuses to apply it if nobody is in the allowed groups yet or
# nobody in them has an authorized_keys file. Add the operators first with
# deploy/bin/add-operator.sh (UZEROWPUP-28) or `useradd -m -G wpup-operators ...`
# plus their public key. The new configuration is checked with `sshd -t`
# before it is activated; if the check fails the drop-in is removed again.
set -euo pipefail
# shellcheck source=../lib/common.sh
. "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/lib/common.sh"
require_root

apt_update_once
apt_install openssh-server

ensure_group "$WPUP_OPERATORS_GROUP"
ensure_group "$WPUP_ADMINS_GROUP"

if [[ "${WPUP_SSHD_FORCE:-0}" != 1 ]]; then
    allowed=0
    for g in "$WPUP_OPERATORS_GROUP" "$WPUP_ADMINS_GROUP"; do
        members="$(getent group "$g" | cut -d: -f4 | tr ',' ' ')"
        for u in $members; do
            home="$(getent passwd "$u" | cut -d: -f6)"
            if [[ -s "$home/.ssh/authorized_keys" ]]; then
                allowed=$((allowed + 1))
            fi
        done
    done
    [[ $allowed -gt 0 ]] \
        || die "No user in $WPUP_OPERATORS_GROUP/$WPUP_ADMINS_GROUP has an authorized_keys file: applying this would lock everybody out. Create the operators first (or set WPUP_SSHD_FORCE=1)."
fi

dropin=/etc/ssh/sshd_config.d/00-wpup.conf
backup=''
[[ -f "$dropin" ]] && backup="$(mktemp)" && cp "$dropin" "$backup"

changed=1
if render_file "$FILES_DIR/sshd/00-wpup.conf" "$dropin" 0644 root:root \
    "OPERATORS_GROUP=$WPUP_OPERATORS_GROUP" "ADMINS_GROUP=$WPUP_ADMINS_GROUP"; then
    changed=0
fi

install -d -m 0755 /run/sshd
if ! sshd -t; then
    if [[ -n "$backup" ]]; then cp "$backup" "$dropin"; else rm -f "$dropin"; fi
    die "sshd -t failed: the drop-in was reverted."
fi
[[ -z "$backup" ]] || rm -f "$backup"

systemctl enable ssh >/dev/null
if [[ $changed -eq 0 ]]; then
    systemctl reload ssh
    ok "sshd reloaded with $dropin"
else
    ok "sshd drop-in already up to date"
fi

# Effective values (sshd -T applies the whole configuration, includes included).
eff="$(sshd -T)"
for want in 'passwordauthentication no' 'permitrootlogin no' 'kbdinteractiveauthentication no'; do
    grep -qi "^$want\$" <<<"$eff" || die "Effective sshd setting differs from '$want'. Another file wins over 00-wpup.conf."
done
ok "effective sshd settings verified"
