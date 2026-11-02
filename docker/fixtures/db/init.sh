#!/bin/bash
# Creates one database and one user per fixture site. Throwaway, test-only values from the fixtures compose env.
set -euo pipefail

# Works for the MariaDB and the MySQL image: they differ in client binary, root password variable and auth syntax.
if command -v mariadb >/dev/null 2>&1; then
    client=mariadb
    root_password="$MARIADB_ROOT_PASSWORD"
    auth="IDENTIFIED BY"
else
    client=mysql
    root_password="$MYSQL_ROOT_PASSWORD"
    # MySQL 8 defaults to caching_sha2_password, whose full authentication needs TLS or RSA key exchange; the
    # MariaDB client in the site images runs with skip-ssl, so the test users use mysql_native_password.
    auth="IDENTIFIED WITH mysql_native_password BY"
fi

for site in $FIXTURE_SITES; do
    pw_var="DB_PASSWORD_${site^^}"
    "$client" -uroot -p"$root_password" <<SQL
CREATE DATABASE IF NOT EXISTS \`$site\` CHARACTER SET utf8mb4;
CREATE USER IF NOT EXISTS '$site'@'%' $auth '${!pw_var}';
GRANT ALL PRIVILEGES ON \`$site\`.* TO '$site'@'%';
SQL
done
