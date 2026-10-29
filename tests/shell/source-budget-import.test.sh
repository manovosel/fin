#!/usr/bin/env bash
set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TMP_DIR="$(mktemp -d /tmp/fin-source-budget-import-test-XXXXXX)"
trap 'rm -rf "$TMP_DIR"' EXIT

make_workbook() {
  local path="$1"
  local include_rate="$2"
  python3 - "$path" "$include_rate" <<'PY'
import sys, zipfile
from xml.sax.saxutils import escape
path, include_rate = sys.argv[1], sys.argv[2] == 'yes'
strings = ['august', 'EUR bill', 'Salary', 'Food', 'x', 'Bank', 'Savings']
indexes = {value: index for index, value in enumerate(strings)}
cells = [
    ('A1', 's', 'august'),
    ('A2', 'n', '46237'), ('B2', 's', 'EUR bill'), ('C2', 'n', '10'),
    ('A7', 's', 'Salary'), ('C7', 'n', '30000'),
    ('A8', 's', 'Food'), ('B8', 'n', '0'), ('C8', 'n', '100'),
    ('D8', 's', 'x'), ('E8', 's', 'Bank'), ('A9', 's', 'Savings'),
]
if include_rate:
    cells.append(('E1', 'n', '7.5'))
rows = {}
for ref, kind, value in cells:
    row = int(''.join(character for character in ref if character.isdigit()))
    rows.setdefault(row, []).append((ref, kind, value))
xml_rows = []
for row, row_cells in sorted(rows.items()):
    body = ''.join(
        f'<c r="{ref}" t="s"><v>{indexes[value]}</v></c>' if kind == 's'
        else f'<c r="{ref}"><v>{value}</v></c>'
        for ref, kind, value in row_cells
    )
    xml_rows.append(f'<row r="{row}">{body}</row>')
shared = '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="%d" uniqueCount="%d">%s</sst>' % (
    len(strings), len(strings), ''.join(f'<si><t>{escape(value)}</t></si>' for value in strings)
)
sheet = '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' + ''.join(xml_rows) + '</sheetData></worksheet>'
with zipfile.ZipFile(path, 'w') as archive:
    archive.writestr('xl/sharedStrings.xml', shared)
    archive.writestr('xl/worksheets/sheet1.xml', sheet)
PY
}

assert_imported_database() {
  local database="$1"
  python3 - "$database" <<'PY'
import sqlite3, sys
db = sqlite3.connect(sys.argv[1])
assert db.execute("SELECT COUNT(*) FROM schema_migrations WHERE version='2.16'").fetchone()[0] == 1
assert db.execute('SELECT currency FROM database_settings WHERE id=1').fetchone()[0] == 'DKK'
assert db.execute('SELECT exchange_rate FROM months WHERE year=2026 AND month=8').fetchone()[0] == 7.5
assert db.execute("SELECT COUNT(*) FROM entries WHERE envelope_id IS NULL AND type<>'income'").fetchone()[0] == 0
assert db.execute("SELECT COUNT(*) FROM entries WHERE category<>'Envelope' OR type<>'expense'").fetchone()[0] == 0
assert db.execute("SELECT currency FROM envelopes WHERE name='EUR bill'").fetchone()[0] == 'HELPER_CURRENCY'
assert db.execute("SELECT currency FROM envelopes WHERE name='Food'").fetchone()[0] == 'CURRENCY'
assert db.execute("SELECT amount FROM entries WHERE name='EUR bill'").fetchone()[0] == 75.0
assert db.execute("SELECT amount FROM entries WHERE name='Food'").fetchone()[0] == 100.0
assert db.execute("SELECT amount FROM envelope_movements WHERE type='funding'").fetchone()[0] == 100.0
assert db.execute('PRAGMA foreign_key_check').fetchone() is None
PY
}

cd "$ROOT_DIR"
make_workbook "$TMP_DIR/source-budget.xlsx" yes

# A missing database is initialized through FIN's canonical migration before import.
python3 scripts/import_source_budget.py "$TMP_DIR/source-budget.xlsx" "$TMP_DIR/fresh.sqlite" --replace >/dev/null
FIN_DB_FILE="$TMP_DIR/fresh.sqlite" php api.php migrate >/dev/null
assert_imported_database "$TMP_DIR/fresh.sqlite"

# Importing into an already-current DB v2.16 uses canonical fields and links every allocation to an Envelope.
FIN_DB_FILE="$TMP_DIR/current.sqlite" php api.php migrate >/dev/null
python3 scripts/import_source_budget.py "$TMP_DIR/source-budget.xlsx" "$TMP_DIR/current.sqlite" --replace >/dev/null
assert_imported_database "$TMP_DIR/current.sqlite"

# The DKK/EUR-specific source fails before financial writes against an incompatible immutable Currency.
FIN_DB_FILE="$TMP_DIR/usd.sqlite" php -r 'require "api.php"; $db=db(); migrate($db,[...app_config(),"currency"=>"USD","helper_currency"=>"SEK"]);'
if python3 scripts/import_source_budget.py "$TMP_DIR/source-budget.xlsx" "$TMP_DIR/usd.sqlite" --replace >"$TMP_DIR/usd.out" 2>&1; then
  echo 'Source budget importer accepted incompatible Currency' >&2
  exit 1
fi
grep -q 'requires FIN Currency DKK and Helper currency EUR\|Configured Currency DKK does not match database Currency USD' "$TMP_DIR/usd.out"
python3 - "$TMP_DIR/usd.sqlite" <<'PY'
import sqlite3, sys
db = sqlite3.connect(sys.argv[1])
assert db.execute("SELECT currency FROM database_settings WHERE id=1").fetchone()[0] == 'USD'
assert db.execute('SELECT COUNT(*) FROM months').fetchone()[0] == 0
assert db.execute('SELECT COUNT(*) FROM entries').fetchone()[0] == 0
PY

# Missing source rate stays missing and a required EUR-to-DKK conversion rolls back all import data.
make_workbook "$TMP_DIR/source-budget-no-rate.xlsx" no
if python3 scripts/import_source_budget.py "$TMP_DIR/source-budget-no-rate.xlsx" "$TMP_DIR/no-rate.sqlite" --replace >"$TMP_DIR/no-rate.out" 2>&1; then
  echo 'Source budget importer invented a missing exchange rate' >&2
  exit 1
fi
grep -q 'Missing EUR to DKK exchange rate' "$TMP_DIR/no-rate.out"
python3 - "$TMP_DIR/no-rate.sqlite" <<'PY'
import sqlite3, sys
db = sqlite3.connect(sys.argv[1])
assert db.execute('SELECT COUNT(*) FROM months').fetchone()[0] == 0
assert db.execute('SELECT COUNT(*) FROM envelopes').fetchone()[0] == 0
assert db.execute('SELECT COUNT(*) FROM entries').fetchone()[0] == 0
PY

echo 'Source budget importer integration passed'
