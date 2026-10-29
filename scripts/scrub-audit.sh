#!/usr/bin/env bash
# Publication-readiness audit.
#
# Scans the CONTENT of every tracked file (git ls-files) for values that must
# not reach a published source tree:
#
#   * generic structural leaks that can be detected without knowing the
#     installation: non-loopback IP addresses, home-directory paths that
#     reveal a Unix user name, and tracked data files;
#   * literal private values (production Envelope/Account names, the private
#     host name, the private source-workbook name, personal names) taken from
#     an ignored local pattern list.
#
# Out of scope by design:
#   - docs/architecture/FIN_BACKLOG.md (it changes only through the backlog
#     skill and quotes private values on purpose);
#   - git history, untracked files and gitignored files.
#
# Real bank/provider institution names are covered as well: they are listed in
# the same ignored local pattern file, so the literal-value loop below reports
# them in every tracked file. The names themselves never enter a tracked file.
#
# Usage:
#   scripts/scrub-audit.sh [pattern-file]
#
# The pattern file defaults to scripts/scrub-patterns.local.txt, which is
# ignored by Git and holds one private value per line. When it is absent the
# literal part of the audit is skipped and reported as skipped.
#
# Exit code 0 when clean, 1 when a finding remains.

set -uo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

PATTERN_FILE="${1:-$ROOT_DIR/scripts/scrub-patterns.local.txt}"
BACKLOG='docs/architecture/FIN_BACKLOG.md'
STATUS=0

tracked_files() {
  git ls-files -z | grep -zv -x -F "$BACKLOG"
}

report() {
  local label="$1"
  local pattern="$2"
  local filter="${3:-}"
  local hits
  hits="$(tracked_files | xargs -0 grep -nI -E -- "$pattern" 2>/dev/null || true)"
  if [[ -n "$filter" ]]; then
    hits="$(printf '%s\n' "$hits" | grep -vE -- "$filter" || true)"
  fi
  if [[ -n "$hits" ]]; then
    echo "--- FINDING [$label]: $pattern"
    echo "$hits"
    echo
    STATUS=1
  fi
}

echo "== publication-readiness audit =="
echo "tracked files: $(git ls-files | wc -l)"
echo

echo "--- generic structural checks ---"
# Non-loopback, non-wildcard IPv4 literals.
report 'ip address' '(^|[^0-9.])((25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])\.){3}(25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])([^0-9.]|$)' \
  '(^|[^0-9.])127\.[0-9.]+|(^|[^0-9.])0\.0\.0\.0'
# Home-directory paths that reveal a Unix user name. The documented generic
# placeholder used by config.local.example.php is allowed.
report 'home directory path' '/home/([A-Za-z0-9._-]+)/' '/home/your-user/'
# Tracked data files must not be part of a published source tree. This checks
# the tracked file names, not their content.
data_files="$(git ls-files | grep -E '\.(sqlite|sqlite3|db|xlsx|xls|csv)$' || true)"
if [[ -n "$data_files" ]]; then
  echo "--- FINDING [tracked data file]: *.sqlite|db|xlsx|xls|csv"
  echo "$data_files"
  echo
  STATUS=1
fi
echo

echo "--- literal private values ---"
if [[ -f "$PATTERN_FILE" ]]; then
  while IFS= read -r pattern || [[ -n "$pattern" ]]; do
    [[ -z "$pattern" || "$pattern" == \#* ]] && continue
    report 'private value' "$pattern"
  done <"$PATTERN_FILE"
else
  echo "SKIPPED: no literal pattern file at ${PATTERN_FILE#"$ROOT_DIR"/}"
  echo "         (one private value per line; this file is ignored by Git)"
fi
echo

if (( STATUS == 0 )); then
  echo "RESULT: CLEAN"
else
  echo "RESULT: FINDINGS REMAIN"
fi
exit "$STATUS"
