#!/usr/bin/env bash
# The operators' entry point (ADR-0002 section 1): /usr/local/bin/wpup, the
# root-owned helper /usr/local/libexec/wpup-cli, and the sudoers rule that lets
# the two operator groups run that helper and nothing else.
# Design and trade-off: docs/runbooks/desplegament.md, section 'CLI i KEK'.
set -euo pipefail
# shellcheck source=../lib/common.sh
. "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/lib/common.sh"
require_root
require_cmd visudo systemd-run

ensure_group "$WPUP_OPERATORS_GROUP"
ensure_group "$WPUP_ADMINS_GROUP"

install_file "$FILES_DIR/bin/wpup" /usr/local/bin/wpup 0755 root:root || true
render_file "$FILES_DIR/bin/wpup-cli" /usr/local/libexec/wpup-cli 0755 root:root \
    "OPERATORS_GROUP=$WPUP_OPERATORS_GROUP" "ADMINS_GROUP=$WPUP_ADMINS_GROUP" || true
ok "/usr/local/bin/wpup and /usr/local/libexec/wpup-cli installed"

# Validate the sudoers file BEFORE it goes into /etc/sudoers.d: a broken file
# there can lock sudo for everybody.
tmp="$(mktemp)"
trap 'rm -f "$tmp"' EXIT
sed -e "s|@OPERATORS_GROUP@|$WPUP_OPERATORS_GROUP|g" -e "s|@ADMINS_GROUP@|$WPUP_ADMINS_GROUP|g" \
    "$FILES_DIR/sudoers/wpup" >"$tmp"
visudo -cf "$tmp" >/dev/null || die "The sudoers file is not valid."
if install_file "$tmp" /etc/sudoers.d/wpup 0440 root:root; then
    ok "/etc/sudoers.d/wpup installed"
fi
visudo -c >/dev/null || die "visudo -c fails after installing /etc/sudoers.d/wpup."
ok "sudoers valid: operators may run only /usr/local/libexec/wpup-cli"
