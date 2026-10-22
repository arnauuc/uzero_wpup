#!/usr/bin/env bash
# Checks of the tool server itself, ON THE VM, as root (UZEROWPUP-28).
# One OK/FAIL/SKIP line per check; exit 1 if anything fails. Changes nothing.
#
#   sudo deploy/checks/server.sh [operator_unix_user ...]
#
# With operator names it also proves that they CANNOT read the application, the
# .env, the sealed KEK, the state directory or the database socket.
set -uo pipefail

app_dir="${WPUP_APP_DIR:-/opt/uzero-wpup}"
service_user="${WPUP_SERVICE_USER:-wpup}"
failures=0
pass() { printf 'OK    %s\n' "$*"; }
fail() { printf 'FAIL  %s\n' "$*"; failures=$((failures + 1)); }
skip() { printf 'SKIP  %s\n' "$*"; }

[[ "$(id -u)" -eq 0 ]] || { echo "FAIL  run as root" >&2; exit 1; }

# --- TPM and KEK -------------------------------------------------------------
[[ -c /dev/tpmrm0 ]] && pass "/dev/tpmrm0 present" || fail "/dev/tpmrm0 missing"
systemd-creds has-tpm2 >/dev/null 2>&1 && pass "systemd-creds has-tpm2" || fail "systemd-creds has-tpm2 fails"

shopt -s nullglob
keks=(/etc/credstore.encrypted/wpup-kek-v[0-9]*)
if [[ ${#keks[@]} -eq 0 ]]; then
    fail "no sealed KEK in /etc/credstore.encrypted"
fi
for k in "${keks[@]}"; do
    name="$(basename "$k")"
    len="$(systemd-creds decrypt --name="$name" "$k" - 2>/dev/null | tr -d '\n' | wc -c)"
    if [[ "$len" -eq 44 ]]; then pass "$name opens with the TPM (32 bytes, base64)"; else fail "$name does not open or has the wrong length"; fi
    [[ "$(stat -c '%a %U' "$k")" == "600 root" ]] && pass "$name is 0600 root" || fail "$name permissions are not 0600 root"
done
ls /dev/shm/wpup-kek-v*.txt >/dev/null 2>&1 \
    && fail "a raw KEK is still in /dev/shm (run deploy/bin/kek-shred.sh once it is in KeePassXC)" \
    || pass "no raw KEK left in RAM"

# --- Files and ownership -----------------------------------------------------
[[ -d "$app_dir" && "$(stat -c '%a %U' "$app_dir")" == "750 $service_user" ]] && pass "$app_dir is 0750 $service_user" || fail "$app_dir is not 0750 $service_user"
if [[ -e "$app_dir/.env" ]]; then
    [[ "$(stat -c '%a %U' "$app_dir/.env")" == "600 $service_user" ]] && pass ".env is 0600 $service_user" || fail ".env is not 0600 $service_user"
    grep -q '^WPUP_KEK_PROVIDER=systemd' "$app_dir/.env" && pass "WPUP_KEK_PROVIDER=systemd" || fail "WPUP_KEK_PROVIDER is not systemd"
    grep -q '^WPUP_IDENTITY_MODE=sudo' "$app_dir/.env" && pass "WPUP_IDENTITY_MODE=sudo" || fail "WPUP_IDENTITY_MODE is not sudo"
    grep -q '^APP_ENV=production' "$app_dir/.env" && pass "APP_ENV=production" || fail "APP_ENV is not production"
else
    skip ".env does not exist yet"
fi

# --- Services ----------------------------------------------------------------
for u in postgresql redis-server php8.4-fpm nginx wpup-horizon wpup-scheduler; do
    if [[ ! -f "$app_dir/artisan" && "$u" == wpup-* ]]; then skip "$u (application not installed)"; continue; fi
    systemctl is-active --quiet "$u" && pass "$u is active" || fail "$u is not active"
done
for u in wpup-horizon wpup-scheduler; do
    if systemctl cat "$u" 2>/dev/null | grep -q '^LoadCredentialEncrypted=wpup-kek-v'; then pass "$u loads the KEK (LoadCredentialEncrypted)"; else fail "$u has no LoadCredentialEncrypted for the KEK"; fi
done

# --- sudo / CLI --------------------------------------------------------------
visudo -c >/dev/null 2>&1 && pass "sudoers is valid" || fail "visudo -c fails"
[[ -x /usr/local/bin/wpup && "$(stat -c '%U' /usr/local/libexec/wpup-cli 2>/dev/null)" == root ]] && pass "wpup wrapper and root-owned helper installed" || fail "wpup wrapper/helper missing or not root-owned"

# --- PostgreSQL --------------------------------------------------------------
if ss -H -ltn 2>/dev/null | awk '{print $4}' | grep -qE ':(5432|6379)$'; then fail "PostgreSQL or Redis listens on TCP"; else pass "PostgreSQL and Redis do not listen on TCP"; fi
runuser -u "$service_user" -- psql -X -tA -h /var/run/postgresql -U wpup_app -d wpup -c 'select 1' >/dev/null 2>&1 \
    && pass "$service_user reaches PostgreSQL as wpup_app (peer)" || fail "$service_user cannot reach PostgreSQL as wpup_app"
runuser -u "$service_user" -- redis-cli -s /run/redis/redis-server.sock ping 2>/dev/null | grep -qx PONG \
    && pass "$service_user reaches Redis" || fail "$service_user cannot reach Redis"

# --- Operators must see nothing ---------------------------------------------
for op in "$@"; do
    id "$op" >/dev/null 2>&1 || { fail "operator $op does not exist"; continue; }
    leaks=''
    for p in "$app_dir" "$app_dir/.env" /etc/credstore.encrypted /var/lib/wpup /var/run/postgresql/.s.PGSQL.5432 /run/redis/redis-server.sock; do
        [[ -e "$p" ]] || continue
        if runuser -u "$op" -- test -r "$p" 2>/dev/null; then leaks="$leaks $p"; fi
    done
    if [[ -z "$leaks" ]]; then pass "operator $op cannot read the app, .env, credentials, state, DB or Redis"; else fail "operator $op can read:$leaks"; fi
    if runuser -u "$op" -- psql -X -h /var/run/postgresql -d wpup -c 'select 1' >/dev/null 2>&1; then fail "operator $op connects to the database"; else pass "operator $op cannot connect to the database"; fi
done

echo
if [[ $failures -eq 0 ]]; then echo "All server checks passed."; exit 0; fi
echo "$failures check(s) failed."
exit 1
