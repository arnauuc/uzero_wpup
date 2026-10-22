#!/usr/bin/env bash
# Docker must not publish anything outward (ADR-0003 section 1):
#  - daemon.json: published ports bind to 127.0.0.1 by default ("ip");
#  - DOCKER-USER rules (wpup-docker-user.service) drop inbound traffic to
#    containers on every restart of the daemon.
# This step does NOT install Docker (that is step 56 of UZEROWPUP-28). Without
# Docker it still installs the unit; it activates itself when dockerd exists.
set -euo pipefail
# shellcheck source=../lib/common.sh
. "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/lib/common.sh"
require_root

restart_docker=0
if install_file "$FILES_DIR/docker/daemon.json" /etc/docker/daemon.json 0644 root:root; then
    restart_docker=1
    ok "/etc/docker/daemon.json installed"
fi

install_file "$FILES_DIR/systemd/wpup-docker-user.sh" /usr/local/libexec/wpup-docker-user 0755 root:root || true
if install_file "$FILES_DIR/systemd/wpup-docker-user.service" /etc/systemd/system/wpup-docker-user.service 0644 root:root; then
    systemctl daemon-reload
fi

if command -v dockerd >/dev/null 2>&1; then
    systemctl enable wpup-docker-user.service >/dev/null
    if [[ $restart_docker -eq 1 ]] && systemctl is-active --quiet docker; then
        warn "daemon.json changed: restarting Docker (running containers restart)"
        systemctl restart docker
    fi
    systemctl restart wpup-docker-user.service
    ok "DOCKER-USER rules applied"
else
    info "Docker is not installed yet: the unit will take effect once it is."
fi
