#!/usr/bin/env bash
set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MODE="${1:-all}"
cd "$ROOT_DIR"

require_file() {
  if [[ ! -e "$1" ]]; then
    echo "Missing test dependency: $1" >&2
    echo "Run: composer install && npm install" >&2
    exit 1
  fi
}

run_unit() {
  require_file node_modules/.bin/playwright
  echo "== JavaScript financial logic =="
  npm run test:js
}

run_integration() {
  require_file vendor/bin/phpunit
  echo "== PHP database and domain integration =="
  vendor/bin/phpunit --configuration phpunit.xml
  echo "== Source budget importer integration =="
  ./tests/shell/source-budget-import.test.sh
  echo "== Production-update script integration =="
  ./tests/shell/update-script.test.sh
  echo "== History archive integration =="
  ./tests/shell/archive-history.test.sh
  echo "== Restore-backup script integration =="
  ./tests/shell/restore-backup.test.sh
  echo "== PROD -> DEV refresh integration =="
  ./tests/shell/prod-dev-refresh.test.sh
}

run_browser() {
  require_file node_modules/.bin/playwright
  echo "== Chromium critical workflows =="
  npm run test:browser
  echo
  echo "Chromium HTML report:"
  echo "  npx playwright show-report playwright-report --host 0.0.0.0 --port 9323"
  echo "Then open: http://SERVER_IP:9323"
}

case "$MODE" in
  unit) run_unit ;;
  integration) run_integration ;;
  browser) run_browser ;;
  all)
    run_unit
    run_integration
    run_browser
    ;;
  *)
    echo "Usage: ./scripts/test.sh [all|unit|integration|browser]" >&2
    exit 2
    ;;
esac
