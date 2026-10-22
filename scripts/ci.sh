#!/usr/bin/env bash
# Local CI gate (UZEROWPUP-2). Runs every step even if one fails, then prints a
# summary suitable for pasting into a PR. Exits non-zero if any step failed.
set -u

cd "$(dirname "${BASH_SOURCE[0]}")/.."

LOG_DIR="$(mktemp -d)"
trap 'rm -rf "$LOG_DIR"' EXIT

NAMES=()
RESULTS=()
FAILED=0

record() { NAMES+=("$1"); RESULTS+=("$2"); }

echo "== make ci: preparant l'entorn =="
if ! make up; then
    echo "No s'ha pogut aixecar l'entorn (make up)." >&2
    exit 1
fi

if [ ! -d verifier/node_modules ]; then
    echo "== verifier: instal·lant dependències (npm ci) =="
    if ! docker compose exec -T verifier npm ci; then
        echo "npm ci ha fallat." >&2
        exit 1
    fi
fi

# run_step <name> <make target>: output goes to the terminal and to a log file.
run_step() {
    local name="$1" target="$2" log="$LOG_DIR/$1.log"
    echo
    echo "== make $target =="
    make "$target" 2>&1 | tee "$log"
    local status=${PIPESTATUS[0]}
    if [ "$status" -eq 0 ]; then
        local detail=""
        if [ "$name" = "test" ]; then
            local passed
            passed=$(grep -oE '[0-9]+ passed' "$log" | tail -n1)
            [ -n "$passed" ] && detail=" ($passed)"
        fi
        if [ "$name" = "agent-matrix" ] && grep -q 'matrix skipped' "$log"; then
            record "$name" "SKIP (sense agent)"
        else
            record "$name" "OK$detail"
        fi
    else
        # Keep the full output of a failed step: intermittent failures must be
        # diagnosable afterwards (UZEROWPUP-136).
        local kept="storage/logs/ci/$(date +%Y%m%d-%H%M%S)-$name.log"
        mkdir -p storage/logs/ci && cp "$log" "$kept"
        record "$name" "FAIL (sortida a $kept)"
        FAILED=1
    fi
}

run_step lint lint
run_step stan stan
run_step test test
run_step verifier verifier
run_step agent-matrix agent-matrix

echo
echo "== make ci: resum =="
for i in "${!NAMES[@]}"; do
    printf '%-13s %s\n' "${NAMES[$i]}" "${RESULTS[$i]}"
done
if [ "$FAILED" -eq 0 ]; then
    printf '%-13s %s\n' "resultat" "OK"
else
    printf '%-13s %s\n' "resultat" "FAIL"
fi

exit "$FAILED"
