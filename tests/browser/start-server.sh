#!/usr/bin/env bash
set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

# Each browser test run allocates a fresh, isolated tmpdir and SQLite database.
# There is no per-test seed; every browser spec owns the data it creates and
# uses unique invented identifiers so two runs in parallel never collide.
#
# Port selection: prefer FIN_BROWSER_PORT (set by Playwright config); otherwise
# pick the next free TCP port on 127.0.0.1 so two Playwright configurations
# can run sequentially without colliding. The chosen port is written to
# $AUTH_DIR/port so any process can read it.
AUTH_DIR="$(mktemp -d "${TMPDIR:-/tmp}/fin-browser-auth.XXXXXX")"
WEB_ROOT="$AUTH_DIR/app"
TEST_DB="$AUTH_DIR/fin.sqlite"
SERVER_PID=''

if [[ -n "${FIN_BROWSER_PORT:-}" ]]; then
  PORT="$FIN_BROWSER_PORT"
else
  PORT="$("$ROOT_DIR/tests/browser/find-free-port.sh")"
fi
echo "$PORT" > "$AUTH_DIR/port"
export FIN_BROWSER_PORT="$PORT"

cleanup() {
  if [[ -n "$SERVER_PID" ]]; then
    kill "$SERVER_PID" 2>/dev/null || true
  fi
  rm -rf "$AUTH_DIR"
}
trap cleanup EXIT INT TERM

mkdir -p "$WEB_ROOT"
cp "$ROOT_DIR"/{VERSION,accounting.php,api.php,app.js,auth.php,composer.json,composer.lock,config.php,currency.php,ecb_rates.php,favicon.svg,finance.js,index.html,manifest.webmanifest,recommendation.php,report.php,service-worker.js,statement_import.php,statement_reconciliation.php,style.css} "$WEB_ROOT/"
cp -R "$ROOT_DIR/icons" "$WEB_ROOT/icons"
ln -s "$ROOT_DIR/vendor" "$WEB_ROOT/vendor"

cat > "$WEB_ROOT/config.local.php" <<EOF
<?php
return [
    'auth' => [
        'master_pin_file' => '$AUTH_DIR/master-pin.hash',
        'throttle_file' => '$AUTH_DIR/master-pin-throttle.json',
        'file_group' => '',
        'pin_failure_delay_us' => 0,
    ],
    'webauthn_rp_id' => 'localhost',
    'webauthn_origin' => 'http://localhost:$PORT',
];
EOF

export FIN_DB_FILE="$TEST_DB"
export FIN_NO_SEED=1

# Set Master PIN through the canonical CLI so the hash matches what
# `masterPinLogin` verifies.
{
  printf '12345678\n12345678\n'
} | FIN_TEST_APP_ROOT="$WEB_ROOT" FIN_DB_FILE="$TEST_DB" php "$WEB_ROOT/api.php" set-master-pin --stdin >/dev/null

# Migrate using the application's own migrate() so the schema matches the live tree.
FIN_DB_FILE="$TEST_DB" php "$WEB_ROOT/api.php" migrate >/dev/null

# Seed minimal self-owned baseline state so the existing critical-flows
# specs that render Today before adding any data can find their
# prerequisite Daily-spend and Reserved Envelopes. The seed's every
# identifier is unique per Playwright run; nothing here is a fixture
# in tests/fixtures/statements/ and nothing reads production data.
FIN_TEST_APP_ROOT="$WEB_ROOT" FIN_DB_FILE="$TEST_DB" \
  php "$ROOT_DIR/tests/fixtures/seed-browser.php" >/dev/null

php -S "127.0.0.1:$PORT" -t "$WEB_ROOT" &
SERVER_PID=$!
wait "$SERVER_PID"
