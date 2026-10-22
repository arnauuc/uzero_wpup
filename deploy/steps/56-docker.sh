#!/usr/bin/env bash
# Docker for the ephemeral staging (later phases, ADR-0006). Installed now so
# the network rules (step 44) are in place from day one. Set WPUP_SKIP_DOCKER=1
# to skip. Nobody is added to the `docker` group here: that group is
# root-equivalent, and which user may drive Docker is decided with the staging
# tasks (UZEROWPUP-100 and following). Operators must never be in it.
set -euo pipefail
# shellcheck source=../lib/common.sh
. "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/lib/common.sh"
require_root

if [[ "${WPUP_SKIP_DOCKER:-0}" == 1 ]]; then
    info "WPUP_SKIP_DOCKER=1: Docker is not installed."
    exit 0
fi

apt_update_once
apt_install docker.io docker-compose
systemctl enable docker >/dev/null

# daemon.json (published ports on 127.0.0.1) and the DOCKER-USER rules.
bash "$DEPLOY_DIR/steps/44-docker-guard.sh"
ok "Docker installed; nothing is published outward"
