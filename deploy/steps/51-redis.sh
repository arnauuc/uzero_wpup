#!/usr/bin/env bash
# Redis (queues, locks, sessions): Unix socket only, no persistence.
set -euo pipefail
# shellcheck source=../lib/common.sh
. "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/lib/common.sh"
require_root

id "$WPUP_SERVICE_USER" >/dev/null 2>&1 || die "Run step 30 first (service user)."

restart=0
if install_file "$FILES_DIR/redis/wpup.conf" /etc/redis/wpup.conf 0640 redis:redis; then restart=1; fi

include='include /etc/redis/wpup.conf'
if ! grep -qxF "$include" /etc/redis/redis.conf; then
    printf '\n# Uzero wpup (deploy/steps/51-redis.sh): must stay the last line.\n%s\n' "$include" >>/etc/redis/redis.conf
    restart=1
fi

# The service user reaches the socket through the redis group.
if ! id -nG "$WPUP_SERVICE_USER" | tr ' ' '\n' | grep -qx redis; then
    usermod --append --groups redis "$WPUP_SERVICE_USER"
    restart=1
    ok "$WPUP_SERVICE_USER added to group redis (restart wpup-horizon and wpup-scheduler to pick it up)"
fi

systemctl enable redis-server >/dev/null
if [[ $restart -eq 1 ]]; then
    systemctl restart redis-server
fi

runuser -u "$WPUP_SERVICE_USER" -- redis-cli -s /run/redis/redis-server.sock ping 2>/dev/null | grep -qx PONG \
    || die "$WPUP_SERVICE_USER cannot reach Redis on /run/redis/redis-server.sock (needs redis-tools: apt install redis-tools)."
ok "Redis answers on the Unix socket"

[[ "$(redis-cli -s /run/redis/redis-server.sock config get save | tail -n1)" == "" ]] || die "Redis RDB persistence is on."
[[ "$(redis-cli -s /run/redis/redis-server.sock config get appendonly | tail -n1)" == "no" ]] || die "Redis AOF persistence is on."
ok "Redis has no persistence"

if ss -H -ltn | awk '{print $4}' | grep -qE ':6379$'; then
    die "Redis still listens on TCP 6379."
fi
ok "no TCP listener on 6379"
