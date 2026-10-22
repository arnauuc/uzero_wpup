#!/usr/bin/env bash
# systemd units for Horizon and the scheduler, with the KEK drop-in
# (ADR-0002, ADR-0006). Re-run it after a KEK rotation to refresh the drop-in.
# It enables the units but starts them only when the application is installed
# (deploy/release.sh does the first start).
set -euo pipefail
# shellcheck source=../lib/common.sh
. "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/lib/common.sh"
require_root

reload=0
for u in wpup-horizon wpup-scheduler; do
    if install_file "$FILES_DIR/systemd/$u.service" "/etc/systemd/system/$u.service" 0644 root:root; then reload=1; fi
done
if [[ $reload -eq 1 ]]; then systemctl daemon-reload; fi

install -m 0755 "$DEPLOY_DIR/bin/wpup-kek-units" /usr/local/libexec/wpup-kek-units
result="$(/usr/local/libexec/wpup-kek-units)"
ok "KEK drop-in $result"

systemctl enable wpup-horizon.service wpup-scheduler.service >/dev/null 2>&1
if [[ -f "$WPUP_APP_DIR/artisan" ]]; then
    systemctl restart wpup-horizon.service wpup-scheduler.service
    ok "wpup-horizon and wpup-scheduler (re)started"
else
    info "The application is not installed yet: the units start with deploy/release.sh."
fi

if command -v systemd-analyze >/dev/null 2>&1; then
    systemd-analyze security wpup-horizon.service --no-pager 2>/dev/null | tail -n1 | sed 's/^/  /' || true
fi
