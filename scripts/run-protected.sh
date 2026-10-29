#!/usr/bin/env bash
# Protected FIN invariant harness runner.
#
# Invokes the protected test harness and produces a structured,
# machine-readable report of the result. The harness lives outside this
# writable tree and must remain immutable; this wrapper invokes it as-is
# and only formats its output.
#
# The harness location is installation-specific, so it is not hard-coded
# here. Supply it either through the FIN_PROTECTED_HARNESS_ROOT environment
# variable or through an ignored local settings file
# (scripts/run-protected.local.sh) that sets the same variable.
#
# Usage:
#   scripts/run-protected.sh                 # run the protected suite
#   scripts/run-protected.sh --report <path> # also write a structured
#                                          #   JSON report next to the text
#
# The script exits 0 if the protected suite passed, non-zero if it failed.

set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LOCAL_SETTINGS="$ROOT_DIR/scripts/run-protected.local.sh"

FIN_PROTECTED_HARNESS_ROOT="${FIN_PROTECTED_HARNESS_ROOT:-}"
if [[ -f "$LOCAL_SETTINGS" ]]; then
  # shellcheck disable=SC1090
  source "$LOCAL_SETTINGS"
fi

HARNESS_ROOT="${FIN_PROTECTED_HARNESS_ROOT:-}"
if [[ -z "$HARNESS_ROOT" ]]; then
  echo "Error: the protected harness location is not configured." >&2
  echo "Set FIN_PROTECTED_HARNESS_ROOT, or create scripts/run-protected.local.sh containing:" >&2
  echo "  FIN_PROTECTED_HARNESS_ROOT='/absolute/path/to/fin-protected-tests'" >&2
  exit 1
fi
HARNESS_RUN="$HARNESS_ROOT/run.sh"
REPORT_PATH=''

while (($#)); do
  case "$1" in
    --report)
      REPORT_PATH="${2:-}"
      shift 2
      ;;
    --help|-h)
      sed -n '2,19p' "$0"
      exit 0
      ;;
    *)
      echo "Unknown argument: $1" >&2
      exit 2
      ;;
  esac
done

if [[ ! -x "$HARNESS_RUN" ]]; then
  echo "Protected harness not found at $HARNESS_RUN" >&2
  exit 1
fi

LOG_DIR="$(mktemp -d "${TMPDIR:-/tmp}/fin-protected-runner.XXXXXX")"
cleanup() { rm -rf "$LOG_DIR"; }
trap cleanup EXIT INT TERM

OUT_LOG="$LOG_DIR/protected.out"
ERR_LOG="$LOG_DIR/protected.err"

set +e
"$HARNESS_RUN" >"$OUT_LOG" 2>"$ERR_LOG"
RC=$?
set -e

# Per-project status. The protected harness's playwright config defines the
# desktop-chromium and mobile-chromium projects. We look for the test's line
# in the list reporter output and decide per project.
DSTATUS='UNKNOWN'
MSTATUS='UNKNOWN'
if (( RC == 0 )); then
  DSTATUS='PASS'
  MSTATUS='PASS'
elif grep -E '^\s*✓' "$OUT_LOG" >/dev/null 2>&1; then
  # Mixed results: derive per-project from the per-test lines.
  if grep -E '^\s*✓.*\[desktop-chromium\]' "$OUT_LOG" >/dev/null 2>&1; then DSTATUS='PASS'; else DSTATUS='FAIL'; fi
  if grep -E '^\s*✓.*\[mobile-chromium\]' "$OUT_LOG" >/dev/null 2>&1; then MSTATUS='PASS'; else MSTATUS='FAIL'; fi
else
  DSTATUS='FAIL'
  MSTATUS='FAIL'
fi

OVERALL='PASS'
if (( RC != 0 )) || [[ "$DSTATUS" != "PASS" ]] || [[ "$MSTATUS" != "PASS" ]]; then
  OVERALL='FAIL'
fi

cat <<EOF
Protected tests:
  Command: scripts/run-protected.sh
  Result: $OVERALL
  Desktop: $DSTATUS
  Mobile: $MSTATUS
  Log: $OUT_LOG
EOF

# On failure, persist a copy of the harness log to a stable location so the
# caller can inspect it after the trap cleans up the temp dir.
if [[ "$OVERALL" != "PASS" ]]; then
  PERSIST_LOG="${TMPDIR:-/tmp}/fin-protected-runner.log"
  cp -f "$OUT_LOG" "$PERSIST_LOG" 2>/dev/null || true
  if [[ -f "$ERR_LOG" ]]; then
    cat "$ERR_LOG" >> "$PERSIST_LOG" 2>/dev/null || true
  fi
  echo "Log persisted at: $PERSIST_LOG"
fi

if [[ -n "$REPORT_PATH" ]]; then
  mkdir -p "$(dirname "$REPORT_PATH")"
  if command -v python3 >/dev/null 2>&1; then
    STATUS="$OVERALL" DSTATUS="$DSTATUS" MSTATUS="$MSTATUS" RC="$RC" OUT_LOG="$OUT_LOG" HARNESS_ROOT="$HARNESS_ROOT" python3 - "$REPORT_PATH" <<'PY'
import json, os, sys
out_path = sys.argv[1]
data = {
    "harness_root": os.environ["HARNESS_ROOT"],
    "command": os.path.join(os.environ["HARNESS_ROOT"], "run.sh"),
    "result": os.environ["STATUS"],
    "desktop": os.environ["DSTATUS"],
    "mobile": os.environ["MSTATUS"],
    "exit_code": int(os.environ["RC"]),
    "log_path": os.environ["OUT_LOG"],
}
with open(out_path, "w") as fh:
    json.dump(data, fh, indent=2, sort_keys=True)
PY
  fi
fi

if [[ "$OVERALL" == "PASS" ]]; then
  exit 0
fi
exit 1
