#!/usr/bin/env bash
# Web panel plumbing (ADR-0008): PHP-FPM pool as `wpup` and nginx on 127.0.0.1
# only, plus the production .env (created once, 0600, never overwritten).
# Tailscale Serve (step 43) publishes the loopback port to the tailnet.
#
# nginx workers run as `wpup` so they can read /opt/uzero-wpup (0750) without
# opening that directory to any other user. Trade-off: nginx and the app share
# an identity; there is no other user with a reason to read the code.
set -euo pipefail
# shellcheck source=../lib/common.sh
. "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/lib/common.sh"
require_root

id "$WPUP_SERVICE_USER" >/dev/null 2>&1 || die "Run step 30 first (service user)."

# PHP settings for CLI and FPM, and the dedicated pool (the default 'www' pool
# is disabled).
reload_fpm=0
for sapi in cli fpm; do
    if install_file "$FILES_DIR/php/90-wpup.ini" "/etc/php/8.4/$sapi/conf.d/90-wpup.ini" 0644 root:root; then reload_fpm=1; fi
done
if install_file "$FILES_DIR/php/wpup-fpm.conf" /etc/php/8.4/fpm/pool.d/wpup.conf 0644 root:root; then reload_fpm=1; fi
if [[ -e /etc/php/8.4/fpm/pool.d/www.conf ]]; then
    mv /etc/php/8.4/fpm/pool.d/www.conf /etc/php/8.4/fpm/pool.d/www.conf.disabled
    reload_fpm=1
fi
systemctl enable php8.4-fpm >/dev/null
if [[ $reload_fpm -eq 1 ]]; then systemctl restart php8.4-fpm; fi
ok "PHP-FPM pool wpup on /run/php/wpup-fpm.sock"

# nginx: workers as wpup, only our site, listening on loopback.
reload_nginx=0
if ! grep -qE "^user[[:space:]]+$WPUP_SERVICE_USER;" /etc/nginx/nginx.conf; then
    sed -i -E "s/^user[[:space:]]+[^;]+;/user $WPUP_SERVICE_USER;/" /etc/nginx/nginx.conf
    reload_nginx=1
fi
rm -f /etc/nginx/sites-enabled/default
if render_file "$FILES_DIR/nginx/wpup.conf" /etc/nginx/sites-available/wpup 0644 root:root "WEB_PORT=$WPUP_WEB_PORT"; then reload_nginx=1; fi
ln -sfn /etc/nginx/sites-available/wpup /etc/nginx/sites-enabled/wpup
nginx -t || die "nginx -t failed."
systemctl enable nginx >/dev/null
if systemctl is-active --quiet nginx; then
    if [[ $reload_nginx -eq 1 ]]; then systemctl reload nginx; fi
else
    systemctl start nginx
fi
ok "nginx on 127.0.0.1:$WPUP_WEB_PORT"

# .env: only when absent. APP_URL comes from WPUP_APP_URL (the Tailscale name).
env_file="$WPUP_APP_DIR/.env"
if [[ -e "$env_file" ]]; then
    ok ".env already exists, left untouched"
else
    app_url="${WPUP_APP_URL:-https://CHANGE-ME.example.ts.net}"
    [[ -n "${WPUP_APP_URL:-}" ]] || warn "WPUP_APP_URL is not set: edit APP_URL in $env_file (https://<node>.<tailnet>.ts.net)"
    tmp="$(mktemp)"
    trap 'rm -f "$tmp"' EXIT
    sed "s|@APP_URL@|$app_url|" "$FILES_DIR/env/env.production" >"$tmp"
    install -m 0600 -o "$WPUP_SERVICE_USER" -g "$WPUP_SERVICE_USER" "$tmp" "$env_file"
    ok ".env created (0600 $WPUP_SERVICE_USER); WPUP_EGRESS_IP is still empty: fill it in"
fi
chmod 0600 "$env_file" 2>/dev/null || true
