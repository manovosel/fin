#!/usr/bin/env python3
"""Import a source budget workbook into the FIN SQLite schema.

Usage:
  python3 scripts/import_source_budget.py source-budget.xlsx data/fin.sqlite --replace
"""

from __future__ import annotations

import argparse
import datetime as dt
import json
import os
import re
import sqlite3
import subprocess
import zipfile
import xml.etree.ElementTree as ET
from pathlib import Path

MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'
SOURCE_CURRENCY = 'DKK'
SOURCE_HELPER_CURRENCY = 'EUR'
FIN_ROOT = Path(__file__).resolve().parent.parent

def tag(name: str) -> str:
    return f'{{{MAIN}}}{name}'

def colnum(col: str) -> int:
    n = 0
    for ch in col:
        n = n * 26 + ord(ch) - 64
    return n

def colname(n: int) -> str:
    out = ''
    while n:
        n, rem = divmod(n - 1, 26)
        out = chr(65 + rem) + out
    return out

def ref(col: int, row: int) -> str:
    return f'{colname(col)}{row}'

def split_ref(cell_ref: str) -> tuple[int, int]:
    m = re.match(r'([A-Z]+)(\d+)', cell_ref)
    if not m:
        raise ValueError(cell_ref)
    return colnum(m.group(1)), int(m.group(2))

def numeric(value):
    if value is None or value == '':
        return None
    try:
        return float(value)
    except (TypeError, ValueError):
        return None

def money(value) -> float:
    n = numeric(value)
    return round(n or 0.0, 2)

def excel_date(value) -> str | None:
    n = numeric(value)
    if n is None or n < 20000:
        return None
    return (dt.date(1899, 12, 30) + dt.timedelta(days=int(n))).isoformat()

def clean_text(value) -> str:
    return str(value or '').strip()

def read_sheet(path: Path) -> dict[str, object]:
    with zipfile.ZipFile(path) as z:
        shared = []
        root = ET.fromstring(z.read('xl/sharedStrings.xml'))
        for si in root.findall(tag('si')):
            shared.append(''.join(t.text or '' for t in si.iter(tag('t'))))

        root = ET.fromstring(z.read('xl/worksheets/sheet1.xml'))
        values = {}
        formulas = set()
        for c in root.iter(tag('c')):
            cell_ref = c.attrib['r']
            typ = c.attrib.get('t')
            v = c.find(tag('v'))
            f = c.find(tag('f'))
            if f is not None:
                formulas.add(cell_ref)
            if typ == 's' and v is not None:
                values[cell_ref] = shared[int(v.text)]
            elif v is not None:
                values[cell_ref] = v.text
        return {'values': values, 'formulas': formulas}

def prepare_database(db_path: Path) -> dict[str, str]:
    """Run FIN's canonical migration and return its resolved currency context."""
    db_path = db_path.resolve()
    db_path.parent.mkdir(parents=True, exist_ok=True)
    env = os.environ.copy()
    env['FIN_DB_FILE'] = str(db_path)
    php = (
        'require $argv[1]; '
        '$database=db(); migrate($database, app_config()); '
        'echo json_encode(currency_context($database));'
    )
    result = subprocess.run(
        ['php', '-r', php, str(FIN_ROOT / 'api.php')],
        cwd=FIN_ROOT,
        env=env,
        text=True,
        capture_output=True,
        check=False,
    )
    if result.returncode != 0:
        raise RuntimeError(f'FIN database migration failed: {(result.stderr or result.stdout).strip()}')
    try:
        context = json.loads(result.stdout)
    except json.JSONDecodeError as error:
        raise RuntimeError('FIN database migration returned an invalid currency context') from error
    if context.get('currency') != SOURCE_CURRENCY or context.get('helper_currency') != SOURCE_HELPER_CURRENCY:
        raise RuntimeError(
            f'source-budget.xlsx requires FIN Currency {SOURCE_CURRENCY} and Helper currency {SOURCE_HELPER_CURRENCY}; '
            f"database/configuration resolves {context.get('currency', '?')} and {context.get('helper_currency', '?')}"
        )
    return {'currency': context['currency'], 'helper_currency': context['helper_currency']}


def envelope_role(name: str) -> str:
    normalized = name.strip().lower()
    if any(token in normalized for token in ('saving', 'savings', 'invest', 'home')):
        return 'savings'
    return 'daily' if normalized in {'food', 'transport/fuel', 'fun&travel', 'clothing/barber'} else 'reserved'


def ensure_envelope(db: sqlite3.Connection, name: str, account_name: str, preference: str, sort_order: int) -> int:
    account = db.execute(
        'SELECT id FROM accounts WHERE lower(trim(name))=lower(trim(?)) LIMIT 1',
        (account_name,),
    ).fetchone() if account_name else None
    default_account_id = int(account[0]) if account else None
    row = db.execute('SELECT id, default_account_id FROM envelopes WHERE lower(trim(name))=lower(trim(?))', (name,)).fetchone()
    if row:
        if row[1] is None and default_account_id is not None:
            db.execute('UPDATE envelopes SET default_account_id=?, updated_at=CURRENT_TIMESTAMP WHERE id=?', (default_account_id, row[0]))
        return int(row[0])
    cursor = db.execute(
        '''INSERT INTO envelopes (name, default_account_id, role, currency, sort_order)
           VALUES (?, ?, ?, ?, ?)''',
        (name, default_account_id, envelope_role(name), preference, sort_order),
    )
    return int(cursor.lastrowid)


def save_imported_entry(
    db: sqlite3.Connection,
    month_id: int,
    envelope_id: int,
    name: str,
    amount: float,
    status: str,
    due_date: str | None,
    sort_order: int,
) -> int:
    existing = db.execute(
        "SELECT id FROM entries WHERE month_id=? AND envelope_id=? AND type<>'income' ORDER BY id LIMIT 1",
        (month_id, envelope_id),
    ).fetchone()
    values = (name, amount, status, due_date, sort_order)
    if existing:
        entry_id = int(existing[0])
        db.execute(
            '''UPDATE entries SET name=?, category='Envelope', type='expense', amount=?, note='',
               status=?, due_date=?, sort_order=?, is_recurring=1, is_bs=0,
               bs_payment_account_id=NULL, bs_expense_finalized_at=NULL, updated_at=CURRENT_TIMESTAMP WHERE id=?''',
            (*values, entry_id),
        )
    else:
        cursor = db.execute(
            '''INSERT INTO entries (month_id, envelope_id, type, name, category, amount, note,
               status, due_date, sort_order, is_recurring, is_bs)
               VALUES (?, ?, 'expense', ?, 'Envelope', ?, '', ?, ?, ?, 1, 0)''',
            (month_id, envelope_id, *values),
        )
        entry_id = int(cursor.lastrowid)

    db.execute("DELETE FROM envelope_movements WHERE entry_id=? AND type='funding'", (entry_id,))
    if status == 'paid' and amount > 0:
        movement_date = due_date or dt.date.today().isoformat()
        db.execute(
            '''INSERT INTO envelope_movements (envelope_id, month_id, entry_id, type, amount, note, movement_date)
               VALUES (?, ?, ?, 'funding', ?, ?, ?)''',
            (envelope_id, month_id, entry_id, amount, f'Funded from plan row: {name}', movement_date),
        )
    return entry_id

def month_blocks(values: dict[str, object]) -> list[tuple[int, int, int, str]]:
    month_map = {
        'january': 1, 'february': 2, 'march': 3, 'april': 4, 'may': 5, 'june': 6,
        'july': 7, 'august': 8, 'september': 9, 'october': 10, 'november': 11, 'december': 12,
    }
    blocks = []
    for cell_ref, value in values.items():
        label = clean_text(value).lower()
        if label in month_map:
            c, r = split_ref(cell_ref)
            blocks.append((r, c, month_map[label], clean_text(value)))
    return sorted(blocks, key=lambda x: (x[2], x[0], x[1]))

def import_file(xlsx: Path, db_path: Path, replace: bool) -> None:
    sheet = read_sheet(xlsx)
    values: dict[str, object] = sheet['values']  # type: ignore[assignment]
    formulas: set[str] = sheet['formulas']  # type: ignore[assignment]
    blocks = month_blocks(values)
    if not blocks:
        raise RuntimeError('No month blocks found')

    prepare_database(db_path)
    db = sqlite3.connect(db_path)
    db.execute('PRAGMA foreign_keys = ON')

    try:
        db.execute('BEGIN IMMEDIATE')
        for start_row, start_col, month_num, label in blocks:
            year = 2026
            rate = numeric(values.get(ref(start_col + 4, start_row)))
            if rate is not None and rate <= 0:
                raise RuntimeError(f'Invalid EUR to DKK exchange rate in {label}')
            salary = 0.0
            summary_row = None
            for rr in range(start_row + 6, start_row + 40):
                name = clean_text(values.get(ref(start_col, rr)))
                if name == 'Salary':
                    salary = money(values.get(ref(start_col + 2, rr)))
                if name == 'Savings':
                    summary_row = rr
                    break

            db.execute('''INSERT INTO months (year, month, exchange_rate, salary, notes)
                VALUES (?, ?, ?, ?, ?)
                ON CONFLICT(year, month) DO UPDATE SET exchange_rate=excluded.exchange_rate, salary=excluded.salary, notes=excluded.notes, updated_at=CURRENT_TIMESTAMP''',
                (year, month_num, rate, salary, f'Imported from source-budget.xlsx block {label}@{ref(start_col, start_row)}'))
            month_id = int(db.execute('SELECT id FROM months WHERE year=? AND month=?', (year, month_num)).fetchone()[0])
            if replace:
                db.execute('DELETE FROM entries WHERE month_id=?', (month_id,))

            sort_order = 10
            # The source workbook stores its EUR obligations in the source Helper currency.
            for rr in range(start_row + 1, start_row + 5):
                vendor = clean_text(values.get(ref(start_col + 1, rr)))
                helper_amount = money(values.get(ref(start_col + 2, rr)))
                due = excel_date(values.get(ref(start_col, rr)))
                if not vendor and not due and helper_amount == 0:
                    continue
                if rate is None and helper_amount != 0:
                    raise RuntimeError(f'Missing EUR to DKK exchange rate for {label}')
                name = vendor or 'Bill'
                amount = round(helper_amount * rate, 2) if rate is not None else 0.0
                envelope_id = ensure_envelope(db, name, '', 'HELPER_CURRENCY', sort_order)
                save_imported_entry(db, month_id, envelope_id, name, amount, 'planned', due, sort_order)
                sort_order += 10

            stop_row = summary_row or start_row + 38
            for rr in range(start_row + 6, stop_row + 1):
                name = clean_text(values.get(ref(start_col, rr)))
                if not name or name in {'Σ', 'Salary', 'Savings'}:
                    continue
                helper_amount = money(values.get(ref(start_col + 1, rr)))
                amount = money(values.get(ref(start_col + 2, rr)))
                used_helper_amount = amount == 0 and helper_amount != 0
                if used_helper_amount:
                    if rate is None:
                        raise RuntimeError(f'Missing EUR to DKK exchange rate for {name} in {label}')
                    amount = round(helper_amount * rate, 2)
                marker = clean_text(values.get(ref(start_col + 3, rr))).lower()
                helper = values.get(ref(start_col + 4, rr))
                helper_ref = ref(start_col + 4, rr)
                unit_or_marker = clean_text(values.get(ref(start_col + 5, rr))).lower()
                status = 'paid' if marker == 'x' or unit_or_marker == 'x' else 'planned'
                account = clean_text(helper) if helper_ref not in formulas else ''
                preference = 'HELPER_CURRENCY' if used_helper_amount else 'CURRENCY'
                envelope_id = ensure_envelope(db, name, account, preference, sort_order)
                save_imported_entry(db, month_id, envelope_id, name, amount, status, None, sort_order)
                sort_order += 10
        if db.execute('PRAGMA foreign_key_check').fetchone() is not None:
            raise RuntimeError('Source budget import foreign-key verification failed')
        db.commit()
    except Exception:
        db.rollback()
        raise
    finally:
        db.close()
    print(f'Imported {len(blocks)} month blocks into {db_path}')


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument('xlsx', type=Path)
    parser.add_argument('database', type=Path)
    parser.add_argument('--replace', action='store_true', help='Replace imported entries for matching months')
    args = parser.parse_args()
    import_file(args.xlsx, args.database, args.replace)

if __name__ == '__main__':
    main()
