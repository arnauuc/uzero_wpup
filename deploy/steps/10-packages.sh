#!/usr/bin/env bash
# Installs the packages of the tool server from the Debian 13 repositories
# (PHP 8.4, PostgreSQL 17, Redis, nginx, rsync, ssh client, Node, TPM libraries).
set -euo pipefail
# shellcheck source=../lib/common.sh
. "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/lib/common.sh"
require_root

apt_update_once

# Base tools used by the scripts and by the tool itself (ssh, rsync, make for `make tools`).
apt_install ca-certificates curl git unzip jq make rsync openssh-client openssh-server sudo \
    nftables acl gnupg util-linux

# TPM userspace (systemd-creds loads these libraries to talk to /dev/tpmrm0).
apt_install tpm2-tools

# PHP 8.4. sodium, pcntl and posix are compiled into php8.4-cli/-common on
# Debian; they are checked below.
apt_install php8.4-cli php8.4-fpm php8.4-common php8.4-pgsql php8.4-redis php8.4-intl \
    php8.4-zip php8.4-gd php8.4-mbstring php8.4-xml php8.4-curl php8.4-bcmath composer

# Database, queue, web.
apt_install postgresql postgresql-client redis-server nginx

# Frontend build (Vite) and, later, the Playwright verifier.
apt_install nodejs npm

missing=''
for ext in pgsql redis sodium pcntl posix intl zip gd mbstring openssl; do
    php -m | grep -qix "$ext" || missing="$missing $ext"
done
[[ -z "$missing" ]] || die "PHP extensions missing:$missing"
ok "PHP $(php -r 'echo PHP_VERSION;') with pgsql, redis, sodium, pcntl, posix, intl, zip, gd"

pgv="$(psql --version | sed -n 's/.* \([0-9]\+\)\..*/\1/p')"
[[ "${pgv:-0}" -ge 17 ]] || die "PostgreSQL 17 or newer is required (found: ${pgv:-none})."
ok "PostgreSQL $pgv"

# The default nginx site listens on 0.0.0.0:80; the web step replaces it, but do
# not leave it running in the meantime.
systemctl disable --now nginx >/dev/null 2>&1 || true
ok "packages installed"
