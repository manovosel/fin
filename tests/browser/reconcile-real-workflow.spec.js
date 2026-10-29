const { test, expect } = require('./fixtures.cjs');
const { openAuthenticatedApp } = require('./auth.cjs');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');

// Self-owned browser-side helper. Builds a deterministic, isolated
// test fixture per run using only invented values. Replaces shared
// mutable browser state with unique per-test Account/Envelope names.
function randomToken(prefix) {
  return prefix + '-' + crypto.randomBytes(4).toString('hex');
}

function crc32(buf) {
  const table = new Uint32Array(256);
  for (let i = 0; i < 256; i++) {
    let c = i;
    for (let j = 0; j < 8; j++) c = (c & 1) ? (0xEDB88320 ^ (c >>> 1)) : (c >>> 1);
    table[i] = c >>> 0;
  }
  let crc = 0xFFFFFFFF;
  for (let i = 0; i < buf.length; i++) crc = table[(crc ^ buf[i]) & 0xFF] ^ (crc >>> 8);
  return (crc ^ 0xFFFFFFFF) >>> 0;
}

function buildZip(files) {
  const localParts = [];
  const centralParts = [];
  let offset = 0;
  for (const { name, data } of files) {
    const nameBuf = Buffer.from(name, 'utf-8');
    const dataBuf = Buffer.from(data, 'utf-8');
    const crc = crc32(dataBuf);
    const local = Buffer.alloc(30);
    local.writeUInt32LE(0x04034b50, 0);
    local.writeUInt16LE(20, 4);
    local.writeUInt16LE(0, 6);
    local.writeUInt16LE(0, 8);
    local.writeUInt16LE(0, 10);
    local.writeUInt16LE(0, 12);
    local.writeUInt32LE(crc, 14);
    local.writeUInt32LE(dataBuf.length, 18);
    local.writeUInt32LE(dataBuf.length, 22);
    local.writeUInt16LE(nameBuf.length, 26);
    local.writeUInt16LE(0, 28);
    localParts.push(local, nameBuf, dataBuf);
    const central = Buffer.alloc(46);
    central.writeUInt32LE(0x02014b50, 0);
    central.writeUInt16LE(20, 4);
    central.writeUInt16LE(20, 6);
    central.writeUInt16LE(0, 8);
    central.writeUInt16LE(0, 10);
    central.writeUInt16LE(0, 12);
    central.writeUInt16LE(0, 14);
    central.writeUInt32LE(crc, 16);
    central.writeUInt32LE(dataBuf.length, 20);
    central.writeUInt32LE(dataBuf.length, 24);
    central.writeUInt16LE(nameBuf.length, 28);
    central.writeUInt16LE(0, 30);
    central.writeUInt16LE(0, 32);
    central.writeUInt16LE(0, 34);
    central.writeUInt16LE(0, 36);
    central.writeUInt32LE(0, 38);
    central.writeUInt32LE(offset, 42);
    centralParts.push(central, nameBuf);
    offset += 30 + nameBuf.length + dataBuf.length;
  }
  const localBuf = Buffer.concat(localParts);
  const centralBuf = Buffer.concat(centralParts);
  const eocd = Buffer.alloc(22);
  eocd.writeUInt32LE(0x06054b50, 0);
  eocd.writeUInt16LE(0, 4);
  eocd.writeUInt16LE(0, 6);
  eocd.writeUInt16LE(files.length, 8);
  eocd.writeUInt16LE(files.length, 10);
  eocd.writeUInt32LE(centralBuf.length, 12);
  eocd.writeUInt32LE(localBuf.length, 16);
  eocd.writeUInt16LE(0, 20);
  return Buffer.concat([localBuf, centralBuf, eocd]);
}

function buildSyntheticSignedXlsxBuffer({ accountNumber, accountName, rows }) {
  const escape = (value) => String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
  const excelSerialDate = (yyyyMmDd) => {
    const epoch = Date.UTC(
      Number(yyyyMmDd.slice(0, 4)),
      Number(yyyyMmDd.slice(5, 7)) - 1,
      Number(yyyyMmDd.slice(8, 10))
    );
    const base = Date.UTC(1899, 11, 30);
    return String(Math.round((epoch - base) / 86400000));
  };
  const danishDecimal = (value) => {
    const sign = value < 0 ? '-' : '';
    const abs = Math.abs(value).toFixed(2);
    return sign + abs.replace('.', ',');
  };
  const headers = ['Dato', 'Tekst', 'Beløb', 'Saldo', 'Afstemt', 'Kontonummer', 'Kontonavn', 'Hovedkategori', 'Kategori', 'Kommentar'];
  const headerRow = '<row r="1">' + headers.map((h, i) => {
    const col = String.fromCharCode(65 + i);
    return `<c r="${col}1" t="inlineStr"><is><t>${escape(h)}</t></is></c>`;
  }).join('') + '</row>';
  const dataRows = rows.map((row, idx) => {
    const rowNumber = idx + 2;
    const cells = [
      excelSerialDate(row.booking_date),
      row.description,
      danishDecimal(row.amount),
      danishDecimal(row.running_balance ?? 0),
      '',
      accountNumber,
      accountName,
      '',
      '',
      '',
    ];
    return '<row r="' + rowNumber + '">' + cells.map((value, i) => {
      const col = String.fromCharCode(65 + i);
      return `<c r="${col}${rowNumber}" t="inlineStr"><is><t>${escape(value)}</t></is></c>`;
    }).join('') + '</row>';
  }).join('');

  const sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    + '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    + '<sheetData>' + headerRow + dataRows + '</sheetData>'
    + '</worksheet>';

  const contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    + '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    + '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    + '<Default Extension="xml" ContentType="application/xml"/>'
    + '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
    + '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
    + '</Types>';

  const rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    + '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    + '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
    + '</Relationships>';

  const workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    + '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    + '<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets>'
    + '</workbook>';

  const workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    + '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    + '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
    + '</Relationships>';

  const sharedStrings = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    + '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="0" uniqueCount="0"></sst>';

  return buildZip([
    { name: '[Content_Types].xml', data: contentTypes },
    { name: '_rels/.rels', data: rootRels },
    { name: 'xl/workbook.xml', data: workbook },
    { name: 'xl/_rels/workbook.xml.rels', data: workbookRels },
    { name: 'xl/worksheets/sheet1.xml', data: sheetXml },
    { name: 'xl/sharedStrings.xml', data: sharedStrings },
  ]);
}

async function postJson(page, payload) {
  const response = await page.request.post('/api.php', {
    headers: { 'content-type': 'application/json' },
    data: payload,
  });
  const text = await response.text();
  let body = {};
  try { body = text ? JSON.parse(text) : {}; } catch { body = { raw: text }; }
  return { ok: response.ok(), status: response.status(), body };
}

test('real Reconcile UI workflow: automatic rows never appear, manual rows reconcile in one Save', async ({ page }) => {
  test.setTimeout(60_000);
  await openAuthenticatedApp(page);

  const suffix = randomToken('regr');
  const accountAName = `${suffix}-AccountA`;
  const accountBName = `${suffix}-AccountB`;
  const envelopeAName = `${suffix}-EnvelopeA`;
  const envelopeBName = `${suffix}-EnvelopeB`;
  const accountANumber = '9999' + crypto.randomBytes(4).toString('hex');
  const accountBNumber = '8888' + crypto.randomBytes(4).toString('hex');

  // --- Self-owned prerequisite: create the two Accounts and two Envelopes
  // via the real API. Each value is invented; nothing is hardcoded to the
  // user's data.
  const monthResult = await postJson(page, {
    action: 'saveMonth',
    year: new Date().getFullYear(),
    month: { year: new Date().getFullYear(), month: new Date().getMonth() + 1, exchange_rate: 1.0, salary: 0 },
  });
  expect(monthResult.ok, JSON.stringify(monthResult.body)).toBeTruthy();

  const accountAResult = await postJson(page, {
    action: 'saveAccount',
    year: new Date().getFullYear(),
    account: {
      name: accountAName,
      account_number: accountANumber,
      currency: 'CURRENCY',
    },
  });
  expect(accountAResult.ok, JSON.stringify(accountAResult.body)).toBeTruthy();
  const accountAId = Number(accountAResult.body.id);

  const accountBResult = await postJson(page, {
    action: 'saveAccount',
    year: new Date().getFullYear(),
    account: {
      name: accountBName,
      account_number: accountBNumber,
      currency: 'CURRENCY',
    },
  });
  expect(accountBResult.ok, JSON.stringify(accountBResult.body)).toBeTruthy();
  const accountBId = Number(accountBResult.body.id);

  const envelopeAResult = await postJson(page, {
    action: 'saveEnvelope',
    year: new Date().getFullYear(),
    envelope: { name: envelopeAName, role: 'daily', currency: 'CURRENCY' },
  });
  expect(envelopeAResult.ok, JSON.stringify(envelopeAResult.body)).toBeTruthy();
  const envelopeAId = Number(envelopeAResult.body.id);

  const envelopeBResult = await postJson(page, {
    action: 'saveEnvelope',
    year: new Date().getFullYear(),
    envelope: { name: envelopeBName, role: 'reserved', currency: 'CURRENCY' },
  });
  expect(envelopeBResult.ok, JSON.stringify(envelopeBResult.body)).toBeTruthy();
  const envelopeBId = Number(envelopeBResult.body.id);

  // Create the authoritative counterpart for Row D. The deterministic match
  // rule requires the same Account, direction, amount within 0.01 Currency
  // after conversion, and ±4 calendar days.
  const counterpartDate = '2026-08-04';
  const counterpartAmount = 50;
  const counterpartResult = await postJson(page, {
    action: 'createTransaction',
    year: new Date().getFullYear(),
    transaction: {
      envelope_id: envelopeAId,
      payment_account_id: accountAId,
      amount: counterpartAmount,
      spent_date: counterpartDate,
      note: `${suffix}-RowD-counterpart`,
    },
  });
  expect(counterpartResult.ok, JSON.stringify(counterpartResult.body)).toBeTruthy();

  // API-owned setup does not mutate the already loaded browser state. Refresh
  // it explicitly so the import Account selector contains this test's Account.
  await page.evaluate(async () => {
    applyState(await apiGet(`state&year=${new Date().getFullYear()}`));
  });

  // --- Build the synthetic statement file with all four rows.
  const syntheticRows = [
    { booking_date: '2026-08-02', amount: -10,  description: `${suffix}-RowA-manual-expense`,    running_balance: 990 },
    { booking_date: '2026-08-03', amount: -20,  description: `${suffix}-RowB-manual-transfer`,   running_balance: 970 },
    { booking_date: '2026-08-05', amount: -30,  description: `${suffix}-RowC-untouched`,         running_balance: 940 },
    { booking_date: counterpartDate, amount: -counterpartAmount, description: `${suffix}-RowD-automatic-match`, running_balance: 890 },
  ];
  const xlsxBytes = buildSyntheticSignedXlsxBuffer({
    accountNumber: 'TT9999' + crypto.randomBytes(3).toString('hex'),
    accountName: accountAName + ' Privatkonto',
    rows: syntheticRows,
  });
  const tmpDir = path.join('/tmp', `fin-regression-${suffix}`);
  fs.mkdirSync(tmpDir, { recursive: true });
  const statementPath = path.join(tmpDir, 'regression.xlsx');
  fs.writeFileSync(statementPath, xlsxBytes);
  try {
    // --- Import the synthetic statement through the real UI workflow.
    await page.getByRole('button', { name: 'Month', exact: true }).click();
    await page.locator('#accountsDetails > summary').click();
    await page.getByRole('button', { name: 'Import statement' }).click();
    await page.locator('#statementFile').setInputFiles(statementPath);
    await page.locator('#statementAccountChoice').selectOption(String(accountAId));
    await page.getByRole('button', { name: 'Preview' }).click();
    await page.getByRole('button', { name: 'Import evidence' }).click();
    await expect(page.locator('#statementImportMessage')).toContainText('Evidence imported');
    await page.locator('#statementImportDialog').evaluate((dialog) => dialog.close());

    // --- Load the actual reconciliation state via the real API.
    const reconStateResult = await postJson(page, {
      action: 'loadStatementReconciliation',
      account_id: accountAId,
    });
    expect(reconStateResult.ok, JSON.stringify(reconStateResult.body)).toBeTruthy();
    // Refresh page-side envelopes + accounts so the reconciliation table
    // dropdowns contain the freshly created envelopes.
    const appState = await page.evaluate(async () => apiGet(`state&year=${new Date().getFullYear()}`));
    await page.evaluate((data) => {
      state.accounts = data.accounts || [];
      state.envelopes = data.envelopes || [];
    }, appState);
    const beforeRows = reconStateResult.body.state.unresolved || [];
    const rowA = beforeRows.find((row) => row.description_original === `${suffix}-RowA-manual-expense`);
    const rowB = beforeRows.find((row) => row.description_original === `${suffix}-RowB-manual-transfer`);
    const rowC = beforeRows.find((row) => row.description_original === `${suffix}-RowC-untouched`);
    const rowD = beforeRows.find((row) => row.description_original === `${suffix}-RowD-automatic-match`);
    expect(rowA, 'Row A must be in the unresolved list').toBeTruthy();
    expect(rowB).toBeTruthy();
    expect(rowC).toBeTruthy();
    expect(rowD, 'Row D must have been auto-processed at import').toBeUndefined();

    // Push state into the page and open the dialog.
    await page.evaluate((payload) => {
      state.reconciliation = {
        account_id: payload.account_id,
        account_name: payload.account_name,
        pending: {},
        errors: {},
        ...payload.state,
      };
      renderReconciliationState();
      document.querySelector('#reconciliationDialog').showModal();
    }, { account_id: accountAId, account_name: accountAName, state: reconStateResult.body.state });

    // --- Defect assertions on the rendered reconciliation table.
    const tableHeaders = await page.locator('.reconciliation-exception-table thead th').allTextContents();
    expect.soft(tableHeaders.map((h) => h.trim().toLowerCase()), 'No Status column header should be present')
      .not.toContain('status');

    const statusCellTexts = await page.locator('.reconciliation-exception-table tbody td:nth-child(4)').allTextContents();
    for (const text of statusCellTexts) {
      const lowered = text.toLowerCase();
      expect.soft(lowered, 'Status cell text must not contain Envelope required').not.toContain('envelope required');
      expect.soft(lowered, 'Status cell text must not contain Automatic processing pending').not.toContain('automatic processing pending');
      expect.soft(lowered, 'Status cell text must not contain tracking start').not.toContain('tracking start');
      expect.soft(lowered, 'Status cell text must not contain tracking date').not.toContain('tracking date');
      expect.soft(lowered, 'Status cell text must not contain history baseline').not.toContain('history baseline');
      expect.soft(lowered, 'Status cell text must not contain account history baseline').not.toContain('account history baseline');
    }

    // --- User selections: choose Envelope A for Row A, Account B for Row B.
    const rowAId = Number(rowA.id);
    const rowBId = Number(rowB.id);
    const rowCId = Number(rowC.id);
    await page.locator(`tr[data-row-id="${rowAId}"] [data-exception-classification]`).selectOption(`envelope:${envelopeAId}`);
    await page.locator(`tr[data-row-id="${rowBId}"] [data-exception-classification]`).selectOption(`account:${accountBId}`);

    // --- One Save.
    const requestPromise = page.waitForRequest((request) => {
      if (request.method() !== 'POST' || !request.url().endsWith('/api.php')) return false;
      try { return request.postDataJSON().action === 'batchReconcileStatementExpenses'; } catch { return false; }
    });
    await page.locator('#saveReconciliationExceptionsBtn').click();
    await requestPromise;
    // Allow dialog state refresh.
    await expect(page.locator('#reconciliationMessage')).toBeVisible();

    // --- After Save: only Row C remains.
    await expect(page.locator('.reconciliation-exception-table tbody tr')).toHaveCount(1);
    await expect(page.locator(`tr[data-row-id="${rowCId}"]`)).toBeVisible();

    // --- Authoritative database verification.
    const afterReconResult = await postJson(page, {
      action: 'loadStatementReconciliation',
      account_id: accountAId,
    });
    expect(afterReconResult.ok, JSON.stringify(afterReconResult.body)).toBeTruthy();
    const afterRows = afterReconResult.body.state.unresolved || [];
    expect(afterRows.map((row) => Number(row.id))).toEqual([rowCId]);
    const reconciledRows = afterReconResult.body.state.reconciled || [];
    const rowALink = reconciledRows.find((row) => Number(row.statement_row_id) === rowAId);
    const rowBLink = reconciledRows.find((row) => Number(row.statement_row_id) === rowBId);
    expect.soft(rowALink, 'Row A must be authored exactly one Transaction').toBeTruthy();
    if (rowALink) {
      expect.soft(rowALink.target_kind).toBe('transaction');
      expect.soft(Number(rowALink.transaction_id)).toBeGreaterThan(0);
    }
    expect.soft(rowBLink, 'Row B must be authored exactly one Transfer').toBeTruthy();
    if (rowBLink) {
      expect.soft(rowBLink.target_kind).toBe('account_event');
      expect.soft(Number(rowBLink.event_source_account_id)).toBe(accountAId);
      expect.soft(Number(rowBLink.event_destination_account_id)).toBe(accountBId);
    }
    expect(Number(rowC.envelope ?? -1), 'Row C must remain unresolved with no envelope assigned').toBe(-1);
    expect(Number(rowC.transaction_id ?? 0), 'Row C must have no Transaction').toBe(0);

    // --- No duplicate Transaction: Row A creates one Transaction, Row D is
    // auto-linked to the pre-existing counterpart (no new Transaction).
    const stateResult = await page.evaluate(async () => apiGet(`state&year=${new Date().getFullYear()}`));
    const ourTransactions = (stateResult.transactions || []).filter(
      (tx) => Number(tx.envelope_id) === envelopeAId && Number(tx.payment_account_id) === accountAId
    );
    expect.soft(ourTransactions.length, 'Row A creates 1 new Transaction; Row D reuses the pre-existing counterpart — total must be exactly 2').toBe(2);
    // The pre-existing counterpart Transaction must still be unique (Row D was auto-linked to it, not duplicated).
    const counterpartCount = (stateResult.transactions || []).filter(
      (tx) => tx.note === `${suffix}-RowD-counterpart`
    ).length;
    expect.soft(counterpartCount, 'Row D counterpart Transaction must remain unique (no duplicate creation)').toBe(1);

    // --- Row D must have been auto-linked at import.
    const rowDLink = (reconciledRows || []).find((row) => row.description_original === `${suffix}-RowD-automatic-match`);
    expect.soft(rowDLink, 'Row D must have been auto-linked at import').toBeTruthy();
    if (rowDLink) {
      expect.soft(rowDLink.target_kind).toBe('transaction');
    }
  } finally {
    fs.rmSync(tmpDir, { recursive: true, force: true });
  }
});
