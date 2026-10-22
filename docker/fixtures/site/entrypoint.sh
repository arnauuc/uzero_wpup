#!/bin/bash
# Fixture site entrypoint: creates the "subscription" user, configures sshd and Apache, then runs sshd + Apache.
set -euo pipefail

: "${SITE_USER:?}" "${SITE_UID:?}" "${SITE_DOMAIN:?}" "${HTTP_PORT:?}"
VHOST_DIR="/var/www/vhosts/${SITE_DOMAIN}"
DOCROOT="${VHOST_DIR}/httpdocs"

# Subscription user: non-root, bash shell, no password (SSH key only), owns the docroot.
if ! id "$SITE_USER" >/dev/null 2>&1; then
    groupadd -g "$SITE_UID" psacln
    useradd -u "$SITE_UID" -g psacln -d "$VHOST_DIR" -M -s /bin/bash -p '*' "$SITE_USER"
fi
# Account without a usable shell (like /bin/false on Plesk), for the driver's negative tests.
NOSHELL_USER="${SITE_USER}_nosh"
id "$NOSHELL_USER" >/dev/null 2>&1 || useradd -g psacln -d /nonexistent -M -s /bin/false -p '*' "$NOSHELL_USER"

mkdir -p "$DOCROOT" "${VHOST_DIR}/logs"
chown "$SITE_USER":psacln "$VHOST_DIR" "$DOCROOT" "${VHOST_DIR}/logs"

# PHP errors go to the subscription's logs/ directory, as on Plesk (web and CLI).
printf 'log_errors=On\nerror_log=%s/logs/php_error.log\n' "$VHOST_DIR" > /usr/local/etc/php/conf.d/zz-fixture-log.ini

# WP-CLI skips MySQL option files by default; the fixture DB needs the client's skip-ssl (see Dockerfile).
mkdir -p "${VHOST_DIR}/.wp-cli"
cat > "${VHOST_DIR}/.wp-cli/config.yml" <<'WPCLI'
db check: { defaults: true }
db cli: { defaults: true }
db create: { defaults: true }
db drop: { defaults: true }
db export: { defaults: true }
db import: { defaults: true }
db optimize: { defaults: true }
db query: { defaults: true }
db repair: { defaults: true }
db reset: { defaults: true }
db search: { defaults: true }
db size: { defaults: true }
db tables: { defaults: true }
WPCLI
chown -R "$SITE_USER":psacln "${VHOST_DIR}/.wp-cli"

# Authorized key: copied (root-owned) from the read-only bind mount; never baked into the image.
if [ -f /run/fixtures/authorized_key ]; then
    install -o root -g root -m 644 /run/fixtures/authorized_key "/etc/ssh/authorized_keys/${SITE_USER}"
    install -o root -g root -m 644 /run/fixtures/authorized_key "/etc/ssh/authorized_keys/${NOSHELL_USER}"
fi

# Host keys persist in a volume so known_hosts stays valid across container recreation.
[ -f /var/lib/hostkeys/ssh_host_ed25519_key ] || ssh-keygen -q -t ed25519 -N '' -f /var/lib/hostkeys/ssh_host_ed25519_key
cat > /etc/ssh/sshd_config <<SSHD
Port 22
HostKey /var/lib/hostkeys/ssh_host_ed25519_key
PermitRootLogin no
PasswordAuthentication no
KbdInteractiveAuthentication no
PubkeyAuthentication yes
UsePAM no
AllowUsers ${SITE_USER} ${NOSHELL_USER}
# Root-managed base key (like the hosting provider's) plus the account's own file, which the tool manages.
AuthorizedKeysFile /etc/ssh/authorized_keys/%u .ssh/authorized_keys
PrintMotd no
Subsystem sftp internal-sftp
SSHD

# OpenSSH >= 9.8 penalises sources with failed or unauthenticated connections. The
# negative tests cause both on purpose, so the test sites switch it off where supported.
if /usr/sbin/sshd -T -o PerSourcePenalties=no >/dev/null 2>&1; then
    echo 'PerSourcePenalties no' >> /etc/ssh/sshd_config
fi

# Apache listens on the same port that is published, so loopback requests from WordPress/WP-CLI work.
printf 'Listen %s\n' "$HTTP_PORT" > /etc/apache2/ports.conf
cat > /etc/apache2/sites-available/site.conf <<VHOST
<VirtualHost *:${HTTP_PORT}>
    ServerName ${SITE_DOMAIN}
    DocumentRoot ${DOCROOT}
    <Directory ${DOCROOT}>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
VHOST
a2ensite -q site >/dev/null
echo "ServerName ${SITE_DOMAIN}" > /etc/apache2/conf-enabled/servername.conf

/usr/sbin/sshd

# Serve the web as the subscription user, like a Plesk subscription with suexec/FPM.
export APACHE_RUN_USER="$SITE_USER" APACHE_RUN_GROUP=psacln
exec docker-php-entrypoint "$@"
