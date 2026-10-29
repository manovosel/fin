const { test, expect } = require('./fixtures.cjs');
const { openAuthenticatedApp } = require('./auth.cjs');

test('historical import workflow never shows the user a tracking date or baseline move UI', async ({ page }) => {
  await openAuthenticatedApp(page);

  // 1. Account UI exposes no date input, no baseline-move details, and no
  // user-facing tracking-date terminology.
  await page.getByRole('button', { name: 'Month', exact: true }).click();
  const accountsDetails = page.locator('#accountsDetails');
  await accountsDetails.locator(':scope > summary').click();
  await expect(page.locator('#accountsList')).toBeVisible();
  await accountsDetails.getByRole('button', { name: 'Add Account' }).click();
  await expect(page.locator('#accountDialog')).toBeVisible();

  // No tracking date input.
  await expect(page.locator('#accountInitialDate')).toHaveCount(0);
  // No "Move tracking start earlier" or baseline details in the Account dialog.
  await expect(page.locator('#accountBaselineDetails')).toHaveCount(0);
  await expect(page.locator('#accountBaselineForm')).toHaveCount(0);
  // The "Initial Balance" section is now "Current Balance".
  await expect(page.locator('#accountInitialSection')).toContainText('Current Balance');
  // The whole dialog body must not contain user-facing tracking-date wording.
  const dialogText = (await page.locator('#accountDialog').textContent()) || '';
  expect(dialogText.toLowerCase()).not.toContain('tracking start');
  expect(dialogText.toLowerCase()).not.toContain('history baseline');
  expect(dialogText.toLowerCase()).not.toContain('tracking date');

  // Close the dialog; this verifies the no-tracking-date contract for new
  // and existing Accounts alike. We re-open for an existing Account by mocking
  // the state.
  await page.locator('#closeAccountDialogBtn').click();
  await expect(page.locator('#accountDialog')).not.toBeVisible();

  // 2. The dialog opened for an existing Account also hides the baseline
  // details panel — confirming that user-facing Account management never asks
  // the operator to manage a tracking date.
  await page.evaluate(() => {
    state.accounts = state.accounts.concat([{
      id: 950001,
      name: 'Existing browser account',
      account_number: 'TEST-EXISTING-BROWSER',
      currency: 'CURRENCY',
      active: 1,
      balance_known: true,
      balance: 0,
      display_balance: 0,
      display_currency: 'DKK',
      conversion_available: true,
      movement_count: 0,
      last_movement_date: null,
    }]);
    renderAccountsList();
  });

  await page.locator('.managed-account-row').filter({ hasText: 'Existing browser account' }).click();
  await expect(page.locator('#accountDialog')).toBeVisible();
  await expect(page.locator('#accountInitialSection')).toBeHidden();
  await expect(page.locator('#accountBaselineDetails')).toHaveCount(0);
  await expect(page.locator('#accountBaselineForm')).toHaveCount(0);
  const existingDialogText = (await page.locator('#accountDialog').textContent()) || '';
  expect(existingDialogText.toLowerCase()).not.toContain('tracking start');
  expect(existingDialogText.toLowerCase()).not.toContain('history baseline');
  expect(existingDialogText.toLowerCase()).not.toContain('tracking date');
  await page.locator('#closeAccountDialogBtn').click();
});

test('historical Expense reconciles without ever leaving the workflow or showing tracking-date language', async ({ page }) => {
  await openAuthenticatedApp(page);

  const successfulRowId = 980001;
  const envelopeId = 980010;
  const statementAccountId = 980020;
  const transferAccountId = 980021;

  // Pre-baseline rows require automatic baseline derivation. The reconciliation
  // request returns no error because the workflow already moved the baseline.
  let batchPayload = null;
  await page.route('**/api.php', async (route) => {
    const request = route.request();
    if (request.method() !== 'POST') return route.continue();
    let payload = {};
    try { payload = request.postDataJSON(); } catch { return route.continue(); }
    if (payload.action === 'loadStatementReconciliation') {
      const accountId = Number(payload.account_id);
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          account: { id: accountId, name: accountId === statementAccountId ? 'Statement Account' : 'Transfer Account' },
          state: { unresolved: [], reconciled: [] },
          preselections: [],
        }),
      });
    }
    if (payload.action !== 'batchReconcileStatementExpenses') return route.continue();
    batchPayload = payload;
    return route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        ok: true,
        results: [
          { statement_row_id: successfulRowId, status: 'saved', target_kind: 'transaction', transaction_id: 980030 },
        ],
      }),
    });
  });

  await page.evaluate(({ rows, statementAccountId, transferAccountId, envelopeId }) => {
    state.accounts = [
      { id: statementAccountId, name: 'Statement Account', account_number: 'TEST-STATEMENT', currency: 'CURRENCY', active: 1, balance_known: false, balance: null, display_balance: null, display_currency: 'DKK', conversion_available: false, movement_count: 0, last_movement_date: null },
      { id: transferAccountId, name: 'Transfer Account', account_number: 'TEST-TRANSFER', currency: 'CURRENCY', active: 1, balance_known: false, balance: null, display_balance: null, display_currency: 'DKK', conversion_available: false, movement_count: 0, last_movement_date: null },
    ];
    state.envelopes = [{ id: envelopeId, name: 'Historical purpose', currency: 'CURRENCY', role: 'daily' }];
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
  }, {
    rows: [
      { id: successfulRowId, booking_date: '2025-08-03', amount: -42.5, currency: 'DKK', description_original: 'Historical evidence', exception_reason: 'Envelope required' },
    ],
    statementAccountId,
    transferAccountId,
    envelopeId,
  });

  await page.locator(`tr[data-row-id="${successfulRowId}"] [data-exception-classification]`).selectOption(`envelope:${envelopeId}`);
  await page.locator('#saveReconciliationExceptionsBtn').click();

  expect(batchPayload).not.toBeNull();
  // The Save workflow never had to ask the user to manage a tracking date.
  const dialogText = (await page.locator('#reconciliationDialog').textContent()) || '';
  expect(dialogText.toLowerCase()).not.toContain('tracking start');
  expect(dialogText.toLowerCase()).not.toContain('history baseline');
  expect(dialogText.toLowerCase()).not.toContain('tracking date');

  // The reconciliation dialog remains open and reachable: the user never has
  // to leave the workflow to manage a tracking baseline.
  await expect(page.locator('#reconciliationDialog')).toBeVisible();
  await page.locator('#closeReconciliationDialogBtn').click();
});
