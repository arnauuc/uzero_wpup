#!/bin/bash
# Creates one database and one user per fixture site. Throwaway, test-only values from the fixtures compose env.
set -euo pipefail
for site in $FIXTURE_SITES; do
    pw_var="DB_PASSWORD_${site^^}"
    mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" <<SQL
CREATE DATABASE IF NOT EXISTS \`$site\` CHARACTER SET utf8mb4;
CREATE USER IF NOT EXISTS '$site'@'%' IDENTIFIED BY '${!pw_var}';
GRANT ALL PRIVILEGES ON \`$site\`.* TO '$site'@'%';
SQL
done
