const { test, expect } = require('./fixtures.cjs');
const { openAuthenticatedApp, waitForApiAction } = require('./auth.cjs');
const path = require('node:path');
const cp = require('node:child_process');
const { buildSyntheticDebitCreditPreheaderXlsx, writeTempXlsx } = require('./SyntheticXlsx.cjs');

function runDatabasePhp(finWorker, source) {
  const result = cp.spawnSync('php', ['-r', source], {
    cwd: finWorker.codeDir,
    env: { ...process.env, FIN_DB_FILE: finWorker.dbPath },
    encoding: 'utf8',
  });
  expect(result.status).toBe(0);
  return (result.stdout || '').trim();
}

async function createAccount(page, name, accountNumber) {
  const response = await page.request.post('/api.php', {
    headers: { 'content-type': 'application/json' },
    data: {
      action: 'saveAccount',
      account: {
        name,
        account_number: accountNumber,
        currency: 'CURRENCY',
      },
    },
  });
  expect(response.ok()).toBeTruthy();
  return Number((await response.json()).id);
}

test('statement exceptions use one dropdown for partial Envelope, Transfer, and IGNORE saves', async ({ page }) => {
  test.setTimeout(60_000);
  await openAuthenticatedApp(page);
  const suffix = `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`;
  const statementAccountId = await createAccount(page, `Statement Account ${suffix}`, `STATEMENT-${suffix}`);
  const transferAccountId = await createAccount(page, `Transfer Account ${suffix}`, `TRANSFER-${suffix}`);
  await createAccount(page, `zulu Transfer Account ${suffix}`, `ZULU-TRANSFER-${suffix}`);
  await createAccount(page, `Alpha Transfer Account ${suffix}`, `ALPHA-TRANSFER-${suffix}`);
  const envelopeName = `Statement Envelope ${suffix}`;

  const envelopeResponse = await page.request.post('/api.php', {
    headers: { 'content-type': 'application/json' },
    data: {
      action: 'saveEnvelope',
      year: new Date().getFullYear(),
      envelope: { name: envelopeName, role: 'daily', currency: 'CURRENCY', visibility: 'normal' },
    },
  });
  expect(envelopeResponse.ok()).toBeTruthy();
  const envelopeId = Number((await envelopeResponse.json()).id);
  for (const name of [`zulu Statement Envelope ${suffix}`, `Alpha Statement Envelope ${suffix}`]) {
    const response = await page.request.post('/api.php', {
      headers: { 'content-type': 'application/json' },
      data: {
        action: 'saveEnvelope',
        year: new Date().getFullYear(),
        envelope: { name, role: 'reserved', currency: 'CURRENCY', visibility: 'normal' },
      },
    });
    expect(response.ok()).toBeTruthy();
  }

  const appState = await page.evaluate(async () => apiGet(`state&year=${new Date().getFullYear()}`));
  await page.evaluate((data) => {
    state.accounts = data.accounts;
    state.envelopes = data.envelopes;
  }, appState);

  const frequentEnvelopeName = `zulu Statement Envelope ${suffix}`;
  await page.evaluate(({ frequentEnvelopeName, suffix }) => {
    state.envelopes.find((envelope) => envelope.name === frequentEnvelopeName).usage_count = 99;
    state.envelopes.find((envelope) => envelope.name === `Alpha Statement Envelope ${suffix}`).usage_count = 1;
  }, { frequentEnvelopeName, suffix });
  await page.getByRole('button', { name: 'Add expense' }).click();
  await expect(page.locator('#expenseEnvelope optgroup[label="Visible envelopes"] option').first()).toHaveText(frequentEnvelopeName);
  await expect(page.locator('#expenseEnvelope')).toHaveValue(String(await page.evaluate((name) => state.envelopes.find((envelope) => envelope.name === name).id, frequentEnvelopeName)));
  await page.locator('#closeExpenseDialogBtn').click();

  await page.getByRole('button', { name: 'Month', exact: true }).click();
  await page.locator('#accountsDetails > summary').click();
  await page.getByRole('button', { name: 'Import statement' }).click();
  await page.locator('#statementFile').setInputFiles((function () {
    const accountNumber = 'TEST-' + suffix;
    const rows = [];
    for (let i = 1; i <= 8; i++) {
      rows.push({
        booking_date: '2026-05-' + (i < 10 ? '0' + i : i),
        amount: i % 2 === 0 ? -40 - i : 30 + i,
        description: 'Synthetic Test Bank partial-save row ' + suffix + ' #' + i,
        counterparty: 'Synthetic counterparty ' + i,
      });
    }
    return writeTempXlsx('fin-statement-batch-' + suffix, buildSyntheticDebitCreditPreheaderXlsx({ accountNumber, currency: 'EUR', rows })).filepath;
  })());
  await expect(page.locator('#statementAccountChoiceSection')).toBeVisible();
  await page.locator('#statementAccountChoice').selectOption(String(statementAccountId));
  await page.getByRole('button', { name: 'Preview' }).click();
  const evidenceImported = waitForApiAction(page, 'importStatementEvidence');
  await page.getByRole('button', { name: 'Import evidence' }).click();
  await evidenceImported;
  await expect(page.locator('#statementImportMessage')).toContainText('Evidence imported');
  await page.locator('#statementImportDialog').evaluate((dialog) => dialog.close());

  const before = await page.evaluate(async (accountId) => {
    const data = await apiPost({ action: 'loadStatementReconciliation', account_id: accountId });
    state.reconciliation = { account_id: data.account.id, account_name: data.account.name, pending: {}, ...data.state };
    renderReconciliationState();
    document.querySelector('#reconciliationDialog').showModal();
    return data.state;
  }, statementAccountId);

  const classificationSelectors = page.locator('[data-exception-classification]');
  const selectorCount = await classificationSelectors.count();
  expect(selectorCount).toBeGreaterThanOrEqual(4);
  expect(Number(before.unresolved_count)).toBe(selectorCount);
  await expect(page.locator('#reconciliationDialogSubtitle')).toHaveText(`Statement Account ${suffix} — ${selectorCount} unresolved.`);
  const selectedRowIds = [
    Number(await classificationSelectors.nth(0).getAttribute('data-exception-classification')),
    Number(await classificationSelectors.nth(1).getAttribute('data-exception-classification')),
  ];
  const ignoredRowId = Number(await classificationSelectors.nth(2).getAttribute('data-exception-classification'));
  const untouchedRowId = Number(await classificationSelectors.nth(3).getAttribute('data-exception-classification'));
  const beforeById = new Map(before.unresolved.map((row) => [Number(row.id), row]));
  const incomingRowId = selectedRowIds.find((rowId) => Number(beforeById.get(rowId).amount) > 0);
  const outgoingRowId = selectedRowIds.find((rowId) => Number(beforeById.get(rowId).amount) < 0);
  expect(incomingRowId).toBeDefined();
  expect(outgoingRowId).toBeDefined();
  const firstRow = page.locator(`tr[data-row-id="${selectedRowIds[0]}"]`);
  const firstClassification = firstRow.locator('[data-exception-classification]');
  const secondRow = page.locator(`tr[data-row-id="${selectedRowIds[1]}"]`);
  const secondClassification = secondRow.locator('[data-exception-classification]');
  const ignoredRow = page.locator(`tr[data-row-id="${ignoredRowId}"]`);
  const ignoredClassification = ignoredRow.locator('[data-exception-classification]');
  const incomingClassification = page.locator(`tr[data-row-id="${incomingRowId}"] [data-exception-classification]`);
  const outgoingClassification = page.locator(`tr[data-row-id="${outgoingRowId}"] [data-exception-classification]`);
  const save = page.locator('#saveReconciliationExceptionsBtn');

  const exceptionTable = page.locator('.reconciliation-exception-table');
  await expect(exceptionTable.locator('thead th')).toHaveText(['Date', 'Description', 'Amount', 'Reconcile']);
  await expect(exceptionTable.locator('tbody select')).toHaveCount(selectorCount);
  await expect(exceptionTable.locator('[data-exception-envelope], [data-exception-transfer]')).toHaveCount(0);
  await expect(exceptionTable.locator('option[hidden], option[data-classification]')).toHaveCount(0);
  const realOptionValues = await firstClassification.locator('optgroup option').evaluateAll((options) => options.map((option) => option.value));
  expect(realOptionValues.length).toBeGreaterThan(0);
  expect(realOptionValues.every((value) => /^(?:envelope|account):[1-9]\d*$/.test(value))).toBeTruthy();
  const finalOptions = await classificationSelectors.evaluateAll((selects) => selects.map((select) => {
    const option = select.options[select.options.length - 1];
    return { value: option.value, text: option.textContent };
  }));
  expect(finalOptions).toEqual(Array(selectorCount).fill({ value: 'ignore', text: 'IGNORE' }));
  await expect(firstRow.locator('select')).toHaveCount(1);
  const tableText = ((await exceptionTable.textContent()) || '').toLowerCase();
  expect(tableText).not.toContain('envelope required');
  expect(tableText).not.toContain('automatic processing pending');
  expect(tableText).not.toContain('history baseline');
  await expect(firstClassification.locator('option').first()).toHaveText('—');
  await expect(firstClassification.locator('optgroup')).toHaveCount(2);
  await expect(firstClassification.locator('optgroup').nth(0)).toHaveAttribute('label', 'Envelopes');
  await expect(firstClassification.locator('optgroup').nth(1)).toHaveAttribute('label', 'Transfer');
  const ordering = await page.evaluate(({ incomingRowId, outgoingRowId, statementAccountId }) => {
    const compareNames = (left, right) => left.name.localeCompare(right.name, 'da', { sensitivity: 'base' });
    const inspect = (rowId) => {
      const select = document.querySelector(`tr[data-row-id="${rowId}"] [data-exception-classification]`);
      const groups = [...select.querySelectorAll('optgroup')];
      return {
        groupLabels: groups.map((group) => group.label),
        envelopeValues: [...groups[0].querySelectorAll('option')].map((option) => option.value),
        transferValues: [...groups[1].querySelectorAll('option')].map((option) => option.value),
        transferLabels: [...groups[1].querySelectorAll('option')].map((option) => option.textContent),
      };
    };
    return {
      incoming: inspect(incomingRowId),
      outgoing: inspect(outgoingRowId),
      expectedEnvelopeValues: [...state.envelopes].sort(compareNames).map((envelope) => `envelope:${envelope.id}`),
      expectedTransferValues: state.accounts
        .filter((account) => account.active && Number(account.id) !== statementAccountId)
        .sort(compareNames)
        .map((account) => `account:${account.id}`),
    };
  }, { incomingRowId, outgoingRowId, statementAccountId });
  expect(ordering.incoming.groupLabels).toEqual(['Envelopes', 'Transfer']);
  expect(ordering.outgoing.groupLabels).toEqual(['Envelopes', 'Transfer']);
  expect(ordering.incoming.envelopeValues).toEqual(ordering.expectedEnvelopeValues);
  expect(ordering.outgoing.envelopeValues).toEqual(ordering.expectedEnvelopeValues);
  expect(ordering.incoming.transferValues).toEqual(ordering.expectedTransferValues);
  expect(ordering.outgoing.transferValues).toEqual(ordering.expectedTransferValues);
  expect(ordering.incoming.transferLabels.every((label) => label.startsWith('from '))).toBeTruthy();
  expect(ordering.outgoing.transferLabels.every((label) => label.startsWith('to '))).toBeTruthy();
  await expect(save).toBeDisabled();
  await expect(firstRow).not.toHaveClass(/pending/);
  await expect(incomingClassification.locator(`option[value="account:${statementAccountId}"]`)).toHaveCount(0);
  await expect(outgoingClassification.locator(`option[value="account:${statementAccountId}"]`)).toHaveCount(0);
  const incomingTransferOption = incomingClassification.locator(`option[value="account:${transferAccountId}"]`);
  const outgoingTransferOption = outgoingClassification.locator(`option[value="account:${transferAccountId}"]`);
  await expect(incomingTransferOption).toHaveCount(1);
  await expect(incomingTransferOption).toHaveAttribute('value', `account:${transferAccountId}`);
  await expect(incomingTransferOption).toHaveText(`from Transfer Account ${suffix}`);
  await expect(outgoingTransferOption).toHaveCount(1);
  await expect(outgoingTransferOption).toHaveAttribute('value', `account:${transferAccountId}`);
  await expect(outgoingTransferOption).toHaveText(`to Transfer Account ${suffix}`);
  await expect(incomingClassification.locator(`option[value="envelope:${envelopeId}"]`)).toHaveText(envelopeName);
  await expect(outgoingClassification.locator(`option[value="envelope:${envelopeId}"]`)).toHaveText(envelopeName);

  await firstClassification.selectOption(`account:${transferAccountId}`);
  await expect(firstRow).toHaveClass(/pending/);
  await expect(save).toBeEnabled();
  await firstClassification.selectOption(`envelope:${envelopeId}`);
  await expect(firstClassification).toHaveValue(`envelope:${envelopeId}`);
  await expect(firstRow).toHaveClass(/pending/);
  await firstClassification.selectOption('');
  await expect(firstRow).not.toHaveClass(/pending/);
  await expect(save).toBeDisabled();

  await firstClassification.selectOption(`envelope:${envelopeId}`);
  await secondClassification.selectOption(`account:${transferAccountId}`);
  await ignoredClassification.selectOption('ignore');
  await expect(firstRow).toHaveClass(/pending/);
  await expect(secondRow).toHaveClass(/pending/);
  await expect(ignoredRow).toHaveClass(/pending/);
  await expect(save).toBeEnabled();

  const body = page.locator('.reconciliation-dialog-body');
  const actions = page.locator('.reconciliation-dialog-actions');
  await expect(actions).toBeVisible();
  expect(await actions.evaluate((element) => getComputedStyle(element).position)).toBe('sticky');
  const actionBoxBefore = await actions.boundingBox();
  const scrollMetrics = await body.evaluate((element) => {
    element.scrollTop = element.scrollHeight;
    return { scrollTop: element.scrollTop, scrollHeight: element.scrollHeight, clientHeight: element.clientHeight };
  });
  expect(scrollMetrics.scrollHeight).toBeGreaterThan(scrollMetrics.clientHeight);
  expect(scrollMetrics.scrollTop).toBeGreaterThan(0);
  const actionBoxAfter = await actions.boundingBox();
  expect(Math.abs(actionBoxAfter.y - actionBoxBefore.y)).toBeLessThan(2);

  const requestPromise = page.waitForRequest((request) => {
    if (request.method() !== 'POST' || !request.url().endsWith('/api.php')) return false;
    try { return request.postDataJSON().action === 'batchReconcileStatementExpenses'; } catch { return false; }
  });
  const saveCompleted = waitForApiAction(page, 'batchReconcileStatementExpenses');
  const refillCompleted = waitForApiAction(page, 'loadStatementReconciliation');
  await save.click();
  const saveRequest = await requestPromise;
  await saveCompleted;
  await refillCompleted;
  const payload = saveRequest.postDataJSON();
  expect(payload.selections).toHaveLength(3);
  expect(payload.selections.map((row) => Number(row.statement_row_id)).sort((a, b) => a - b)).toEqual([...selectedRowIds, ignoredRowId].sort((a, b) => a - b));
  expect(payload.selections.find((row) => Number(row.statement_row_id) === selectedRowIds[0])).toEqual({ statement_row_id: selectedRowIds[0], classification: `envelope:${envelopeId}` });
  expect(payload.selections.find((row) => Number(row.statement_row_id) === selectedRowIds[1])).toEqual({ statement_row_id: selectedRowIds[1], classification: `account:${transferAccountId}` });
  expect(payload.selections.find((row) => Number(row.statement_row_id) === ignoredRowId)).toEqual({ statement_row_id: ignoredRowId, classification: 'ignore' });
  expect(payload.selections.some((row) => Number(row.statement_row_id) === untouchedRowId)).toBeFalsy();

  await expect(page.locator('#reconciliationMessage')).toContainText('Selected statement rows saved.');
  await expect(page.locator(`tr[data-row-id="${selectedRowIds[0]}"]`)).toHaveCount(0);
  await expect(page.locator(`tr[data-row-id="${selectedRowIds[1]}"]`)).toHaveCount(0);
  await expect(page.locator(`tr[data-row-id="${ignoredRowId}"]`)).toHaveCount(0);
  await expect(page.locator(`tr[data-row-id="${untouchedRowId}"]`)).toBeVisible();
  await expect(classificationSelectors).toHaveCount(selectorCount - 3);
  await expect(page.locator('#reconciliationDialogSubtitle')).toHaveText(`Statement Account ${suffix} — ${selectorCount - 3} unresolved.`);

  const after = await page.evaluate(async (accountId) => {
    const data = await apiPost({ action: 'loadStatementReconciliation', account_id: accountId });
    return data.state;
  }, statementAccountId);
  const envelopeLink = after.reconciled.find((row) => Number(row.statement_row_id) === selectedRowIds[0]);
  const transferLink = after.reconciled.find((row) => Number(row.statement_row_id) === selectedRowIds[1]);
  const ignoredLink = after.reconciled.find((row) => Number(row.statement_row_id) === ignoredRowId);
  expect(envelopeLink.envelope).toBe(envelopeName);
  expect(envelopeLink.transaction_kind).toBe(Number(beforeById.get(selectedRowIds[0]).amount) < 0 ? 'expense' : 'refund');
  // Browser-level target_kind schema: 'transaction' for negative (expense),
  // 'refund' for positive (refund). This is the dedicated counterpart of the
  // removed critical-flows reconciliation tests; it pins the target_kind string
  // for the envelope-link branch.
  expect(envelopeLink.target_kind).toBe(Number(beforeById.get(selectedRowIds[0]).amount) < 0 ? 'transaction' : 'refund');
  expect(transferLink.target_kind).toBe('account_event');
  expect(ignoredLink.target_kind).toBe('no_fin_action');
  const transferEvidence = beforeById.get(selectedRowIds[1]);
  if (Number(transferEvidence.amount) < 0) {
    expect(Number(transferLink.event_source_account_id)).toBe(statementAccountId);
    expect(Number(transferLink.event_destination_account_id)).toBe(transferAccountId);
  } else {
    expect(Number(transferLink.event_source_account_id)).toBe(transferAccountId);
    expect(Number(transferLink.event_destination_account_id)).toBe(statementAccountId);
  }
  const untouchedAfter = after.unresolved.find((row) => Number(row.id) === untouchedRowId);
  expect(untouchedAfter).toMatchObject({
    booking_date: beforeById.get(untouchedRowId).booking_date,
    amount: beforeById.get(untouchedRowId).amount,
    currency: beforeById.get(untouchedRowId).currency,
    description_original: beforeById.get(untouchedRowId).description_original,
  });

  await page.locator('#closeReconciliationDialogBtn').click();
  await expect(page.locator('#reconciliationDialog')).not.toBeVisible();
});

test('Reconcile Save stays disabled and loading through save and refill, then re-enables', async ({ page }) => {
  await openAuthenticatedApp(page);
  const statementAccountId = 900001;
  const envelopeId = 900002;
  const selectedRow = { id: 900003, booking_date: '2026-09-15', amount: -10, currency: 'DKK', description_original: 'Selected evidence' };
  const refillRow = { id: 900004, booking_date: '2026-09-14', amount: -20, currency: 'DKK', description_original: 'Refilled evidence' };
  let releaseSave;
  let releaseRefill;
  let markSaveStarted;
  let markRefillStarted;
  const saveGate = new Promise((resolve) => { releaseSave = resolve; });
  const refillGate = new Promise((resolve) => { releaseRefill = resolve; });
  const saveStarted = new Promise((resolve) => { markSaveStarted = resolve; });
  const refillStarted = new Promise((resolve) => { markRefillStarted = resolve; });

  await page.evaluate(({ statementAccountId, envelopeId, selectedRow }) => {
    state.accounts = [{ id: statementAccountId, name: 'Statement Account', account_number: 'SOURCE', currency: 'CURRENCY', active: 1 }];
    state.envelopes = [{ id: envelopeId, name: 'Purpose', currency: 'CURRENCY', role: 'daily' }];
    state.reconciliation = {
      account_id: statementAccountId,
      account_name: 'Statement Account',
      unresolved: [selectedRow],
      reconciled: [],
      pending: {},
      errors: {},
    };
    renderReconciliationState();
    document.querySelector('#reconciliationDialog').showModal();
  }, { statementAccountId, envelopeId, selectedRow });

  await page.route('**/api.php', async (route) => {
    const request = route.request();
    if (request.method() !== 'POST') return route.continue();
    let payload = {};
    try { payload = request.postDataJSON(); } catch { return route.continue(); }
    if (payload.action === 'batchReconcileStatementExpenses') {
      markSaveStarted();
      await saveGate;
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          ok: true,
          results: [{ statement_row_id: selectedRow.id, status: 'saved', target_kind: 'transaction', transaction_id: 900005 }],
        }),
      });
    }
    if (payload.action === 'loadStatementReconciliation') {
      markRefillStarted();
      await refillGate;
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          account: { id: statementAccountId, name: 'Statement Account' },
          state: { unresolved: [refillRow], reconciled: [] },
          preselections: [{ statement_row_id: refillRow.id, classification: `envelope:${envelopeId}` }],
        }),
      });
    }
    return route.continue();
  });

  const save = page.locator('#saveReconciliationExceptionsBtn');
  const spinner = save.locator('.button-spinner');
  await page.locator(`[data-exception-classification="${selectedRow.id}"]`).selectOption(`envelope:${envelopeId}`);
  await expect(save).toBeEnabled();
  await expect(spinner).toBeHidden();

  await save.click();
  await saveStarted;
  await expect(save).toBeDisabled();
  await expect(save).toHaveAttribute('aria-busy', 'true');
  await expect(spinner).toBeVisible();

  releaseSave();
  await refillStarted;
  await expect(save).toBeDisabled();
  await expect(save).toHaveAttribute('aria-busy', 'true');
  await expect(spinner).toBeVisible();

  releaseRefill();
  await expect(page.locator(`tr[data-row-id="${refillRow.id}"]`)).toBeVisible();
  await expect(save).toBeEnabled();
  await expect(save).toHaveAttribute('aria-busy', 'false');
  await expect(spinner).toBeHidden();
});

test('Reconcile Save clears loading and re-enables after a refill failure', async ({ page }) => {
  await openAuthenticatedApp(page);
  const statementAccountId = 901001;
  const envelopeId = 901002;
  const row = { id: 901003, booking_date: '2026-09-15', amount: -10, currency: 'DKK', description_original: 'Failed refill evidence' };
  let releaseRefill;
  let markRefillStarted;
  const refillGate = new Promise((resolve) => { releaseRefill = resolve; });
  const refillStarted = new Promise((resolve) => { markRefillStarted = resolve; });

  await page.evaluate(({ statementAccountId, envelopeId, row }) => {
    state.accounts = [{ id: statementAccountId, name: 'Statement Account', account_number: 'SOURCE', currency: 'CURRENCY', active: 1 }];
    state.envelopes = [{ id: envelopeId, name: 'Purpose', currency: 'CURRENCY', role: 'daily' }];
    state.reconciliation = {
      account_id: statementAccountId,
      account_name: 'Statement Account',
      unresolved: [row],
      reconciled: [],
      pending: {},
      errors: {},
    };
    renderReconciliationState();
    document.querySelector('#reconciliationDialog').showModal();
  }, { statementAccountId, envelopeId, row });

  await page.route('**/api.php', async (route) => {
    const request = route.request();
    if (request.method() !== 'POST') return route.continue();
    let payload = {};
    try { payload = request.postDataJSON(); } catch { return route.continue(); }
    if (payload.action === 'batchReconcileStatementExpenses') {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ ok: false, results: [{ statement_row_id: row.id, status: 'error', error: 'Synthetic row failure' }] }),
      });
    }
    if (payload.action === 'loadStatementReconciliation') {
      markRefillStarted();
      await refillGate;
      return route.fulfill({
        status: 500,
        contentType: 'application/json',
        body: JSON.stringify({ error: 'Synthetic refill failure' }),
      });
    }
    return route.continue();
  });

  const save = page.locator('#saveReconciliationExceptionsBtn');
  const spinner = save.locator('.button-spinner');
  await page.locator(`[data-exception-classification="${row.id}"]`).selectOption(`envelope:${envelopeId}`);
  await save.click();
  await refillStarted;
  await expect(save).toBeDisabled();
  await expect(save).toHaveAttribute('aria-busy', 'true');
  await expect(spinner).toBeVisible();

  releaseRefill();
  await expect(page.locator('#reconciliationMessage')).toContainText('Synthetic refill failure');
  await expect(save).toBeEnabled();
  await expect(save).toHaveAttribute('aria-busy', 'false');
  await expect(spinner).toBeHidden();
});

test('partial Save shows the exact backend error on each failed pending row', async ({ page }) => {
  await openAuthenticatedApp(page);
  const successfulRowId = 910001;
  const failedRowId = 910002;
  const untouchedRowId = 910003;
  const envelopeId = 920001;
  const statementAccountId = 930001;
  const backendError = 'Test reconciliation failure';
  const configuredCurrency = await page.evaluate(() => state.currency);
  const rows = [
    { id: successfulRowId, booking_date: '2026-09-02', amount: -10, currency: configuredCurrency, description_original: 'Successful evidence', exception_reason: 'Envelope required' },
    { id: failedRowId, booking_date: '2026-08-02', amount: -20, currency: configuredCurrency, description_original: 'Failed evidence', exception_reason: 'Envelope required' },
    { id: untouchedRowId, booking_date: '2026-08-03', amount: -30, currency: configuredCurrency, description_original: 'Untouched evidence', exception_reason: 'Envelope required' },
  ];

  await page.evaluate(({ rows, statementAccountId, envelopeId }) => {
    state.accounts = [{ id: statementAccountId, name: 'Statement Account', account_number: 'TEST-STATEMENT', currency: 'CURRENCY', active: 1 }];
    state.envelopes = [{ id: envelopeId, name: 'Purpose', currency: 'CURRENCY', role: 'daily' }];
    state.reconciliation = {
      account_id: statementAccountId,
      account_name: 'Statement Account',
      unresolved: rows,
      reconciled: [],
      pending: {},
      errors: {},
    };
    renderReconciliationState();
    document.querySelector('#reconciliationDialog').showModal();
  }, { rows, statementAccountId, envelopeId });

  let submitted = null;
  await page.route('**/api.php', async (route) => {
    const request = route.request();
    if (request.method() !== 'POST') return route.continue();
    let payload = {};
    try { payload = request.postDataJSON(); } catch { return route.continue(); }
    if (payload.action === 'loadStatementReconciliation') {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          account: { id: statementAccountId, name: 'Statement Account' },
          state: { unresolved: [rows[1], rows[2]], reconciled: [] },
          preselections: [],
        }),
      });
    }
    if (payload.action !== 'batchReconcileStatementExpenses') return route.continue();
    submitted = payload;
    return route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        ok: false,
        results: [
          { statement_row_id: successfulRowId, status: 'saved', target_kind: 'transaction', transaction_id: 940001 },
          { statement_row_id: failedRowId, status: 'error', error: backendError },
        ],
      }),
    });
  });

  const successfulRow = page.locator(`tr[data-row-id="${successfulRowId}"]`);
  const failedRow = page.locator(`tr[data-row-id="${failedRowId}"]`);
  const untouchedRow = page.locator(`tr[data-row-id="${untouchedRowId}"]`);
  await successfulRow.locator('[data-exception-classification]').selectOption(`envelope:${envelopeId}`);
  await failedRow.locator('[data-exception-classification]').selectOption(`envelope:${envelopeId}`);
  await page.locator('#saveReconciliationExceptionsBtn').click();

  expect(submitted.selections).toHaveLength(2);
  await expect(successfulRow).toHaveCount(0);
  await expect(failedRow).toBeVisible();
  await expect(failedRow).toHaveClass(/pending/);
  await expect(failedRow.locator('[data-exception-classification]')).toHaveValue(`envelope:${envelopeId}`);
  await expect(failedRow.locator('.reconciliation-row-error')).toHaveText(backendError);
  await expect(untouchedRow).toBeVisible();
  await expect(untouchedRow).not.toHaveClass(/pending/);
  await expect(untouchedRow.locator('[data-exception-classification]')).toHaveValue('');
  await expect(untouchedRow).toContainText('Untouched evidence');
  await expect(page.locator('#reconciliationMessage')).toHaveText('Some rows could not be saved.');
  expect(await page.evaluate((rowId) => state.reconciliation.pending[String(rowId)], failedRowId)).toEqual({ statement_row_id: failedRowId, classification: `envelope:${envelopeId}` });
  expect(await page.evaluate((rowId) => state.reconciliation.errors[String(rowId)], failedRowId)).toBe(backendError);

  await failedRow.locator('[data-exception-classification]').selectOption('');
  await expect(failedRow.locator('.reconciliation-row-error')).toHaveCount(0);
});

test('history preselection is an ordinary editable choice and Close writes nothing', async ({ page }) => {
  await openAuthenticatedApp(page);
  const statementAccountId = 950001;
  const alphaAccountId = 950002;
  const zuluAccountId = 950003;
  const alphaEnvelopeId = 960001;
  const zuluEnvelopeId = 960002;
  const rowId = 970001;
  const currency = await page.evaluate(() => state.currency);
  const row = {
    id: rowId,
    booking_date: '2026-09-12',
    amount: -42,
    currency,
    description_original: 'History-matched merchant',
    exception_reason: 'Envelope required',
  };
  let batchRequests = 0;
  let submitted = null;
  let saved = false;

  await page.evaluate(({ statementAccountId, alphaAccountId, zuluAccountId, alphaEnvelopeId, zuluEnvelopeId }) => {
    state.accounts = [
      { id: statementAccountId, name: 'Statement Account', account_number: 'SOURCE', currency: 'CURRENCY', active: 1 },
      { id: zuluAccountId, name: 'Zulu Account', account_number: 'ZULU', currency: 'CURRENCY', active: 1 },
      { id: alphaAccountId, name: 'Alpha Account', account_number: 'ALPHA', currency: 'CURRENCY', active: 1 },
    ];
    state.envelopes = [
      { id: zuluEnvelopeId, name: 'Zulu Envelope', currency: 'CURRENCY', role: 'daily' },
      { id: alphaEnvelopeId, name: 'Alpha Envelope', currency: 'CURRENCY', role: 'daily' },
    ];
  }, { statementAccountId, alphaAccountId, zuluAccountId, alphaEnvelopeId, zuluEnvelopeId });

  await page.route('**/api.php', async (route) => {
    const request = route.request();
    if (request.method() !== 'POST') return route.continue();
    let payload = {};
    try { payload = request.postDataJSON(); } catch { return route.continue(); }
    if (payload.action === 'loadStatementReconciliation') {
      const account = stateAccount(payload.account_id);
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          account,
          state: { unresolved: !saved && Number(payload.account_id) === statementAccountId ? [row] : [], reconciled: [] },
          preselections: !saved && Number(payload.account_id) === statementAccountId
            ? [{ statement_row_id: rowId, classification: `envelope:${zuluEnvelopeId}` }]
            : [],
        }),
      });
    }
    if (payload.action === 'batchReconcileStatementExpenses') {
      batchRequests++;
      submitted = payload;
      saved = true;
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          ok: true,
          results: [{ statement_row_id: rowId, status: 'saved', target_kind: 'transaction', transaction_id: 980001 }],
        }),
      });
    }
    return route.continue();
  });

  await page.evaluate(() => openReconciliationDialog());
  const dropdown = page.locator(`tr[data-row-id="${rowId}"] [data-exception-classification]`);
  await expect(dropdown).toHaveValue(`envelope:${zuluEnvelopeId}`);
  await expect(page.locator('#saveReconciliationExceptionsBtn')).toBeEnabled();
  await expect(dropdown.locator('optgroup')).toHaveCount(2);
  expect(await dropdown.locator('optgroup').evaluateAll((groups) => groups.map((group) => group.label))).toEqual(['Envelopes', 'Transfer']);
  await expect(dropdown.locator('optgroup[label="Envelopes"] option')).toHaveText(['Alpha Envelope', 'Zulu Envelope']);
  await expect(dropdown.locator('optgroup[label="Transfer"] option')).toHaveText(['to Alpha Account', 'to Zulu Account']);

  await page.locator('#closeReconciliationDialogBtn').click();
  await expect(page.locator('#reconciliationDialog')).not.toBeVisible();
  await page.waitForTimeout(100);
  expect(batchRequests).toBe(0);

  await page.evaluate(() => openReconciliationDialog());
  const reopened = page.locator(`tr[data-row-id="${rowId}"] [data-exception-classification]`);
  await expect(reopened).toHaveValue(`envelope:${zuluEnvelopeId}`);
  await reopened.selectOption(`envelope:${alphaEnvelopeId}`);
  await page.locator('#saveReconciliationExceptionsBtn').click();
  expect(batchRequests).toBe(1);
  expect(submitted.selections).toEqual([{ statement_row_id: rowId, classification: `envelope:${alphaEnvelopeId}` }]);
  await expect(page.locator(`tr[data-row-id="${rowId}"]`)).toHaveCount(0);
});

test('IGNORE-winning history stays neutral through the API and rendered dropdown', async ({ page, finWorker }) => {
  await openAuthenticatedApp(page);
  const suffix = `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`;
  const statementAccountId = await createAccount(page, `History Statement Account ${suffix}`, `HISTORY-${suffix}`);
  const envelopeResponse = await page.request.post('/api.php', {
    headers: { 'content-type': 'application/json' },
    data: {
      action: 'saveEnvelope',
      year: new Date().getFullYear(),
      envelope: { name: `Mistaken History Envelope ${suffix}`, role: 'daily', currency: 'CURRENCY', visibility: 'normal' },
    },
  });
  expect(envelopeResponse.ok()).toBeTruthy();
  const envelopeId = Number((await envelopeResponse.json()).id);

  const transactionResponse = await page.request.post('/api.php', {
    headers: { 'content-type': 'application/json' },
    data: {
      action: 'createTransaction',
      transaction: {
        envelope_id: envelopeId,
        payment_account_id: statementAccountId,
        amount: 10,
        note: 'Monthly cloud serv1ce subscription',
        spent_date: '2026-09-01',
      },
    },
  });
  expect(transactionResponse.ok()).toBeTruthy();

  const currentRowId = Number(runDatabasePhp(finWorker, `
    $db = new PDO('sqlite:' . getenv('FIN_DB_FILE'));
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA foreign_keys=ON');
    $insertImport = $db->prepare("INSERT INTO statement_imports
      (account_id,provider,original_filename,file_checksum,parser_version,row_count,valid_row_count,problem_count)
      VALUES (:account_id,'test_provider','ignore-history.xlsx',:checksum,'2',2,2,0)");
    $insertImport->execute([':account_id' => ${statementAccountId}, ':checksum' => hash('sha256', '${suffix}|import')]);
    $importId = (int)$db->lastInsertId();
    $insertRow = $db->prepare("INSERT INTO statement_rows
      (import_id,account_id,provider,source_row_number,booking_date,value_date,amount,currency,
       description_original,description_normalized,row_fingerprint,occurrence_index,parse_problem,raw_fields_json)
      VALUES (:import_id,:account_id,'test_provider',:source_row,'2026-09-02','2026-09-02',-25,'DKK',
       :description,:normalized,:fingerprint,0,'','{}')");
    $description = 'Monthly cloud service subscription';
    $insertRow->execute([
      ':import_id' => $importId, ':account_id' => ${statementAccountId}, ':source_row' => 1,
      ':description' => $description, ':normalized' => strtolower($description),
      ':fingerprint' => hash('sha256', '${suffix}|ignored'),
    ]);
    $ignoredRowId = (int)$db->lastInsertId();
    $db->exec("INSERT INTO statement_reconciliations (statement_row_id,disposition) VALUES ($ignoredRowId,'no_fin_action')");
    $insertRow->execute([
      ':import_id' => $importId, ':account_id' => ${statementAccountId}, ':source_row' => 2,
      ':description' => $description, ':normalized' => strtolower($description),
      ':fingerprint' => hash('sha256', '${suffix}|current'),
    ]);
    echo $db->lastInsertId();
  `));

  const response = await page.request.post('/api.php', {
    headers: { 'content-type': 'application/json' },
    data: { action: 'loadStatementReconciliation', account_id: statementAccountId },
  });
  expect(response.ok()).toBeTruthy();
  const payload = await response.json();
  expect(payload.state.unresolved.some((row) => Number(row.id) === currentRowId)).toBeTruthy();
  expect(payload.preselections).toEqual([]);

  const appState = await page.evaluate(async () => apiGet(`state&year=${new Date().getFullYear()}`));
  await page.evaluate(({ payload, appState }) => {
    state.accounts = appState.accounts;
    state.envelopes = appState.envelopes;
    state.reconciliation = {
      account_id: payload.account.id,
      account_name: payload.account.name,
      unresolved: payload.state.unresolved,
      unresolved_count: payload.state.unresolved_count,
      reconciled: payload.state.reconciled || [],
      pending: Object.fromEntries((payload.preselections || []).map((selection) => [String(selection.statement_row_id), selection])),
      errors: {},
    };
    renderReconciliationState();
    document.querySelector('#reconciliationDialog').showModal();
  }, { payload, appState });

  const row = page.locator(`tr[data-row-id="${currentRowId}"]`);
  const dropdown = row.locator('[data-exception-classification]');
  await expect(dropdown).toHaveValue('');
  await expect(dropdown.locator('option:checked')).toHaveText('—');
  expect(await dropdown.locator('option[value="ignore"]').evaluate((option) => option.selected)).toBeFalsy();
  await expect(row).not.toHaveClass(/pending/);
  await expect(page.locator('#saveReconciliationExceptionsBtn')).toBeDisabled();
});

function stateAccount(accountId) {
  const names = {
    910101: 'Account A',
    910102: 'Account B',
    910103: 'Account C',
    910104: 'Account D',
    950001: 'Statement Account',
    950002: 'Alpha Account',
    950003: 'Zulu Account',
  };
  return { id: Number(accountId), name: names[Number(accountId)] || `Account ${accountId}` };
}

test('global Reconcile shows the full unresolved count while refilling and advancing Accounts', async ({ page }) => {
  await openAuthenticatedApp(page);
  const firstEmptyId = 910101;
  const secondEmptyId = 910102;
  const statementAccountId = 910103;
  const nextAccountId = 910104;
  const envelopeId = 910110;
  const currency = await page.evaluate(() => state.currency);
  const statementRows = Array.from({ length: 34 }, (_, index) => ({
    id: 911000 + index,
    booking_date: '2026-09-15',
    amount: -(index + 1),
    currency,
    description_original: `Bounded statement row ${index + 1}`,
  }));
  const nextRows = Array.from({ length: 2 }, (_, index) => ({
    id: 912000 + index,
    booking_date: '2026-09-14',
    amount: -(index + 1),
    currency,
    description_original: `Next Account row ${index + 1}`,
  }));
  const remaining = new Map([
    [firstEmptyId, []],
    [secondEmptyId, []],
    [statementAccountId, [...statementRows]],
    [nextAccountId, [...nextRows]],
  ]);
  const loadOrder = [];
  const batchSizes = [];

  await page.evaluate(({ firstEmptyId, secondEmptyId, statementAccountId, nextAccountId, envelopeId }) => {
    state.accounts = [
      { id: firstEmptyId, name: 'Account A', account_number: 'A', currency: 'CURRENCY', active: 1 },
      { id: secondEmptyId, name: 'Account B', account_number: 'B', currency: 'CURRENCY', active: 1 },
      { id: statementAccountId, name: 'Account C', account_number: 'C', currency: 'CURRENCY', active: 1 },
      { id: nextAccountId, name: 'Account D', account_number: 'D', currency: 'CURRENCY', active: 1 },
    ];
    state.envelopes = [{ id: envelopeId, name: 'Purpose', currency: 'CURRENCY', role: 'daily' }];
  }, { firstEmptyId, secondEmptyId, statementAccountId, nextAccountId, envelopeId });

  await page.route('**/api.php', async (route) => {
    const request = route.request();
    if (request.method() !== 'POST') return route.continue();
    let payload = {};
    try { payload = request.postDataJSON(); } catch { return route.continue(); }
    if (payload.action === 'loadStatementReconciliation') {
      const accountId = Number(payload.account_id);
      loadOrder.push(accountId);
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          account: stateAccount(accountId),
          state: {
            unresolved: (remaining.get(accountId) || []).slice(0, 32),
            unresolved_count: (remaining.get(accountId) || []).length,
            reconciled: [],
          },
          preselections: [],
        }),
      });
    }
    if (payload.action === 'batchReconcileStatementExpenses') {
      const accountId = Number(payload.account_id);
      const savedIds = new Set(payload.selections.map((selection) => Number(selection.statement_row_id)));
      batchSizes.push(savedIds.size);
      remaining.set(accountId, (remaining.get(accountId) || []).filter((row) => !savedIds.has(Number(row.id))));
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          ok: true,
          results: [...savedIds].map((rowId) => ({ statement_row_id: rowId, status: 'saved', target_kind: 'transaction', transaction_id: rowId + 100000 })),
        }),
      });
    }
    return route.continue();
  });

  await page.evaluate(() => openReconciliationDialog());
  await expect(page.locator('#reconciliationDialogSubtitle')).toHaveText('Account C — 34 unresolved.');
  await expect(page.locator('[data-exception-classification]')).toHaveCount(32);
  expect(loadOrder).toEqual([firstEmptyId, secondEmptyId, statementAccountId]);

  const firstTwo = page.locator('[data-exception-classification]').first();
  const second = page.locator('[data-exception-classification]').nth(1);
  await firstTwo.selectOption(`envelope:${envelopeId}`);
  await second.selectOption(`envelope:${envelopeId}`);
  await page.locator('#saveReconciliationExceptionsBtn').click();

  await expect(page.locator('[data-exception-classification]')).toHaveCount(32);
  await expect(page.locator('#reconciliationDialogSubtitle')).toHaveText('Account C — 32 unresolved.');
  await expect(page.locator(`tr[data-row-id="${statementRows[32].id}"]`)).toBeVisible();
  await expect(page.locator(`tr[data-row-id="${statementRows[33].id}"]`)).toBeVisible();
  expect(batchSizes).toEqual([2]);
  expect(loadOrder.slice(-1)).toEqual([statementAccountId]);

  await page.locator('[data-exception-classification]').evaluateAll((selects, value) => {
    for (const select of selects) {
      select.value = value;
      select.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }, `envelope:${envelopeId}`);
  await page.locator('#saveReconciliationExceptionsBtn').click();

  await expect(page.locator('#reconciliationDialogSubtitle')).toHaveText('Account D — 2 unresolved.');
  await expect(page.locator('[data-exception-classification]')).toHaveCount(2);
  expect(batchSizes).toEqual([2, 32]);
  expect(loadOrder.slice(-2)).toEqual([statementAccountId, nextAccountId]);
  await expect(page.locator('#reconciliationDialog')).toBeVisible();
});

test('global Reconcile exposes a batch-load failure instead of fabricating an empty Account', async ({ page }) => {
  await openAuthenticatedApp(page);
  const firstAccountId = 913001;
  const failingAccountId = 913002;
  const loadOrder = [];

  await page.evaluate(({ firstAccountId, failingAccountId }) => {
    state.accounts = [
      { id: firstAccountId, name: 'Account A', account_number: 'A', currency: 'CURRENCY', active: 1 },
      { id: failingAccountId, name: 'Account B', account_number: 'B', currency: 'CURRENCY', active: 1 },
    ];
    state.reconciliation = null;
  }, { firstAccountId, failingAccountId });

  await page.route('**/api.php', async (route) => {
    const request = route.request();
    if (request.method() !== 'POST') return route.continue();
    let payload = {};
    try { payload = request.postDataJSON(); } catch { return route.continue(); }
    if (payload.action !== 'loadStatementReconciliation') return route.continue();
    loadOrder.push(Number(payload.account_id));
    if (Number(payload.account_id) === firstAccountId) {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ account: { id: firstAccountId, name: 'Account A' }, state: { unresolved: [], reconciled: [] }, preselections: [] }),
      });
    }
    return route.fulfill({
      status: 500,
      contentType: 'application/json',
      body: JSON.stringify({ error: 'Synthetic batch load failed' }),
    });
  });

  await page.evaluate(() => openReconciliationDialog());

  expect(loadOrder).toEqual([firstAccountId, failingAccountId]);
  await expect(page.locator('#reconciliationMessage')).toContainText('Could not load statement exceptions for Account B: Synthetic batch load failed');
  await expect(page.locator('#reconciliationEmpty')).toBeHidden();
  await expect(page.locator('#reconciliationUnresolvedSection')).toBeHidden();
  await expect(page.locator('#reconciliationDialogSubtitle')).toBeEmpty();
  await expect(page.locator('#saveReconciliationExceptionsBtn')).toBeDisabled();
});

test('global Reconcile retains the ordinary empty state when every small Account is exhausted', async ({ page }) => {
  await openAuthenticatedApp(page);
  const firstAccountId = 914001;
  const secondAccountId = 914002;
  const loadOrder = [];

  await page.evaluate(({ firstAccountId, secondAccountId }) => {
    state.accounts = [
      { id: firstAccountId, name: 'Account A', account_number: 'A', currency: 'CURRENCY', active: 1 },
      { id: secondAccountId, name: 'Account B', account_number: 'B', currency: 'CURRENCY', active: 1 },
    ];
  }, { firstAccountId, secondAccountId });

  await page.route('**/api.php', async (route) => {
    const request = route.request();
    if (request.method() !== 'POST') return route.continue();
    let payload = {};
    try { payload = request.postDataJSON(); } catch { return route.continue(); }
    if (payload.action !== 'loadStatementReconciliation') return route.continue();
    const accountId = Number(payload.account_id);
    loadOrder.push(accountId);
    return route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ account: stateAccount(accountId), state: { unresolved: [], reconciled: [] }, preselections: [] }),
    });
  });

  await page.evaluate(() => openReconciliationDialog());

  expect(loadOrder).toEqual([firstAccountId, secondAccountId]);
  await expect(page.locator('#reconciliationDialogSubtitle')).toContainText('Account A');
  await expect(page.locator('#reconciliationEmpty')).toBeVisible();
  await expect(page.locator('#reconciliationMessage')).toBeHidden();
});

test('Persisted statement text is repaired and exceptions serialization fails closed as JSON', async ({ page, finWorker }) => {
  await openAuthenticatedApp(page);
  const accountId = await createAccount(page, 'Encoding test account', `ENCODING-${Date.now()}`);
  const fingerprint = 'f'.repeat(64);
  runDatabasePhp(finWorker, `
    $db = new PDO('sqlite:' . getenv('FIN_DB_FILE'));
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA foreign_keys=ON');
    $insertImport = $db->prepare("INSERT INTO statement_imports
      (account_id,provider,original_filename,file_checksum,parser_version,row_count,valid_row_count,problem_count)
      VALUES (:account_id,'evidence:alpha','encoding.xlsx',:checksum,'2',1,1,0)");
    $insertImport->execute([':account_id' => ${accountId}, ':checksum' => str_repeat('e', 64)]);
    $insertRow = $db->prepare("INSERT INTO statement_rows
      (import_id,account_id,provider,source_row_number,booking_date,value_date,amount,currency,
       description_original,description_normalized,row_fingerprint,occurrence_index,parse_problem,raw_fields_json)
      VALUES (:import_id,:account_id,'evidence:alpha',139,'2025-01-28','2025-01-28',-25,'DKK',
       :description,:normalized,:fingerprint,0,'','{}')");
    $insertRow->execute([
      ':import_id' => (int)$db->lastInsertId(), ':account_id' => ${accountId},
      ':description' => hex2bin('CE6C652D64652D4672616E6365204D6F62696C6974C3A973'),
      ':normalized' => hex2bin('CE6C652D64652D6672616E6365206D6F62696C6974C3A973'),
      ':fingerprint' => '${fingerprint}',
    ]);
  `);

  const repairedResponse = await page.request.post('/api.php', {
    headers: { 'content-type': 'application/json' },
    data: { action: 'loadStatementReconciliation', account_id: accountId },
  });
  expect(repairedResponse.status()).toBe(200);
  expect(repairedResponse.headers()['content-type']).toContain('application/json');
  const repairedPayload = await repairedResponse.json();
  expect(repairedPayload.state.unresolved).toHaveLength(1);
  expect(repairedPayload.state.unresolved[0].description_original).toBe('Île-de-France Mobilités');
  expect(Number(repairedPayload.state.unresolved[0].amount)).toBe(-25);
  expect(Number(repairedPayload.state.unresolved_count)).toBe(1);

  const storedHex = runDatabasePhp(finWorker, `
    $db = new PDO('sqlite:' . getenv('FIN_DB_FILE'));
    echo $db->query('SELECT hex(description_original) FROM statement_rows ORDER BY id DESC LIMIT 1')->fetchColumn();
  `);
  expect(storedHex).toBe(Buffer.from('Île-de-France Mobilités', 'utf8').toString('hex').toUpperCase());

  runDatabasePhp(finWorker, `
    $db = new PDO('sqlite:' . getenv('FIN_DB_FILE'));
    $stmt = $db->prepare('UPDATE accounts SET name=:name WHERE id=:id');
    $stmt->execute([':name' => hex2bin('CE'), ':id' => ${accountId}]);
  `);
  const encodingFailure = await page.request.post('/api.php', {
    headers: { 'content-type': 'application/json' },
    data: { action: 'loadStatementReconciliation', account_id: accountId },
  });
  expect(encodingFailure.status()).toBe(500);
  expect(encodingFailure.headers()['content-type']).toContain('application/json');
  expect(await encodingFailure.json()).toEqual({ error: 'Response could not be encoded as JSON.' });
});
