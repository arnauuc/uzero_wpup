#!/bin/bash
# Seeds one fixture site with WordPress + WooCommerce + known content. Idempotent: does nothing if already installed.
#   docker/fixtures/seed.sh wp81        (host: re-executes itself inside the container as the subscription user)
set -euo pipefail

if [ -z "${SITE_NAME:-}" ]; then
    # Host mode.
    site="${1:?usage: seed.sh <wp74|wp81|wp83>}"
    here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    exec docker compose -f "$here/compose.yaml" exec -T -e SEED_DEBUG="${SEED_DEBUG:-}" -u "sub_${site}" "$site" bash -c "$(cat "$here/seed.sh")"
fi

# Container mode (runs as the subscription user). SEED_DEBUG=1 traces every command.
[ -z "${SEED_DEBUG:-}" ] || set -x
DOCROOT="/var/www/vhosts/${SITE_DOMAIN}/httpdocs"
wp() { command wp --path="$DOCROOT" "$@"; }
wc() { wp wc --user="$FIXTURE_ADMIN_USER" "$@"; }

if wp option get wpup_fixture_seeded >/dev/null 2>&1; then
    echo "[$SITE_NAME] already seeded, skipping"
    exit 0
fi

# A previous seeding may have been interrupted: start from an empty docroot and database.
mkdir -p "$DOCROOT"
find "$DOCROOT" -mindepth 1 -delete
MYSQL_PWD="$DB_PASSWORD" mysql -h "$DB_HOST" -u "$DB_USER" -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4"

echo "[$SITE_NAME] downloading WordPress $WP_VERSION"
wp core download --version="$WP_VERSION" --force
wp config create --dbname="$DB_NAME" --dbuser="$DB_USER" --dbpass="$DB_PASSWORD" --dbhost="$DB_HOST" --skip-check
wp config set WP_DEBUG false --raw
wp config set DISABLE_WP_CRON true --raw
wp core install --url="$SITE_URL" --title="Fixture $SITE_NAME" \
    --admin_user="$FIXTURE_ADMIN_USER" --admin_password="$FIXTURE_ADMIN_PASSWORD" \
    --admin_email="$FIXTURE_ADMIN_EMAIL" --skip-email
wp rewrite structure '/%postname%/' --hard

echo "[$SITE_NAME] installing WooCommerce $WC_VERSION"
wp plugin install woocommerce --version="$WC_VERSION" --activate
wp option update woocommerce_currency EUR
wp option update woocommerce_default_country ES:B
wp option update woocommerce_onboarding_profile '{"skipped":true}' --format=json

# Test payment methods only (no real gateway) and a flat-rate shipping zone.
wc payment_gateway update cod --enabled=true >/dev/null
wc payment_gateway update cheque --enabled=true >/dev/null
zone_id="$(wc shipping_zone create --name="Spain" --porcelain)"
ZONE_ID="$zone_id" wp eval '$z = new WC_Shipping_Zone((int) getenv("ZONE_ID")); $z->add_location("ES", "country"); $z->save();'
wc shipping_zone_method create "$zone_id" --method_id=flat_rate >/dev/null

# One simple product and one variable product with two variations.
wc product create --name="Simple fixture product" --type=simple --regular_price=10.00 \
    --sku="FIX-SIMPLE" --status=publish >/dev/null
var_id="$(wc product create --name="Variable fixture product" --type=variable --sku="FIX-VARIABLE" --status=publish \
    --attributes='[{"name":"Size","visible":true,"variation":true,"options":["S","M"]}]' --porcelain)"
wc product_variation create "$var_id" --regular_price=20.00 --sku="FIX-VARIABLE-S" \
    --attributes='[{"name":"Size","option":"S"}]' >/dev/null
wc product_variation create "$var_id" --regular_price=22.00 --sku="FIX-VARIABLE-M" \
    --attributes='[{"name":"Size","option":"M"}]' >/dev/null

# Old plugin versions from wordpress.org so that updates are pending.
wp plugin install classic-editor --version=1.6.3 --activate
wp plugin install hello-dolly --version=1.6 --activate || wp plugin install hello-dolly --version=1.7.2 --activate

# Local plugin that declares "Requires PHP: 8.2" (cannot be activated on PHP < 8.2; it only declares the requirement).
cp -r /opt/fixtures/plugins/wpup-php-guard "$DOCROOT/wp-content/plugins/"
if php -r 'exit(version_compare(PHP_VERSION, "8.2", ">=") ? 0 : 1);'; then
    wp plugin activate wpup-php-guard
fi

# Fixture plugins/themes stay in the exact state recorded here; make them identifiable.
wp option update blogdescription "wpup fixture ($SITE_NAME, PHP $(php -r 'echo PHP_VERSION;'))"
wp option update wpup_fixture_seeded "$(date -u +%FT%TZ)"
echo "[$SITE_NAME] seeded"
