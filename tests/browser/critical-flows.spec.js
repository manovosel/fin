const { test, unauthenticatedTest, expect } = require('./fixtures.cjs');
const { openAuthenticatedApp, signInWithMasterPin, waitForApiAction } = require('./auth.cjs');
const path = require('node:path');
const fs = require('node:fs');
const { buildSyntheticDebitCreditPreheaderXlsx, buildSyntheticSignedAccountXlsx, writeTempXlsx } = require('./SyntheticXlsx.cjs');

// Lazy per-test statement fixture. Each call returns a fresh tmpdir,
// filepath, and the seeded account/number used to mint the XLSX so the
// caller can make assertions against the same numeric values the test
// uses to set up the Account. The XLSX content originates entirely from
// SyntheticXlsx.cjs and never opens or copies a real statement file.
function synthSignedAccountPath(testId, opts = {}) {
  const suffix = testId + '-' + Date.now().toString(36) + '-' + Math.floor(Math.random() * 1e6).toString(36);
  const accountNumber = opts.accountNumber || ('TEST-SIGNED-' + suffix);
  const accountName = opts.accountName || ('Synthetic Account ' + suffix);
  const rows = opts.rows || [
    { booking_date: opts.dateA || '2026-04-10', amount: -50,   description: 'Synthetic Test Bank row ' + suffix + ' A', running_balance: 950 },
    { booking_date: opts.dateB || '2026-04-11', amount: 100,   description: 'Synthetic Test Bank row ' + suffix + ' B', running_balance: 1050 },
    { booking_date: opts.dateC || '2026-04-12', amount: -75.5, description: 'Synthetic Test Bank row ' + suffix + ' C', running_balance: 974.5 },
  ];
  const bytes = buildSyntheticSignedAccountXlsx({ accountNumber, accountName, rows });
  return Object.assign({ rows, accountNumber, accountName }, writeTempXlsx('fin-cf-signed-' + testId, bytes));
}

function synthSignedAccountRefundPath(testId, opts = {}) {
  const suffix = testId + '-' + Date.now().toString(36) + '-' + Math.floor(Math.random() * 1e6).toString(36);
  const accountNumber = opts.accountNumber || ('TEST-SIGNED-REFUND-' + suffix);
  const accountName = opts.accountName || ('Synthetic Account ' + suffix);
  const rows = opts.rows || [
    { booking_date: '2026-04-15', amount: 30, description: 'Synthetic Test Bank refund row ' + suffix, running_balance: 500 },
  ];
  const bytes = buildSyntheticSignedAccountXlsx({ accountNumber, accountName, rows });
  return Object.assign({ rows, accountNumber, accountName }, writeTempXlsx('fin-cf-signed-refund-' + testId, bytes));
}

// Tiny local helper used only by the in-flight busy-state tests below:
// installs one held-response route handler for `**/api.php` that waits
// for an explicit release() signal before fulfilling, instead of using a
// fixed setTimeout delay. body may be a plain object (used for every
// matching request) or a function (payload) => object.
async function holdApiActionForRelease(page, { action, body }) {
  let requestCount = 0;
  let releaseHold;
  const holdPromise = new Promise((resolve) => { releaseHold = resolve; });
  let markFulfilled;
  const fulfilled = new Promise((resolve) => { markFulfilled = resolve; });
  await page.route('**/api.php', async (route) => {
    const request = route.request();
    if (request.method() !== 'POST') return route.continue();
    let payload = {};
    try { payload = request.postDataJSON() || {}; } catch { return route.continue(); }
    if (action && payload.action !== action) return route.continue();
    requestCount += 1;
    await holdPromise;
    const serialized = typeof body === 'function' ? JSON.stringify(body(payload)) : JSON.stringify(body);
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: serialized,
    });
    markFulfilled();
  });
  return {
    release: () => releaseHold(),
    fulfilled,
    requestCount: () => requestCount,
  };
}

unauthenticatedTest('version is public and state requires authentication', async ({ request, releaseVersions }) => {
  const version = await request.get('/api.php?action=version');
  expect(version.ok()).toBeTruthy();
  expect(await version.json()).toEqual(releaseVersions);

  const me = await request.get('/api.php?action=me');
  expect(me.ok()).toBeTruthy();
  expect(me.headers()['cache-control']).toContain('no-store');
  const authentication = await me.json();
  expect(authentication).toMatchObject({ authenticated: false, master_pin_configured: true, configuration_required: false });
  expect(JSON.stringify(authentication)).not.toMatch(/username|password|hash|master_pin_file|throttle_file|\/etc\/fin|12345678/i);

  const state = await request.get('/api.php?action=state');
  expect(state.status()).toBe(401);
  expect((await state.json()).error).toContain('Authentication');
});

unauthenticatedTest('no enrolled device falls back to Master PIN and rejects a wrong PIN without username', async ({ page }) => {
  const pageErrors = [];
  page.on('pageerror', (error) => pageErrors.push(error.message));
  await page.goto('/');
  await expect(page.locator('#pinLoginForm')).toBeVisible();
  await expect(page.locator('#loginUsername')).toHaveCount(0);
  await expect(page.getByText('Password', { exact: true })).toHaveCount(0);
  await page.locator('#masterPin').fill('00000000');
  await page.locator('#pinLoginForm').getByRole('button', { name: 'Sign in' }).click();
  await expect(page.locator('#authView')).toBeVisible();
  await expect(page.locator('#appView')).toBeHidden();
  await expect(page.locator('#authMessage')).toContainText('Authentication failed');
  expect(pageErrors).toEqual([]);
});

unauthenticatedTest('Master PIN authentication regenerates the secure FIN session and logout invalidates it', async ({ page, context }) => {
  await page.goto('/');
  const before = (await context.cookies()).find((cookie) => cookie.name === 'FINSESSID');
  expect(before).toBeTruthy();
  await page.locator('#masterPin').fill('12345678');
  await page.locator('#pinLoginForm').getByRole('button', { name: 'Sign in' }).click();
  await expect(page.locator('#appView')).toBeVisible();
  const after = (await context.cookies()).find((cookie) => cookie.name === 'FINSESSID');
  expect(after.value).not.toBe(before.value);
  expect(after.httpOnly).toBeTruthy();
  expect(after.sameSite).toBe('Lax');
  await page.getByRole('button', { name: 'Logout' }).click();
  await expect(page.locator('#pinLoginForm')).toBeVisible();
  const loggedOut = (await context.cookies()).find((cookie) => cookie.name === 'FINSESSID');
  expect(loggedOut?.value).not.toBe(after.value);
});

test('app exposes an installable PWA shell without caching API data', async ({ page, request }) => {
  const manifestResponse = await request.get('/manifest.webmanifest');
  expect(manifestResponse.ok()).toBeTruthy();
  const manifest = await manifestResponse.json();
  expect(manifest).toMatchObject({
    id: './',
    name: 'FIN Planner',
    short_name: 'FIN',
    start_url: './',
    scope: './',
    display: 'standalone',
    background_color: '#101010',
    theme_color: '#2f8f3a',
  });
  expect(manifest.icons).toEqual(expect.arrayContaining([
    expect.objectContaining({ src: 'icons/fin-192.png', sizes: '192x192', type: 'image/png', purpose: 'any' }),
    expect.objectContaining({ src: 'icons/fin-512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' }),
  ]));

  for (const size of [192, 512]) {
    const iconResponse = await request.get(`/icons/fin-${size}.png`);
    expect(iconResponse.ok()).toBeTruthy();
    const icon = await iconResponse.body();
    expect(icon.subarray(1, 4).toString()).toBe('PNG');
    expect(icon.readUInt32BE(16)).toBe(size);
    expect(icon.readUInt32BE(20)).toBe(size);
  }

  await page.goto('/');
  await expect(page.locator('link[rel="manifest"]')).toHaveAttribute('href', 'manifest.webmanifest');
  await expect(page.locator('link[rel="manifest"]')).toHaveAttribute('crossorigin', 'use-credentials');
  await expect(page.locator('link[rel="apple-touch-icon"]')).toHaveAttribute('href', 'icons/fin-180.png');
  await expect(page.locator('script:not([src])')).toHaveCount(0);
  const serviceWorker = await page.evaluate(async () => {
    const registration = await navigator.serviceWorker.ready;
    const response = await fetch('./service-worker.js');
    const source = await response.text();
    await fetch('./api.php?action=version');
    const cacheNames = await caches.keys();
    const cachedRequests = (await Promise.all(cacheNames.map(async (name) => (await caches.open(name)).keys()))).flat();
    return {
      scope: new URL(registration.scope).pathname,
      active: registration.active?.state,
      excludesApi: source.includes("url.pathname.endsWith('/api.php')"),
      cachedApiUrls: cachedRequests.map((request) => request.url).filter((url) => url.includes('/api.php')),
    };
  });
  expect(serviceWorker).toEqual({ scope: '/', active: 'activated', excludesApi: true, cachedApiUrls: [] });

  const cdp = await page.context().newCDPSession(page);
  expect((await cdp.send('Page.getAppManifest')).errors).toEqual([]);
  const installabilityErrors = (await cdp.send('Page.getInstallabilityErrors')).installabilityErrors;
  expect(installabilityErrors.filter((error) => error.errorId !== 'in-incognito')).toEqual([]);
});

test('dates display as dd/mm/yyyy, every date field has a native picker, and BS defaults to its Plan month', async ({ page }) => {
  await openAuthenticatedApp(page);

  const inventory = await page.evaluate(() => [...document.querySelectorAll('input[type="date"]')].map((input) => ({
    id: input.id,
    wrapped: Boolean(input.closest('.date-input-shell')),
    pickerFor: input.closest('.date-input-shell')?.querySelector('.date-picker-button')?.dataset.datePickerFor || '',
    display: input.closest('.date-input-shell')?.querySelector('.date-input-display')?.textContent || '',
  })));
  expect(inventory.map((item) => item.id).sort()).toEqual([
    'accountReconcileDate',
    'dueDate',
    'envelopeBalanceObservationDate',
    'expenseDate',
    'transferDate',
  ]);
  expect(inventory.every((item) => item.wrapped && item.pickerFor === item.id)).toBeTruthy();
  expect(inventory.every((item) => item.display === 'dd/mm/yyyy')).toBeTruthy();
  expect(await page.evaluate(() => formatDate('2026-10-20'))).toBe('20/10/2026');

  const scenario = await page.evaluate(() => {
    const month = { id: 990017, year: 2099, month: 10, exchange_rate: 7.46, salary: 0, notes: '' };
    state.months.push(month);
    renderEntryMonthOptions();
    const envelope = state.envelopes[0];
    openEntryDialog(null, month.id, envelope.id);
    $('entryBs').checked = true;
    updateBsDateLabel();
    return {
      value: $('dueDate').value,
      display: $('dueDate').closest('.date-input-shell').querySelector('.date-input-display').textContent,
      disabled: $('dueDate').disabled,
      readOnly: $('dueDate').readOnly,
      type: $('dueDate').type,
    };
  });
  expect(scenario).toEqual({ value: '2099-10-01', display: '01/10/2099', disabled: false, readOnly: false, type: 'date' });

  await page.evaluate(() => {
    const input = $('dueDate');
    input.showPicker = () => {
      input.value = '2099-10-17';
      input.dispatchEvent(new Event('input', { bubbles: true }));
    };
  });
  await page.locator('[data-date-picker-for="dueDate"]').click();
  await expect(page.locator('#dueDate')).toHaveValue('2099-10-17');
  await expect(page.locator('#dueDate').locator('xpath=..').locator('.date-input-display')).toHaveText('17/10/2099');
});

test('Statement Account selector starts neutral and Preview requires an explicit choice', async ({ page }) => {
  await openAuthenticatedApp(page);
  const testBank = await page.evaluate(() => state.accounts.find((account) => account.name === 'Test Bank'));
  expect(testBank).toBeTruthy();
  const fixture = synthSignedAccountPath('neutral-selector', { accountNumber: testBank.account_number });

  await page.getByRole('button', { name: 'Month', exact: true }).click();
  await page.locator('#accountsDetails > summary').click();
  await page.getByRole('button', { name: 'Import statement' }).click();
  const selector = page.locator('#statementAccountChoice');
  await expect(selector.locator('option').first()).toHaveText('—');
  await expect(selector).toHaveValue('');

  await page.locator('#statementFile').setInputFiles(fixture.filepath);
  await expect(selector).toHaveValue('');
  await page.getByRole('button', { name: 'Preview' }).click();
  await expect(page.locator('#statementImportMessage')).toHaveText('Select an Account.');
  await expect(page.locator('#statementPreview')).toBeHidden();

  await selector.selectOption(String(testBank.id));
  await page.getByRole('button', { name: 'Preview' }).click();
  await expect(page.locator('#statementPreview')).toBeVisible();
  await expect(page.locator('#statementPreviewAccount')).toHaveText('Test Bank');
  await expect(page.locator('#statementPreviewFileAccountNumber')).toHaveText(testBank.account_number);
});

test('Statement Preview and Import require explicit Account authority and ignore a different file identifier', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-chromium', 'One persisted explicit-ownership workflow is sufficient');
  await openAuthenticatedApp(page);
  const accounts = await page.evaluate(() => state.accounts);
  const selected = accounts.find((account) => account.name === 'Test Bank');
  const other = accounts.find((account) => account.id !== selected.id && account.active);
  expect(selected).toBeTruthy();
  expect(other).toBeTruthy();
  const fixture = synthSignedAccountPath('authoritative-selection', {
    accountNumber: other.account_number,
    rows: [{ booking_date: '2026-06-18', amount: -31, description: 'Synthetic explicit ownership row', running_balance: 969 }],
  });
  const requestPayload = {
    filename: path.basename(fixture.filepath),
    file_base64: fs.readFileSync(fixture.filepath).toString('base64'),
  };

  for (const action of ['previewStatementImport', 'importStatementEvidence']) {
    const response = await page.request.post('/api.php', {
      headers: { 'content-type': 'application/json' },
      data: { action, ...requestPayload },
    });
    expect(response.status()).toBe(400);
    expect(await response.json()).toEqual({ error: 'Select an Account.' });
  }

  await page.getByRole('button', { name: 'Month', exact: true }).click();
  await page.locator('#accountsDetails > summary').click();
  await page.getByRole('button', { name: 'Import statement' }).click();
  await page.locator('#statementFile').setInputFiles(fixture.filepath);
  await page.locator('#statementAccountChoice').selectOption(String(selected.id));
  await page.getByRole('button', { name: 'Preview' }).click();
  await expect(page.locator('#statementPreviewAccount')).toHaveText(selected.name);
  await expect(page.locator('#statementPreviewFileAccountNumber')).toHaveText(other.account_number);
  await page.getByRole('button', { name: 'Import evidence' }).click();
  await expect(page.locator('#statementImportMessage')).toContainText('Evidence imported: 1 new row.');
});

test('oversized statement Preview and Import return the normal JSON validation error', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-chromium', 'One server-side validation contract is sufficient');
  await openAuthenticatedApp(page);
  const testBank = await page.evaluate(() => state.accounts.find((account) => account.name === 'Test Bank'));
  expect(testBank).toBeTruthy();
  const rows = Array.from({ length: 1001 }, (_, index) => ({
    booking_date: `2026-06-${String((index % 28) + 1).padStart(2, '0')}`,
    amount: -1,
    description: `Synthetic oversized row ${index + 1}`,
    running_balance: 10000 - index,
  }));
  const fixture = synthSignedAccountPath('oversized-json-error', { accountNumber: testBank.account_number, rows });
  const requestPayload = {
    account_id: testBank.id,
    filename: path.basename(fixture.filepath),
    file_base64: fs.readFileSync(fixture.filepath).toString('base64'),
  };
  const expected = {
    error: 'Statement contains 1001 rows. Maximum per import is 1000. Split the file and import it in smaller parts.',
  };

  for (const action of ['previewStatementImport', 'importStatementEvidence']) {
    const response = await page.request.post('/api.php', {
      headers: { 'content-type': 'application/json' },
      data: { action, ...requestPayload },
    });
    expect(response.status()).toBe(400);
    expect(response.headers()['content-type']).toContain('application/json');
    expect(await response.json()).toEqual(expected);
  }
});

test.describe('authenticated critical flows', () => {
  test.beforeEach(async ({ page }) => {
    await openAuthenticatedApp(page);
    await expect(page.locator('#safeEnvelopeRows')).toContainText('Envelope A');
  });

  test('Today safe to spend uses the current calendar month when another Plan month is selected', async ({ page }) => {
    const result = await page.evaluate(() => {
      const today = new Date();
      const currentMonth = state.months.find((month) => month.year === today.getFullYear() && month.month === today.getMonth() + 1);
      if (!currentMonth) throw new Error('Current calendar month fixture is missing');
      const currentRemainingDays = Finance.remainingDaysInMonth(currentMonth.year, currentMonth.month, today);
      // Inject a synthetic non-current month with a fixed day count so
      // this test is independent of the real current date. Pick the
      // first February after the current year whose day count is not
      // equal to the current month’s `remainingDaysInMonth`.
      let syntheticYear = today.getFullYear() + 1;
      while (Finance.daysInMonth(syntheticYear, 2) === currentRemainingDays) {
        syntheticYear += 1;
      }
      const syntheticDaysInMonth = Finance.daysInMonth(syntheticYear, 2);
      const selectedMonth = {
        id: -1,
        year: syntheticYear,
        month: 2,
        salary: 0,
        daysInMonth: syntheticDaysInMonth,
      };
      state.months.push(selectedMonth);
      state.selectedPlanYear = selectedMonth.year;
      state.selectedPlanMonth = selectedMonth.month;
      const selectedRemainingDays = Finance.remainingDaysInMonth(selectedMonth.year, selectedMonth.month, today);
      const dailyEnvelopes = state.envelopes.filter((envelope) => envelopeRole(envelope) === 'daily');
      if (!dailyEnvelopes.length) throw new Error('Daily Envelope fixture is missing');
      const controlledDailyBalance = 10 * currentRemainingDays * selectedRemainingDays;
      dailyEnvelopes.forEach((envelope, index) => { envelope.balance = index === 0 ? controlledDailyBalance : 0; });

      setScreen('today');
      renderToday();

      return {
        selectedMonthId: selectedPlanMonth()?.id,
        expectedSelectedMonthId: selectedMonth.id,
        expectedCurrentValue: money(Finance.safeToSpend(controlledDailyBalance, currentMonth.year, currentMonth.month, today).amount),
        leakedSelectedValue: money(Finance.safeToSpend(controlledDailyBalance, selectedMonth.year, selectedMonth.month, today).amount),
      };
    });

    expect(result.selectedMonthId).toBe(result.expectedSelectedMonthId);
    expect(result.expectedCurrentValue).not.toBe(result.leakedSelectedValue);
    await expect(page.locator('#availableTotal')).toHaveText(result.expectedCurrentValue);
    await expect(page.locator('#availableTotal')).not.toHaveText(result.leakedSelectedValue);
  });

  test('Add expense remains visible and cannot be collapsed', { tag: '@responsive' }, async ({ page }) => {
    test.setTimeout(60_000);
    const card = page.locator('#availableDetails');
    const button = page.getByRole('button', { name: 'Add expense' });
    const longEnvelopeName = 'Long household essentials and transportation envelope reference alpha';

    await page.evaluate((name) => {
      const envelope = state.envelopes.find((item) => item.name === 'Envelope A');
      envelope.name = name;
      renderToday();
    }, longEnvelopeName);
    const longEnvelopeLabel = page.locator('.safe-envelope-name').filter({ hasText: longEnvelopeName });
    await expect(longEnvelopeLabel).toHaveText(longEnvelopeName);
    const longEnvelopeLayout = await longEnvelopeLabel.evaluate((label) => {
      const row = label.closest('.safe-envelope-row');
      const amount = row.querySelector('.safe-envelope-amount');
      const labelRect = label.getBoundingClientRect();
      const rowRect = row.getBoundingClientRect();
      const amountRect = amount.getBoundingClientRect();
      const cardRect = document.querySelector('#availableDetails').getBoundingClientRect();
      const range = document.createRange();
      range.selectNodeContents(label);
      return {
        viewportWidth: document.documentElement.clientWidth,
        documentWidth: document.documentElement.scrollWidth,
        cardInsideViewport: cardRect.left >= 0 && cardRect.right <= document.documentElement.clientWidth,
        labelFits: label.scrollWidth <= label.clientWidth + 1 && label.scrollHeight <= label.clientHeight + 1,
        labelInsideRow: labelRect.left >= rowRect.left && labelRect.right <= rowRect.right,
        amountFits: amount.scrollWidth <= amount.clientWidth + 1,
        amountInsideRow: amountRect.left >= rowRect.left && amountRect.right <= rowRect.right,
        amountText: amount.textContent.trim(),
        lineCount: [...range.getClientRects()].filter((rect) => rect.width > 0 && rect.height > 0).length,
        whiteSpace: getComputedStyle(label).whiteSpace,
        overflowWrap: getComputedStyle(label).overflowWrap,
      };
    });
    expect(longEnvelopeLayout.documentWidth).toBeLessThanOrEqual(longEnvelopeLayout.viewportWidth);
    expect(longEnvelopeLayout).toMatchObject({
      cardInsideViewport: true,
      labelFits: true,
      labelInsideRow: true,
      amountFits: true,
      amountInsideRow: true,
      whiteSpace: 'normal',
      overflowWrap: 'anywhere',
    });
    expect(longEnvelopeLayout.amountText.length).toBeGreaterThan(0);
    if (longEnvelopeLayout.viewportWidth <= 620) expect(longEnvelopeLayout.lineCount).toBeGreaterThan(1);
    await page.evaluate((name) => {
      const envelope = state.envelopes.find((item) => item.name === name);
      envelope.name = 'Envelope A';
      renderToday();
    }, longEnvelopeName);

    await expect(card).toHaveJSProperty('tagName', 'SECTION');
    const originalDaily = await page.evaluate(() => ({
      balances: state.envelopes.filter((envelope) => envelopeRole(envelope) === 'daily').map((envelope) => ({ id: envelope.id, balance: envelope.balance })),
      transactions: state.transactions.map((transaction) => ({ ...transaction })),
      entryStatuses: state.entries.map((entry) => ({ id: entry.id, status: entry.status })),
      status: monthStatusFor(activeMonth()).state,
    }));
    await expect(card).toHaveClass(new RegExp(`status-${originalDaily.status}`));
    await page.evaluate(() => {
      const month = activeMonth();
      const dailyEnvelopes = state.envelopes.filter((envelope) => envelopeRole(envelope) === 'daily');
      const envelope = dailyEnvelopes[0];
      const roles = new Map(state.envelopes.map((item) => [Number(item.id), envelopeRole(item)]));
      state.entries
        .filter((entry) => Number(entry.month_id) === Number(month.id) && roles.get(Number(entry.envelope_id)) !== 'daily' && entry.status !== 'skipped')
        .forEach((entry) => { entry.status = 'paid'; });
      dailyEnvelopes.forEach((item) => { item.balance = 0; });
      state.transactions.splice(0, state.transactions.length);
      const monthRemainingFraction = Finance.remainingDaysInMonth(month.year, month.month) / Finance.daysInMonth(month.year, month.month);
      const boundaryBalance = (1000 * 0.75 * monthRemainingFraction) + 0.01;
      envelope.balance = boundaryBalance;
      state.transactions.push({ id: 99999, kind: 'expense', month_id: month.id, envelope_id: envelope.id, envelope: envelope.name, amount: 1000 - boundaryBalance, note: 'Pace boundary', spent_date: `${month.year}-${String(month.month).padStart(2, '0')}-01`, is_automatic: 0 });
      renderToday();
    });
    await expect(card).toHaveClass(/status-on-track/);
    await page.evaluate(() => {
      const transaction = state.transactions.at(-1);
      const envelope = state.envelopes.find((item) => item.id === Number(transaction.envelope_id));
      envelope.balance -= 0.02;
      transaction.amount += 0.02;
      renderToday();
    });
    await expect(card).toHaveClass(/status-getting-tight/);
    await page.evaluate(() => {
      const transaction = state.transactions.at(-1);
      const envelope = state.envelopes.find((item) => item.id === Number(transaction.envelope_id));
      envelope.balance = 0;
      transaction.amount = 1000;
      renderToday();
    });
    const zeroBalanceStatus = await page.evaluate(() => monthStatusFor(activeMonth()).state);
    await expect(card).toHaveClass(new RegExp(`status-${zeroBalanceStatus}`));
    const finalDayZero = await page.evaluate(() => {
      const month = activeMonth();
      const finalDay = Finance.daysInMonth(month.year, month.month);
      return Finance.monthStatus(
        month,
        [],
        [{ month_id: month.id, envelope_id: 1, amount: 1000 }],
        [{ id: 1, role: 'daily', balance: 0 }],
        new Date(month.year, month.month - 1, finalDay),
      ).state;
    });
    expect(finalDayZero).toBe('on-track');
    await page.evaluate(() => {
      const transaction = state.transactions.at(-1);
      state.envelopes.find((item) => item.id === Number(transaction.envelope_id)).balance = -1;
      renderToday();
    });
    await expect(card).toHaveClass(/status-needs-attention/);
    await page.evaluate(({ balances, transactions, entryStatuses }) => {
      balances.forEach(({ id, balance }) => { state.envelopes.find((envelope) => envelope.id === id).balance = balance; });
      entryStatuses.forEach(({ id, status }) => { state.entries.find((entry) => entry.id === id).status = status; });
      state.transactions.splice(0, state.transactions.length, ...transactions);
      renderToday();
    }, originalDaily);
    await expect(card).toHaveClass(new RegExp(`status-${originalDaily.status}`));
    await expect(button).toBeVisible();
    await expect(page.locator('#safeEnvelopeRows')).not.toContainText('Reserved test envelope');
    await expect(page.locator('#safeEnvelopeRows')).not.toContainText('Shown on Today');
    await expect(page.locator('#safeEnvelopeRows')).not.toContainText('not included above');
    await expect(page.locator('#dashboardDetails')).toHaveCount(0);
    await expect(page.getByText('Monthly summary', { exact: true })).toHaveCount(0);
    await page.locator('.available-summary').click();
    await expect(button).toBeVisible();

    const recentDetails = page.locator('#recentExpensesDetails');
    await expect(recentDetails).toHaveJSProperty('open', true);
    await recentDetails.locator('summary').click();
    await expect(recentDetails).toHaveJSProperty('open', false);
    await page.reload();
    await expect(page.locator('#appView')).toBeVisible();
    await expect(recentDetails).toHaveJSProperty('open', false);
    await recentDetails.locator('summary').click();
    await expect(recentDetails).toHaveJSProperty('open', true);

    await page.evaluate(() => {
      const month = activeMonth();
      const envelope = state.envelopes.find((item) => item.name === 'Envelope A');
      const spentDate = `${month.year}-${String(month.month).padStart(2, '0')}-01`;
      for (let index = 1; index <= 41; index += 1) {
        state.transactions.push({ id: 100000 + index, kind: 'expense', month_id: month.id, envelope_id: envelope.id, envelope: envelope.name, amount: 1, note: `Synthetic ${index}`, spent_date: spentDate, is_automatic: index === 41 ? 1 : 0 });
      }
      state.transactions.push({ id: 200000, kind: 'expense', month_id: month.id, envelope_id: envelope.id, envelope: envelope.name, amount: 999, note: 'Future scheduled payment', spent_date: toIsoDate(new Date(month.year, month.month, 1)), is_automatic: 1 });
      renderRecentExpenses(month);
    });
    const recentRows = page.locator('#recentExpenses tbody tr');
    await expect(recentRows).toHaveCount(24);
    await expect(page.locator('#recentExpenses')).not.toContainText('Future scheduled payment');
    const automaticExpenseButton = page.getByRole('button', { name: 'View Direct debit expense' });
    const automaticExpenseIcon = automaticExpenseButton.locator('.recent-edit-icon');
    await expect(automaticExpenseIcon).toBeVisible();
    await expect(automaticExpenseIcon).toBeInViewport();
    await expect(automaticExpenseButton).toHaveText('');
    await expect(automaticExpenseButton.locator('xpath=..')).toHaveCSS('text-overflow', 'clip');
    expect(await automaticExpenseButton.evaluate((button) => {
      const cell = button.closest('td');
      const buttonRect = button.getBoundingClientRect();
      const cellRect = cell.getBoundingClientRect();
      return buttonRect.width > 0
        && buttonRect.left >= cellRect.left
        && buttonRect.right <= cellRect.right
        && buttonRect.right <= document.documentElement.clientWidth;
    })).toBeTruthy();
    await automaticExpenseButton.click();
    await expect(page.locator('#expenseDialogTitle')).toHaveText('Direct debit expense');
    await expect(page.locator('#expenseFormMessage')).toBeHidden();
    await expect(page.locator('#saveExpenseBtn')).toBeVisible();
    await expect(page.locator('#deleteExpenseBtn')).toBeVisible();
    await page.locator('#closeExpenseDialogBtn').click();
    await page.getByRole('button', { name: 'Show more' }).click();
    await expect(recentRows).toHaveCount(40);
    await page.getByRole('button', { name: 'Show less' }).click();
    await expect(recentRows).toHaveCount(24);

    const hasHorizontalOverflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
    expect(hasHorizontalOverflow).toBeFalsy();
  });

  test('Recent expense edit icon remains visible without a trailing overflow dot', { tag: '@responsive' }, async ({ page }) => {
    await page.evaluate(() => {
      const month = activeMonth();
      const envelope = state.envelopes.find((item) => item.name === 'Envelope A');
      state.transactions.push({
        id: 210000,
        kind: 'expense',
        month_id: month.id,
        envelope_id: envelope.id,
        envelope: envelope.name,
        amount: 25,
        note: 'Edit icon regression',
        spent_date: `${month.year}-${String(month.month).padStart(2, '0')}-01`,
        is_automatic: 0,
      });
      renderRecentExpenses(month);
    });

    const row = page.locator('#recentExpenses tbody tr').filter({ hasText: 'Edit icon regression' });
    const cell = row.locator('.recent-actions');
    const button = cell.getByRole('button', { name: 'Edit expense' });
    await expect(button.locator('.recent-edit-icon')).toBeVisible();
    await expect(button.locator('.recent-edit-icon')).toBeInViewport();
    expect(await cell.evaluate((actionCell) => {
      const action = actionCell.querySelector('button');
      const cellRect = actionCell.getBoundingClientRect();
      const buttonRect = action.getBoundingClientRect();
      return {
        text: actionCell.textContent.trim(),
        textOverflow: getComputedStyle(actionCell).textOverflow,
        buttonInsideCell: buttonRect.width > 0 && buttonRect.left >= cellRect.left && buttonRect.right <= cellRect.right,
        buttonInsideViewport: buttonRect.right <= document.documentElement.clientWidth,
      };
    })).toEqual({ text: '', textOverflow: 'clip', buttonInsideCell: true, buttonInsideViewport: true });
  });

  test('redundant Expense labels stay silent and important Recent-expense text remains visible', { tag: '@responsive' }, async ({ page }) => {
    const importantNote = 'Ordinary coffee with a deliberately long description that must remain fully visible instead of being displaced or truncated by type metadata';
    await page.evaluate(() => {
      const month = activeMonth();
      const envelope = state.envelopes.find((item) => item.name === 'Envelope A');
      const spentDate = `${month.year}-${String(month.month).padStart(2, '0')}-01`;
      const base = { month_id: month.id, envelope_id: envelope.id, envelope: envelope.name, amount: 25, spent_date: spentDate, is_automatic: 0 };
      state.transactions.push(
        { ...base, id: 220001, kind: 'expense', note: 'Ordinary coffee with a deliberately long description that must remain fully visible instead of being displaced or truncated by type metadata' },
        { ...base, id: 220002, kind: 'refund', note: 'Recent refund must stay out' },
        { ...base, id: 220003, kind: 'transfer', note: 'Recent transfer must stay out' },
        { ...base, id: 220004, kind: 'funding', note: 'Recent funding must stay out' },
        { ...base, id: 220005, kind: 'adjustment', note: 'Recent adjustment must stay out' },
      );
      renderRecentExpenses(month);
    });

    const recent = page.locator('#recentExpenses');
    const expenseRow = recent.locator('tbody tr').filter({ hasText: 'Ordinary coffee' });
    await expect(expenseRow).toHaveCount(1);
    await expect(expenseRow).toContainText('Envelope A');
    const noteCell = expenseRow.locator('.recent-note');
    await expect(noteCell).toHaveText(importantNote);
    expect(await noteCell.evaluate((cell) => {
      const style = getComputedStyle(cell);
      return {
        whiteSpace: style.whiteSpace,
        overflow: style.overflow,
        textOverflow: style.textOverflow,
        fitsWidth: cell.scrollWidth <= cell.clientWidth + 1,
        fitsHeight: cell.scrollHeight <= cell.clientHeight + 1,
      };
    })).toEqual({ whiteSpace: 'normal', overflow: 'visible', textOverflow: 'clip', fitsWidth: true, fitsHeight: true });
    await expect(expenseRow.locator('.recent-amount')).toContainText('25 DKK');
    await expect(expenseRow.getByRole('button', { name: 'Edit expense' })).toBeVisible();
    await expect(expenseRow).not.toContainText('Expense');
    await expect(recent).not.toContainText('Recent refund must stay out');
    await expect(recent).not.toContainText('Recent transfer must stay out');
    await expect(recent).not.toContainText('Recent funding must stay out');
    await expect(recent).not.toContainText('Recent adjustment must stay out');

    const labels = await page.evaluate(() => ({
      accountExpense: accountHistoryDescription({ event_type: 'expense', transaction_envelope: 'Envelope A' }),
      accountRefund: accountHistoryDescription({ event_type: 'refund', transaction_envelope: 'Envelope A' }),
      accountTransfer: accountHistoryDescription({ event_type: 'transfer', source_account: 'Account A', destination_account: 'Account B' }),
      accountInitialObservation: accountHistoryDescription({ history_kind: 'observation', observation_source: 'account_creation' }),
      accountManualObservation: accountHistoryDescription({ history_kind: 'observation', observation_source: 'manual' }),
      generatedExpenseNote: accountHistoryDisplayNote({ event_type: 'expense', note: 'Expense: Envelope A' }),
      generatedRefundNote: accountHistoryDisplayNote({ event_type: 'refund', note: 'Refund: Envelope A' }),
      envelopeExpense: movementDescription({ type: 'expense' }, {}),
      envelopeRefund: movementDescription({ type: 'refund' }, {}),
      envelopeFunding: movementDescription({ type: 'funding' }, {}),
      envelopeAdjustment: movementDescription({ type: 'adjustment' }, {}),
      expenseCandidate: renderReconciliationCandidate({ kind: 'transaction', transaction_id: 1, transaction_kind: 'expense', spent_date: '2026-08-01', amount: 25, envelope: 'Envelope A', note: 'Coffee' }, 1, true),
      refundCandidate: renderReconciliationCandidate({ kind: 'transaction', transaction_id: 2, transaction_kind: 'refund', spent_date: '2026-08-01', amount: 25, envelope: 'Envelope A', note: 'Credit' }, 1, false),
      transferCandidate: renderReconciliationCandidate({ kind: 'account_event', account_event_id: 3, event_date: '2026-08-01', amount: 25, source_account_name: 'Account A', destination_account_name: 'Account B', note: '' }, 1, false),
    }));
    expect(labels.accountExpense).toBe('Envelope A');
    expect(labels.accountExpense).not.toContain('Expense');
    expect(labels.accountRefund).toBe('Refund · Envelope A');
    expect(labels.accountTransfer).toContain('Transfer');
    // Account Balance observations use the current user-facing wording
    // ('Balance observation' / 'Initial Balance observation'); the
    // rejected 'Balance adjustment' wording from the obsolete
    // reconciliation-delta model is gone.
    expect(labels.accountInitialObservation).not.toBe('Balance adjustment');
    expect(labels.accountInitialObservation).toMatch(/observation/i);
    expect(labels.accountManualObservation).not.toBe('Balance adjustment');
    expect(labels.accountManualObservation).toMatch(/observation/i);
    expect(labels.generatedExpenseNote).toBe('Envelope A');
    expect(labels.generatedRefundNote).toBe('Envelope A');
    expect(labels.envelopeExpense).toBe('');
    expect(labels.envelopeRefund).toBe('Refund');
    expect(labels.envelopeFunding).toBe('Account funding');
    expect(labels.envelopeAdjustment).toBe('Balance adjustment');
    expect(labels.expenseCandidate).not.toContain('Expense');
    expect(labels.refundCandidate).toContain('Refund');
    expect(labels.transferCandidate).toContain('Transfer');
  });

  test('recording, editing, and deleting an expense updates envelope balances', async ({ page }) => {
    const before = await page.evaluate(() => ({
      envelopeA: state.envelopes.find((envelope) => envelope.name === 'Envelope A').balance,
      emergency: state.envelopes.find((envelope) => envelope.name === 'Emergency fund').balance,
    }));

    // This test owns the Helper-currency prerequisite it exercises. The
    // baseline intentionally defines Reserved test envelope as CURRENCY.
    await page.evaluate(async () => {
      const envelope = state.envelopes.find((item) => item.name === 'Reserved test envelope');
      if (!envelope || envelope.currency !== 'CURRENCY') throw new Error('Expected the baseline Reserved test envelope to use Currency');
      const data = await apiPost({
        action: 'saveEnvelope',
        year: state.year,
        envelope: { ...envelope, currency: 'HELPER_CURRENCY' },
      });
      applyState(data.state);
    });

    await page.evaluate(() => {
      state.envelopes.find((envelope) => envelope.name === 'Envelope A').usage_count = 12;
      state.envelopes.find((envelope) => envelope.name === 'Reserved test envelope').usage_count = 3;
    });
    await page.getByRole('button', { name: 'Add expense' }).click();
    await expect(page.locator('#expenseEnvelope')).toHaveValue(String(await page.evaluate(() => state.envelopes.find((envelope) => envelope.name === 'Envelope A').id)));
    await expect(page.locator('#expenseAmountLabel')).toContainText('Amount DKK');
    await expect(page.locator('#expenseAmountHint')).toBeHidden();
    const saveAlignment = await page.locator('#expenseForm').evaluate((form) => ({
      actionRight: form.querySelector('.actions').getBoundingClientRect().right,
      saveRight: form.querySelector('button[type="submit"]').getBoundingClientRect().right,
    }));
    expect(Math.abs(saveAlignment.actionRight - saveAlignment.saveRight)).toBeLessThan(1);
    await page.locator('#expenseAmount').fill('125,50');
    await page.locator('#expenseNote').fill('Automated browser test');
    await page.locator('#expenseForm').getByRole('button', { name: 'Save expense' }).click();

    await expect(page.locator('#expenseDialog')).not.toBeVisible();
    await expect(page.locator('#recentExpenses')).toContainText('Automated browser test');
    let balances = await page.evaluate(() => ({
      envelopeA: state.envelopes.find((envelope) => envelope.name === 'Envelope A').balance,
      emergency: state.envelopes.find((envelope) => envelope.name === 'Emergency fund').balance,
    }));
    expect(balances.envelopeA).toBe(before.envelopeA - 125.5);

    let expenseRow = page.locator('#recentExpenses tbody tr').filter({ hasText: 'Automated browser test' });
    await expenseRow.getByRole('button', { name: 'Edit expense' }).click();
    await expect(page.locator('#expenseDialogTitle')).toHaveText('Edit expense');
    await page.locator('#expenseAmount').fill('175,25');
    await page.locator('#expenseEnvelope').selectOption({ label: 'Emergency fund' });
    await page.locator('#expenseNote').fill('Updated browser test');
    await page.locator('#expenseForm').getByRole('button', { name: 'Save changes' }).click();
    await expect(page.locator('#recentExpenses')).toContainText('Updated browser test');
    balances = await page.evaluate(() => ({
      envelopeA: state.envelopes.find((envelope) => envelope.name === 'Envelope A').balance,
      emergency: state.envelopes.find((envelope) => envelope.name === 'Emergency fund').balance,
    }));
    expect(balances.envelopeA).toBe(before.envelopeA);
    expect(balances.emergency).toBe(before.emergency - 175.25);

    await page.getByRole('button', { name: 'Add expense' }).click();
    await page.locator('#expenseEnvelope').selectOption({ label: 'Reserved test envelope' });
    await expect(page.locator('#expenseAmountLabel')).toContainText('Amount EUR');
    await expect(page.locator('#expenseAmountHint')).toBeHidden();
    await expect(page.locator('#expenseAmountLabel')).toHaveClass(/is-helper/);
    await page.locator('#expenseAmount').fill('20,00');
    await page.locator('#expenseNote').fill('EUR browser test');
    await page.locator('#expenseForm').getByRole('button', { name: 'Save expense' }).click();
    const eurRow = page.locator('#recentExpenses tbody tr').filter({ hasText: 'EUR browser test' });
    await expect(eurRow.locator('.recent-amount')).toContainText('20,00 EUR');
    await expect(eurRow.locator('.recent-amount')).toContainText('149 DKK');
    await eurRow.getByRole('button', { name: 'Edit expense' }).click();
    await expect(page.locator('#expenseAmountLabel')).toContainText('Amount EUR');
    await expect(page.locator('#expenseAmount')).toHaveValue('20');
    await page.locator('#closeExpenseDialogBtn').click();
    const eurTransaction = await page.evaluate(() => state.transactions.find((transaction) => transaction.note === 'EUR browser test'));
    expect(eurTransaction).not.toHaveProperty('currency');
    expect(eurTransaction).not.toHaveProperty('helper_amount');
    expect(eurTransaction.amount).toBeCloseTo(149.2, 2);
    await eurRow.getByRole('button', { name: 'Edit expense' }).click();
    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: 'Delete expense' }).click();

    expenseRow = page.locator('#recentExpenses tbody tr').filter({ hasText: 'Updated browser test' });
    await expenseRow.getByRole('button', { name: 'Edit expense' }).click();
    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: 'Delete expense' }).click();
    await expect(page.locator('#recentExpenses')).not.toContainText('Updated browser test');
    balances = await page.evaluate(() => ({
      envelopeA: state.envelopes.find((envelope) => envelope.name === 'Envelope A').balance,
      emergency: state.envelopes.find((envelope) => envelope.name === 'Emergency fund').balance,
    }));
    expect(balances).toEqual(before);
  });

  test('savings goal progress is motivational, private, and configurable', async ({ page }) => {
    const goalScenario = await page.evaluate(async () => {
      const envelope = state.envelopes.find((item) => item.name === 'Emergency fund');
      if (!envelope || Number(envelope.balance) <= 0) throw new Error('Emergency fund baseline balance is required');
      const targetGoal = Number(envelope.balance) * 2.5;
      const data = await apiPost({
        action: 'saveEnvelope',
        year: state.year,
        envelope: { ...envelope, savings_goal: targetGoal, goal_visibility: 'today' },
      });
      applyState(data.state);
      return {
        percent: `${Math.round((Number(envelope.balance) / targetGoal) * 100)}%`,
        goalInput: String(targetGoal),
      };
    });
    const goals = page.locator('.today-goals-group');
    await expect(goals).toBeVisible();
    await expect(goals).toContainText('Emergency fund');
    await expect(goals).toContainText(goalScenario.percent);
    await expect(goals).not.toContainText('saved');
    await expect(goals).not.toContainText('Savings goals');
    await expect(goals).not.toContainText('DKK');
    await expect(goals.locator('summary')).toHaveCount(0);
    await expect(goals.getByRole('progressbar', { name: 'Emergency fund goal progress' })).toHaveAttribute('aria-valuetext', goalScenario.percent);

    await page.reload();
    await expect(page.locator('#appView')).toBeVisible();
    await expect(goals).toBeVisible();

    await page.getByRole('button', { name: 'Month', exact: true }).click();
    const allocations = page.locator('.section-envelope-allocations');
    if (!(await allocations.evaluate((node) => node.open))) await allocations.locator(':scope > summary').click();
    const row = page.locator('tr.funding-row').filter({ has: page.getByRole('button', { name: 'Emergency fund', exact: true }) });
    await expect(row.locator('.month-goal-progress')).toContainText(goalScenario.percent);
    await expect(row.locator('.month-goal-progress')).not.toContainText('saved');
    await expect(row.locator('.month-goal-progress')).not.toContainText('DKK');
    await expect(row.locator('.funding-balance-value')).toContainText('••• DKK');
    await expect(row.locator('.funding-balance-value')).toContainText('••• EUR');

    await row.locator('.envelope-name-button').click();
    await expect(page.locator('#envelopeGoal')).toHaveValue(goalScenario.goalInput);
    await expect(page.locator('#envelopeGoalVisibility')).toHaveValue('today');
    await page.locator('#envelopeGoalVisibility').selectOption('hidden');
    await page.locator('#envelopeForm').getByRole('button', { name: 'Save envelope' }).click();
    await expect(row.locator('.month-goal-progress')).toHaveCount(0);

    await row.locator('.envelope-name-button').click();
    await page.locator('#envelopeGoalVisibility').selectOption('today');
    await page.locator('#envelopeForm').getByRole('button', { name: 'Save envelope' }).click();
    await expect(row.locator('.month-goal-progress')).toContainText(goalScenario.percent);
    await page.getByRole('button', { name: 'Today', exact: true }).click();
    await expect(page.locator('.today-goals-group')).toContainText(goalScenario.percent);
  });

  test('editing a previous month updates salary and shows validation errors', async ({ page }) => {
    const previousMonth = await page.evaluate(() => {
      const current = activeMonth();
      return state.months.find((month) => month.year === current.year && month.month === current.month - 1)?.month;
    });
    expect(previousMonth).toBeTruthy();

    await page.getByRole('button', { name: 'Month', exact: true }).click();
    await page.locator('#monthSelect').selectOption(String(previousMonth));
    const monthDetails = page.locator('#monthDetails');
    if (!(await monthDetails.evaluate((node) => node.open))) await monthDetails.locator(':scope > summary').click();
    await expect(page.locator('#exchangeRate')).toHaveValue('7,46003');

    await page.locator('#exchangeRate').fill('0');
    await expect(page.locator('#exchangeRate').locator('..')).toHaveClass(/month-field-dirty/);
    await page.locator('#monthForm').getByRole('button', { name: 'Save Month' }).click();
    await expect(page.locator('#monthFormMessage')).toBeVisible();
    await expect(monthDetails).toBeVisible();

    await page.locator('#exchangeRate').fill('7,46003');
    await page.locator('#salary').fill('31234.56');
    const monthSaved = waitForApiAction(page, 'saveMonth');
    await page.locator('#monthForm').getByRole('button', { name: 'Save Month' }).click();
    await monthSaved;
    await expect(page.locator('#toast')).toHaveText('Month saved');
    await expect(page.locator('#salary').locator('..')).not.toHaveClass(/month-field-dirty/);
    expect(await page.evaluate((monthNumber) => state.months.find((month) => month.month === monthNumber).salary, previousMonth)).toBe(31234.56);
    await expect(page.locator('#salary')).toHaveValue('31234,56');
  });

  test('recording Envelope Balance observations and moving money preserves the audit trail', async ({ page }) => {
    test.setTimeout(60_000);
    const before = await page.evaluate(() => ({
      source: state.envelopes.find((envelope) => envelope.name === 'Envelope A').balance,
      destination: state.envelopes.find((envelope) => envelope.name === 'Emergency fund').balance,
    }));
    await page.getByRole('button', { name: 'Month', exact: true }).click();
    const allocations = page.locator('.section-envelope-allocations');
    await allocations.locator(':scope > summary').click();
    const envelopeARow = page.locator('tr.funding-row').filter({ hasText: 'Envelope A' });
    await envelopeARow.locator('.envelope-name-button').click();
    await page.getByRole('button', { name: 'Balance tools' }).click();
    await expect(page.locator('#balanceDialogTitle')).toHaveText('Envelope A');
    await expect(page.locator('#balanceCurrent')).toContainText('Current balance');

    await page.locator('#envelopeBalanceObservationDetails > summary').click();
    await page.locator('#envelopeBalanceObservationAmount').fill(String(before.source + 100).replace('.', ','));
    await page.locator('#envelopeBalanceObservationNote').fill('Browser observation');
    const balanceRecorded = waitForApiAction(page, 'saveEnvelopeBalanceObservation');
    await page.getByRole('button', { name: 'Record Balance' }).click();
    await balanceRecorded;
    await expect(page.locator('#toast')).toHaveText('Envelope Balance recorded');
    expect(await page.evaluate(() => state.envelopes.find((envelope) => envelope.name === 'Envelope A').balance)).toBe(before.source + 100);
    await expect(page.locator('#balanceHistory')).toContainText('Browser observation');
    page.once('dialog', (dialog) => dialog.accept());
    const balanceDeleted = waitForApiAction(page, 'deleteEnvelopeBalanceObservation');
    await page.getByRole('button', { name: 'Delete Balance observation' }).first().click();
    await balanceDeleted;
    await expect(page.locator('#toast')).toHaveText('Balance observation deleted');
    expect(await page.evaluate(() => state.envelopes.find((envelope) => envelope.name === 'Envelope A').balance)).toBe(before.source);

    await page.locator('#transferBalanceDetails > summary').click();
    await page.locator('#transferDestination').selectOption({ label: 'Emergency fund · Test Bank' });
    await page.locator('#transferAmount').fill('200');
    await page.locator('#transferNote').fill('Browser transfer');
    page.once('dialog', (dialog) => dialog.accept());
    const moneyMoved = waitForApiAction(page, 'transferEnvelope');
    await page.getByRole('button', { name: 'Move money' }).click();
    await moneyMoved;
    await expect(page.locator('#toast')).toHaveText('Money moved');
    expect(await page.evaluate(() => ({
      source: state.envelopes.find((envelope) => envelope.name === 'Envelope A').balance,
      destination: state.envelopes.find((envelope) => envelope.name === 'Emergency fund').balance,
    }))).toEqual({ source: before.source - 200, destination: before.destination + 200 });
    await expect(page.locator('#balanceHistory')).toContainText('To Emergency fund');
    page.once('dialog', (dialog) => dialog.accept());
    const transferUndone = waitForApiAction(page, 'undoEnvelopeTransfer');
    await page.getByRole('button', { name: 'Undo transfer' }).first().click();
    await transferUndone;
    await expect(page.locator('#toast')).toHaveText('Transfer undone');
    expect(await page.evaluate(() => ({
      source: state.envelopes.find((envelope) => envelope.name === 'Envelope A').balance,
      destination: state.envelopes.find((envelope) => envelope.name === 'Emergency fund').balance,
    }))).toEqual(before);
    await page.locator('#closeBalanceDialogBtn').click();
  });
  test('an unmapped Envelope can be assigned an actual Account and saved', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop-chromium', 'One persisted save is sufficient for this workflow');

    await page.getByRole('button', { name: 'Month', exact: true }).click();
    await page.locator('#fundingDetails > summary').click();
    await page.getByRole('button', { name: 'Vehicle test envelope', exact: true }).click();

    await expect(page.locator('#envelopeFormMessage')).toBeHidden();
    await page.locator('#envelopeDefaultAccount').selectOption({ label: 'Test Bank' });
    await page.locator('#envelopeRole').selectOption('savings');
    await page.locator('#envelopeVisibility').selectOption('hidden');
    await page.locator('#envelopeForm').getByRole('button', { name: 'Save envelope' }).click();
    await expect(page.locator('#envelopeDialog')).not.toBeVisible();
    await expect(page.locator('#toast')).toHaveText('Envelope saved');
    const saved = await page.evaluate(() => state.envelopes.find((envelope) => envelope.name === 'Vehicle test envelope'));
    const testBankId = await page.evaluate(() => state.accounts.find((account) => account.name === 'Test Bank').id);
    expect(saved).toMatchObject({ default_account_id: testBankId, role: 'savings', visibility: 'hidden' });
    expect(saved).not.toHaveProperty('account');
    const row = page.locator('tr.funding-row').filter({ has: page.getByRole('button', { name: 'Vehicle test envelope', exact: true }) });
    await expect(row.locator('.funding-metric').last()).toContainText('••• DKK');
  });


  test('Statement import previews and stores inert evidence only', async ({ page }, testInfo) => {
    // Desktop and mobile share the same isolated Playwright database within one
    // run, so re-running this state-mutating import workflow on mobile is not
    // independent coverage. One persisted statement-import workflow is enough.
    test.skip(testInfo.project.name !== 'desktop-chromium', 'One persisted statement-import workflow is sufficient');
    // Read the seeded synthetic Test Bank Account and select it explicitly;
    // its Account number is also used as informational file evidence.
    const testBank = await page.evaluate(async () => {
      const current = await apiGet(`state&year=${state.year}`);
      return current.accounts.find((account) => account.name === 'Test Bank') || null;
    });
    expect(testBank, 'Seeded Test Bank Account must exist').toBeTruthy();
    // Single-source-of-truth statement rows owned by this test. Assertions that
    // count rows derive from statementRows.length instead of the helper's
    // default payload, so future helper changes cannot silently drift this
    // test's expectations.
    const statementRows = [
      { booking_date: '2026-04-10', amount: -50, description: 'Synthetic Test Bank row main A', running_balance: 950 },
    ];
    const stmtFixture = synthSignedAccountPath('main', {
      accountNumber: testBank.account_number,
      rows: statementRows,
    });
    const statementPath = stmtFixture.filepath;
    const financialBefore = await page.evaluate(async () => {
      const current = await apiGet(`state&year=${state.year}`);
      const testBank = current.accounts.find((account) => account.name === 'Test Bank');
      return {
        transactions: current.transactions,
        movements: current.movements,
        accounts: current.accounts,
        envelopes: current.envelopes,
        entries: current.entries,
        testBankHistory: (await apiGet(`accountHistory&id=${testBank.id}`)).history,
      };
    });
    await page.getByRole('button', { name: 'Month', exact: true }).click();
    const accountsDetails = page.locator('#accountsDetails');
    await accountsDetails.locator(':scope > summary').click();
    await page.getByRole('button', { name: 'Import statement' }).click();
    await page.locator('#statementFile').setInputFiles(statementPath);
    await page.locator('#statementAccountChoice').selectOption(String(testBank.id));
    await page.getByRole('button', { name: 'Preview' }).click();
    await expect(page.locator('#statementPreviewAccount')).toHaveText('Test Bank');
    await expect(page.locator('#statementPreviewRows')).toHaveText(String(statementRows.length));
    await expect(page.locator('#statementPreviewNew')).toHaveText(String(statementRows.length));
    await expect(page.locator('#statementPreviewKnown')).toHaveText('0');
    await expect(page.locator('#statementPreviewProblemCount')).toHaveText('0');
    await page.getByRole('button', { name: 'Import evidence' }).click();
    await expect(page.locator('#statementImportMessage')).toContainText(`Evidence imported: ${statementRows.length} new row.`);

    const financialAfter = await page.evaluate(async () => {
      const current = await apiGet(`state&year=${state.year}`);
      return {
        transactions: current.transactions,
        movements: current.movements,
        accounts: current.accounts,
        envelopes: current.envelopes,
        entries: current.entries,
      };
    });

    // Statement evidence ingestion must remain inert: no Expense, Refund,
    // Account Transfer, Envelope movement, Income entry, or allocated
    // spending entry may be created by the import. The Account
    // Balance observation model does NOT derive Balance from
    // `running_balance - amount` automatically; the Account keeps its
    // pre-import Balance state, stays Unknown with `balance=null`
    // unless a deliberate Record Balance observation was created, and
    // never acquires an Account `opening` event or opening movement.
    const testBankAfter = financialAfter.accounts.find((account) => account.name === 'Test Bank');
    expect(testBankAfter, 'Seeded Test Bank Account must remain present').toBeTruthy();
    expect(testBankAfter.balance_known).toBe(false);
    expect(testBankAfter.balance).toBeNull();
    expect(testBankAfter.display_balance).toBeNull();
    const testBankBefore = financialBefore.accounts.find((account) => account.name === 'Test Bank');
    expect(Number(testBankAfter.movement_count)).toBe(Number(testBankBefore.movement_count));
    expect(testBankAfter.last_movement_date).toBe(testBankBefore.last_movement_date);
    // Explicit non-existence of `ledger_start_date` / historical-baseline
    // metadata on the Account row proves the rejected model is gone.
    expect(testBankAfter).not.toHaveProperty('ledger_start_date');

    // No synthetic row is treated as financial evidence: no Expense, Refund,
    // Account Transfer, Envelope movement, Income entry, or allocated
    // spending entry may be created by the import.
    expect(financialAfter.transactions).toEqual(financialBefore.transactions);
    expect(financialAfter.movements).toEqual(financialBefore.movements);
    expect(financialAfter.entries).toEqual(financialBefore.entries);

    // Account-level account_history for Test Bank remains empty
    // (no movements; no observations created by import).
    const accountHistoryAfter = await page.evaluate(async (accountId) => apiGet(`accountHistory&id=${accountId}`), testBankAfter.id);
    expect(accountHistoryAfter.history).toEqual(financialBefore.testBankHistory);

    await page.locator('#statementFile').setInputFiles(statementPath);
    await page.getByRole('button', { name: 'Preview' }).click();
    await expect(page.locator('#statementPreviewNew')).toHaveText('0');
    await expect(page.locator('#statementPreviewKnown')).toHaveText(String(statementRows.length));
    await expect(page.getByRole('button', { name: 'Import evidence' })).toBeDisabled();
  });

  test('Statement import exposes an explicit Account selector and no Provider selector', async ({ page }, testInfo) => {
    // Desktop-only workflow: this spec mutates persisted statement evidence
    // and shares the same isolated Playwright database between projects.
    test.skip(testInfo.project.name !== 'desktop-chromium', 'One persisted statement-import workflow is sufficient');

    // The parser remains internal while the target Account is selected explicitly.
    const testBank = await page.evaluate(async () => {
      const current = await apiGet(`state&year=${state.year}`);
      return current.accounts.find((account) => account.name === 'Test Bank') || null;
    });
    expect(testBank, 'Seeded Test Bank Account must exist').toBeTruthy();
    const stmtFixture = synthSignedAccountPath('main', { accountNumber: testBank.account_number });
    const statementPath = stmtFixture.filepath;
    await page.getByRole('button', { name: 'Month', exact: true }).click();
    const accountsDetails = page.locator('#accountsDetails');
    await accountsDetails.locator(':scope > summary').click();
    await page.getByRole('button', { name: 'Import statement' }).click();
    await expect(page.locator('#statementProvider')).toHaveCount(0);
    await expect(page.locator('label').filter({ hasText: 'Provider' })).toHaveCount(0);
    await expect(page.locator('label').filter({ hasText: /FIN Account/i })).toHaveCount(0);
    await expect(page.locator('#statementAccountChoiceSection')).toBeVisible();
    await expect(page.locator('#statementAccountChoice')).toHaveValue('');
    await page.locator('#statementFile').setInputFiles(statementPath);
    await page.locator('#statementAccountChoice').selectOption(String(testBank.id));
    await page.getByRole('button', { name: 'Preview' }).click();
    await expect(page.locator('#statementPreviewAccount')).toHaveText('Test Bank');
    expect(await page.evaluate(() => state.statementPreview.import_profile_name)).toBe('Test Bank');
  });

  test('Account Transfer reconciliation automatically links an existing Transfer candidate', async ({ page }, testInfo) => {
    const testSuffix = `${testInfo.project.name.includes('mobile') ? 'mobile' : 'desktop'}-${Date.now()}`;
    const sourceName = `Browser transfer source ${testSuffix}`;
    const destinationName = `Browser transfer destination ${testSuffix}`;
    const sourceNumber = `TEST-BROWSER-TRANSFER-SOURCE-${testSuffix}`;
    const transferDate = '2026-04-10';
    const transferAmount = 50;
    const description = 'Synthetic transfer-candidate statement row';

    // Both Accounts are created without an opening Balance observation.
    // Balance Knowledge is intentionally Unknown throughout this test so the
    // assertions prove the observation model: balance_known=false when no
    // observation exists, regardless of how many movements are linked.
    const accountIds = await page.evaluate(async ({ sourceName, destinationName, sourceNumber, testSuffix }) => {
      const source = await apiPost({
        action: 'saveAccount',
        account: {
          name: sourceName,
          account_number: sourceNumber,
          currency: 'CURRENCY',
        },
        year: state.year,
      });
      const destination = await apiPost({
        action: 'saveAccount',
        account: {
          name: destinationName,
          account_number: `TEST-BROWSER-TRANSFER-DEST-${testSuffix}`,
          currency: 'CURRENCY',
        },
        year: state.year,
      });
      return { sourceId: Number(source.id), destinationId: Number(destination.id) };
    }, { sourceName, destinationName, sourceNumber, testSuffix });
    expect(accountIds.sourceId).toBeGreaterThan(0);
    expect(accountIds.destinationId).toBeGreaterThan(0);
    await page.evaluate(async () => { state.accounts = (await apiGet(`state&year=${state.year}`)).accounts; });

    const initialAccounts = await page.evaluate(async () => (await apiGet(`state&year=${state.year}`)).accounts);
    expect(initialAccounts.find((a) => a.id === accountIds.sourceId).balance_known, 'Source Account has no opening observation').toBe(false);
    expect(initialAccounts.find((a) => a.id === accountIds.destinationId).balance_known, 'Destination Account has no opening observation').toBe(false);

    const transferResult = await page.evaluate(async ({ sourceId, destinationId, amount, date }) => apiPost({
      action: 'createAccountTransfer',
      source_account_id: sourceId,
      destination_account_id: destinationId,
      amount,
      date,
      note: 'Browser fixture Transfer for reconciliation',
    }), {
      sourceId: accountIds.sourceId,
      destinationId: accountIds.destinationId,
      amount: transferAmount,
      date: transferDate,
    });
    expect(transferResult.ok).toBe(true);
    expect(transferResult.account_event_id).toBeGreaterThan(0);

    const stmtFixture = synthSignedAccountPath('transfer-candidate', {
      accountNumber: sourceNumber,
      rows: [{ booking_date: transferDate, amount: -transferAmount, description, running_balance: 950 }],
    });
    await page.getByRole('button', { name: 'Month', exact: true }).click();
    await page.locator('#accountsDetails > summary').click();
    await page.getByRole('button', { name: 'Import statement' }).click();
    await page.locator('#statementFile').setInputFiles(stmtFixture.filepath);
    await page.locator('#statementAccountChoice').selectOption(String(accountIds.sourceId));
    await page.getByRole('button', { name: 'Preview' }).click();
    await expect(page.locator('#statementPreviewAccount')).toHaveText(sourceName);
    expect(await page.evaluate(() => state.statementPreview.import_profile_name)).toBe('Test Bank');
    await page.getByRole('button', { name: 'Import evidence' }).click();
    await expect(page.locator('#statementImportMessage')).toContainText('Evidence imported:');

    const reconciliation = await page.evaluate(async (accountId) => {
      const data = await apiPost({ action: 'loadStatementReconciliation', account_id: accountId });
      return data.state;
    }, accountIds.sourceId);
    expect(reconciliation.unresolved.some((row) => row.description_original === description)).toBe(false);
    const matchedLink = reconciliation.reconciled.find((row) => row.description_original === description);
    expect(matchedLink).toBeTruthy();
    expect(matchedLink.target_kind).toBe('account_event');
    expect(Number(matchedLink.target_id)).toBe(Number(transferResult.account_event_id));
    expect(matchedLink.event_source_account_name).toBe(sourceName);
    expect(matchedLink.event_destination_account_name).toBe(destinationName);

    // Account Transfer reconciliation asserts on the authoritative Transfer
    // financial facts, not on a synthetic Balance derived from movements:
    // the existing Transfer is linked rather than duplicated, the source
    // and destination names match, and the link survives the import flow.
    // Account Balance remains Unknown because no observation was created;
    // neither the import nor the reconciliation promote movements into a
    // Balance observation.
    const balancesAfterMatch = await page.evaluate(async ({ sourceId, destinationId, transferEventId }) => {
      const accounts = (await apiGet(`state&year=${state.year}`)).accounts;
      const sourceHistory = apiGet(`accountHistory&id=${sourceId}`);
      const destinationHistory = apiGet(`accountHistory&id=${destinationId}`);
      const sourceRows = (await sourceHistory).history.filter((row) => row.history_kind === 'movement' && Number(row.event_id) === transferEventId);
      const destinationRows = (await destinationHistory).history.filter((row) => row.history_kind === 'movement' && Number(row.event_id) === transferEventId);
      return {
        sourceBalanceKnown: accounts.find((account) => account.id === sourceId).balance_known,
        destinationBalanceKnown: accounts.find((account) => account.id === destinationId).balance_known,
        sourceMovementAmount: sourceRows.reduce((sum, row) => sum + Number(row.amount), 0),
        destinationMovementAmount: destinationRows.reduce((sum, row) => sum + Number(row.amount), 0),
        sourceObservations: (await sourceHistory).history.filter((row) => row.history_kind === 'observation').length,
        destinationObservations: (await destinationHistory).history.filter((row) => row.history_kind === 'observation').length,
      };
    }, { sourceId: accountIds.sourceId, destinationId: accountIds.destinationId, transferEventId: transferResult.account_event_id });
    expect(balancesAfterMatch.sourceBalanceKnown).toBe(false);
    expect(balancesAfterMatch.destinationBalanceKnown).toBe(false);
    expect(balancesAfterMatch.sourceMovementAmount).toBeCloseTo(-transferAmount, 2);
    expect(balancesAfterMatch.destinationMovementAmount).toBeCloseTo(transferAmount, 2);
    expect(balancesAfterMatch.sourceObservations).toBe(0);
    expect(balancesAfterMatch.destinationObservations).toBe(0);

    const afterRemoval = await page.evaluate(async ({ linkId, accountId }) => {
      await apiPost({ action: 'removeReconciliationLink', link_id: linkId });
      const data = await apiPost({ action: 'loadStatementReconciliation', account_id: accountId });
      return data.state;
    }, { linkId: Number(matchedLink.link_id), accountId: accountIds.sourceId });
    expect(afterRemoval.unresolved.some((row) => row.description_original === description)).toBe(true);

    // After removing the link the underlying Transfer event and movements
    // remain in place; Account Balances still Unknown; reconciliation
    // provenance is the only thing that changes.
    const afterRemovalBalances = await page.evaluate(async ({ sourceId, destinationId }) => {
      const accounts = (await apiGet(`state&year=${state.year}`)).accounts;
      return {
        sourceBalanceKnown: accounts.find((account) => account.id === sourceId).balance_known,
        destinationBalanceKnown: accounts.find((account) => account.id === destinationId).balance_known,
      };
    }, accountIds);
    expect(afterRemovalBalances.sourceBalanceKnown).toBe(false);
    expect(afterRemovalBalances.destinationBalanceKnown).toBe(false);
  });

  test('Month uses exactly five peer collapsible sections with scoped actions and canonical summary', { tag: '@responsive' }, async ({ page }) => {
    test.setTimeout(60_000);
    await expect(page.locator('#homeBtn')).toHaveAttribute('aria-label', 'Month');
    await expect(page.getByRole('button', { name: 'Security' })).toBeHidden();
    await expect(page.getByRole('button', { name: 'Logout' })).toBeVisible();

    await page.getByRole('button', { name: 'Month', exact: true }).click();
    await expect(page.locator('#planView')).toBeVisible();
    await expect(page.locator('#homeBtn')).toHaveAttribute('aria-label', 'Today');
    await expect(page.getByRole('button', { name: 'Security' })).toBeVisible();

    // Own the aggregate values asserted below. A dedicated future Month has
    // no baseline Plans, so its Plan/Funded totals come only from this test.
    const summaryScenario = await page.evaluate(async () => {
      const year = new Date().getFullYear() + 8;
      const month = 2;
      const accountName = 'Summary Helper Account';
      let data = await apiPost({
        action: 'saveAccount',
        year,
        account: {
          name: accountName,
          account_number: 'TEST-SUMMARY-HELPER',
          currency: 'HELPER_CURRENCY',
          initial_balance: 1000,
        },
      });
      const accountId = Number(data.id);
      data = await apiPost({
        action: 'saveEnvelope',
        year,
        envelope: {
          name: 'Summary planned purpose',
          default_account_id: accountId,
          role: 'reserved',
          currency: 'CURRENCY',
          visibility: 'normal',
        },
      });
      const plannedEnvelopeId = Number(data.id);
      data = await apiPost({
        action: 'saveEnvelope',
        year,
        envelope: {
          name: 'Summary funded purpose',
          default_account_id: accountId,
          role: 'reserved',
          currency: 'CURRENCY',
          visibility: 'normal',
        },
      });
      const fundedEnvelopeId = Number(data.id);
      data = await apiPost({
        action: 'saveMonth',
        year,
        month: { year, month, salary: 0, exchange_rate: 7.5 },
      });
      const monthId = Number(data.id);
      await apiPost({
        action: 'createEntry',
        year,
        entry: { month_id: monthId, envelope_id: plannedEnvelopeId, type: 'expense', amount: 4321, status: 'planned' },
      });
      data = await apiPost({
        action: 'createEntry',
        year,
        entry: { month_id: monthId, envelope_id: fundedEnvelopeId, type: 'expense', amount: 2100, status: 'paid' },
      });
      for (const account of data.state.accounts.filter((item) => item.active && Number(item.id) !== accountId)) {
        data = await apiPost({
          action: 'saveAccountBalanceObservation',
          year,
          id: account.id,
          balance: 0,
          effective_date: toIsoDate(new Date()),
          note: 'Summary test baseline',
        });
      }
      applyState(data.state);
      state.selectedPlanYear = year;
      state.selectedPlanMonth = month;
      renderAll();
      // Plan summary's top Balance is the per-Account Envelope balance
      // sum across every current Account (FIN_BACKLOG HIGH #2). The
      // seeded Test Wallet / Test Bank / Test Card / Transfer Account
      // all carry a mix of opening_balance and zero Envelopes; the
      // Summary Helper Account's "Summary funded purpose" Envelope has
      // a 2100 funding movement (created by the createEntry call above
      // with status='paid'), so its contribution is 2100. The total
      // is therefore 3100 + 6200 + 0 + 0 + 2100 = 11.400.
      const totalEnvelopeBalance = state.envelopes.reduce((sum, envelope) => {
        if (Number(envelope.default_account_id || 0) <= 0) return sum;
        return sum + Number(envelope.balance || 0);
      }, 0);
      const summaryAccount = state.accounts.find((account) => Number(account.id) === accountId);
      return {
        accountName,
        plan: money(4321 + 2100),
        funded: money(2100),
        balance: money(totalEnvelopeBalance),
        summaryAccountBalance: Number(summaryAccount.balance),
        summaryAccountDisplayBalance: Number(summaryAccount.display_balance),
        currentExchangeRate: state.currentExchangeRate,
      };
    });

    const peers = page.locator('#planView > details');
    await expect(peers).toHaveCount(5);
    await expect(peers.locator(':scope > summary > strong')).toHaveText(['Month', 'Accounts', 'Envelopes', 'Plan summary', 'Funding']);
    await expect(page.locator('#planToolbar, #monthStatusMount, #accountsDialog, #monthDialog')).toHaveCount(0);
    await expect(page.locator('#planView > button, #planView > .actions, #planView > .actions-row')).toHaveCount(0);
    await expect(page.locator('#planView > details')).toHaveClass([/category/, /category/, /category/, /category/, /category/]);

    const positions = await peers.evaluateAll((nodes) => nodes.map((node) => node.getBoundingClientRect().top));
    expect(positions).toEqual([...positions].sort((left, right) => left - right));

    const monthDetails = page.locator('#monthDetails');
    await monthDetails.locator(':scope > summary').click();
    await expect(monthDetails.getByRole('button', { name: 'Add month' })).toBeVisible();
    await expect(monthDetails.getByRole('button', { name: 'Copy month' })).toBeVisible();
    await expect(monthDetails.getByRole('button', { name: 'Report / export' })).toBeVisible();
    await expect(monthDetails.getByRole('button', { name: 'Save Month' })).toBeVisible();
    await page.locator('#exchangeRate').fill('7.5');
    await expect(page.locator('#exchangeRate').locator('..')).toHaveClass(/month-field-dirty/);
    await page.locator('#exchangeRate').fill('');

    const accountsDetails = page.locator('#accountsDetails');
    await accountsDetails.locator(':scope > summary').click();
    await expect(accountsDetails).toContainText('Test Wallet');
    await expect(accountsDetails.getByRole('button', { name: 'Import statement' })).toBeVisible();
    await expect(accountsDetails.getByRole('button', { name: 'Reconcile' })).toBeVisible();
    await expect(accountsDetails.getByRole('button', { name: 'Add Account' })).toBeVisible();

    const envelopesDetails = page.locator('#envelopesDetails');
    await envelopesDetails.locator(':scope > summary').click();
    await expect(envelopesDetails).toContainText('Envelope A');
    await expect(envelopesDetails.getByRole('button', { name: 'Add Envelope' })).toBeVisible();
    await envelopesDetails.getByRole('button', { name: 'Add Envelope' }).click();
    await page.locator('#envelopeName').fill('envelope a');
    await page.locator('#envelopeForm').getByRole('button', { name: 'Save envelope' }).click();
    await expect(page.locator('#envelopeFormMessage')).toContainText('An envelope with this name already exists.');
    await page.locator('#closeEnvelopeDialogBtn').click();

    const planSummary = page.locator('#planSummaryDetails');
    await planSummary.locator(':scope > summary').click();
    await expect(page.locator('[data-summary="plan"] .label')).toHaveText('Plan');
    await expect(page.locator('[data-summary="funded"] .label')).toHaveText('Funded');
    await expect(page.locator('[data-summary="balance"] .label')).toHaveText('Balance');
    // The top total remains Balance; the per-Account table labels its
    // summed Envelope balances as Envelopes.
    await expect(page.locator('#accountSummaryDetails > summary').locator('span')).toHaveText(['Account', 'Plan', 'Funded', 'Envelopes']);
    await expect(page.locator('[data-summary="plan"] .value')).toHaveText(summaryScenario.plan);
    await expect(page.locator('[data-summary="funded"] .value')).toHaveText(summaryScenario.funded);
    await expect(page.locator('[data-summary="balance"] .value')).toHaveText(summaryScenario.balance);
    await expect(page.locator('#accountSummary .account-summary-row').filter({ hasText: 'Test Wallet' })).toHaveCount(1);
    await expect(page.locator('#accountSummary .account-summary-row').filter({ hasText: 'Test Bank' })).toHaveCount(1);
    await expect(page.locator('#accountSummary .account-summary-row').filter({ hasText: 'Test Card' })).toHaveCount(1);
    await expect(page.locator('#accountSummary')).toContainText('Test Wallet');
    await expect(page.locator('#accountSummary')).toContainText('Test Bank');
    await expect(page.locator('#accountSummary')).toContainText('Test Card');
    // Each seeded Account's row Balance is the sum of its assigned
    // Envelopes' persistent balances, not the real Account Balance
    // (FIN_BACKLOG HIGH #2). CURRENCY-preference rows use the integer
    // 0-decimal formatter (no .00); HELPER_CURRENCY rows use the
    // 2-decimal helperMoney formatter (.00).
    //   Test Wallet:  Envelope A 3100, Skipped plan 0 → 3.100 DKK
    //   Test Bank:    Reserved 0 + Emergency 5000 + Test Card 1200 → 6.200 DKK
    //   Test Card:    no assigned Envelopes → 0 DKK
    //   Transfer Acct:no assigned Envelopes → 0 DKK
    const testWalletRow = page.locator('.account-summary-row').filter({ hasText: 'Test Wallet' });
    await expect(testWalletRow.locator('.account-summary-balance')).toHaveText('3.100 DKK');
    const testBankRow = page.locator('.account-summary-row').filter({ hasText: 'Test Bank' });
    await expect(testBankRow.locator('.account-summary-balance')).toHaveText('6.200 DKK');
    const testCardRow = page.locator('.account-summary-row').filter({ hasText: 'Test Card' });
    await expect(testCardRow.locator('.account-summary-balance')).toHaveText('0 DKK');
    const helperSummary = page.locator('.account-summary-row').filter({ hasText: summaryScenario.accountName });
    await expect(helperSummary.locator('.account-summary-plan')).toContainText('EUR');
    await expect(helperSummary.locator('.account-summary-funded')).toContainText('EUR');
    // The Summary Helper Account has two Envelopes:
    //   Summary planned purpose: 4321 planned, 0 funded (no movement)
    //   Summary funded purpose:  2100 paid (2100 funding movement)
    // The Envelope balance sum is 2100 Currency. Iteration 3 pins the
    // Balance cell to the current exchange rate (the rate of the Month
    // containing effective_today()), not the selected Plan Month's
    // rate, so the displayed value is 2100 / current_rate. The current
    // rate is read from the new state.current_exchange_rate field
    // (iteration 4) so the assertion uses the same source the renderer
    // uses; the seed's stored current Month rate is 7.46.
    const helperCurrentRate = Number(summaryScenario.currentExchangeRate);
    const helperExpectedNumber = new Intl.NumberFormat('da-DK', { maximumFractionDigits: 2, minimumFractionDigits: 2 }).format(2100 / helperCurrentRate);
    await expect(helperSummary.locator('.account-summary-balance')).toHaveText(`${helperExpectedNumber} EUR`);
    await expect(page.locator('#accountSummary')).not.toContainText(/Planned|Transferred/);

    const funding = page.locator('#fundingDetails');
    await funding.locator(':scope > summary').click();
    await expect(funding).toContainText('Envelope A');
    await expect(funding).not.toContainText(/Allocations|Allocated|Legacy account/i);
    const fundedRow = page.locator('tr.funding-row').filter({ hasText: 'Summary funded purpose' });
    await expect(fundedRow.locator('.funding-value-label')).toHaveText(['Plan', 'Balance']);
    await expect(fundedRow.locator('.allocation-status-toggle')).toHaveText('F');

    await page.evaluate(() => {
      ['month', 'accounts', 'envelopes', 'plan-summary', 'funding'].forEach((key) => localStorage.removeItem(`fin-group-open:${key}`));
      state.addingMonth = false;
      renderMonths();
    });
    await page.getByRole('button', { name: 'Today', exact: true }).click();
    await expect(page.locator('#todayView')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Security' })).toBeHidden();
  });

  test('Plan summary Account-row Balance is the Envelope balance sum, not the real Account Balance (FIN_BACKLOG HIGH #2)', async ({ page }, testInfo) => {
    test.setTimeout(60_000);
    const suffix = testInfo.project.name.includes('mobile') ? 'mobile' : 'desktop';
    const accountName = `Plan Summary Account ${suffix}`;
    const accountNumber = `TEST-PLAN-SUMMARY-${suffix}`;
    // Use a dedicated future Year+Month so this test never depends on
    // the real current Month's Plans or Balances.
    const year = new Date().getFullYear() + 9;
    const month = 4;

    // Create the Account with initial_balance = 1000 in HELPER_CURRENCY.
    // Three Envelopes (Daily, Reserved, Savings) are assigned to it and
    // seeded with movements so their per-Envelope balance is exactly
    //   Daily    : 500  (one funding 500)
    //   Reserved : -100 (one expense 100)
    //   Savings  : 200  (one funding 200)
    // The summed Envelope balance is 600, deliberately different from
    // the real Account Balance of 1000 so a regression that reads the
    // real Account Balance would be caught.
    const setup = await page.evaluate(async ({ year, month, accountName, accountNumber, suffix }) => {
      let data = await apiPost({
        action: 'saveAccount',
        year,
        account: {
          name: accountName,
          account_number: accountNumber,
          currency: 'HELPER_CURRENCY',
          initial_balance: 1000,
        },
      });
      const accountId = Number(data.id);
      const envelopeIds = {};
      for (const role of ['daily', 'reserved', 'savings']) {
        const envData = await apiPost({
          action: 'saveEnvelope',
          year,
          envelope: {
            name: `Plan summary ${role} ${suffix} ${Date.now()}-${role}`,
            default_account_id: accountId,
            role,
            currency: 'CURRENCY',
            visibility: role === 'daily' ? 'normal' : 'today',
          },
        });
        envelopeIds[role] = Number(envData.id);
      }
      // Create a Month in the future and add Plan rows for each
      // Envelope so Plan / Funded have known values too.
      const monthData = await apiPost({
        action: 'saveMonth',
        year,
        month: { year, month, salary: 0, exchange_rate: 7.5 },
      });
      const monthId = Number(monthData.id);
      const todayIso = toIsoDate(new Date());
      // Daily: funding 500 → envelope balance 500, Plan 500, Funded 500
      await apiPost({
        action: 'createEntry',
        year,
        entry: { month_id: monthId, envelope_id: envelopeIds.daily, type: 'expense', amount: 500, status: 'paid' },
      });
      // Reserved: only an expense of 100 → envelope balance -100, no Plan row
      await apiPost({
        action: 'createTransaction',
        year,
        transaction: { month_id: monthId, envelope_id: envelopeIds.reserved, kind: 'expense', amount: 100, spent_date: todayIso },
      });
      // Savings: funding 200 → envelope balance 200, Plan 200, Funded 200
      await apiPost({
        action: 'createEntry',
        year,
        entry: { month_id: monthId, envelope_id: envelopeIds.savings, type: 'expense', amount: 200, status: 'paid' },
      });
      // Capture the authoritative Currency / display-currency state so
      // the assertions below can use exactly the rendered text.
      const refreshed = (await (await fetch(`api.php?action=state&year=${year}`)).json());
      const account = refreshed.accounts.find((item) => Number(item.id) === accountId);
      const envelopes = Object.fromEntries(Object.entries(envelopeIds).map(([role, id]) => [role, refreshed.envelopes.find((env) => Number(env.id) === id)]));
      return {
        accountId,
        monthId,
        monthYear: refreshed.year,
        monthExchangeRate: refreshed.months.find((m) => Number(m.id) === monthId).exchange_rate,
        accountDisplayBalance: Number(account.display_balance),
        accountDisplayCurrency: account.display_currency,
        envelopeBalances: Object.fromEntries(Object.entries(envelopes).map(([role, env]) => [role, Number(env.balance)])),
        refreshed,
      };
    }, { year, month, accountName, accountNumber, suffix });

    expect(setup.envelopeBalances.daily, 'Daily envelope balance must be 500').toBe(500);
    expect(setup.envelopeBalances.reserved, 'Reserved envelope balance must be -100').toBe(-100);
    expect(setup.envelopeBalances.savings, 'Savings envelope balance must be 200').toBe(200);
    expect(setup.accountDisplayBalance, 'Accounts view display_balance must be 1000').toBe(1000);
    expect(setup.accountDisplayCurrency, 'Account display currency must be EUR').toBe('EUR');
    const sum = setup.envelopeBalances.daily + setup.envelopeBalances.reserved + setup.envelopeBalances.savings;
    expect(sum, 'summed Envelope Balance must be 600, different from the real Account Balance of 1000').toBe(600);
    expect(sum, 'summed Envelope Balance must differ from the real Account Balance').not.toBe(setup.accountDisplayBalance);

    // Switch to the Month and open Plan summary.
    await page.getByRole('button', { name: 'Month', exact: true }).click();
    await page.evaluate(({ refreshed, year, month }) => {
      applyState(refreshed);
      state.selectedPlanYear = year;
      state.selectedPlanMonth = month;
      renderAll();
    }, { refreshed: setup.refreshed, year, month });
    const planSummary = page.locator('#planSummaryDetails');
    if (!(await planSummary.evaluate((node) => node.open))) await planSummary.locator(':scope > summary').click();

    // 1. The Account row's Balance in Plan summary is the summed
    //    Envelope balance (600), NOT the real Account Balance (1000).
    const accountRow = page.locator('.account-summary-row').filter({ hasText: accountName });
    await expect(accountRow).toHaveCount(1);
    // The row is presented in the Account's HELPER_CURRENCY preference
    // (EUR). Iteration 4 reads the current rate from the new
    // state.current_exchange_rate field, which is the stored rate of
    // the Month containing effective_today() (the seed sets it to
    // 7,46). The assertion uses the same field the renderer uses so
    // it cannot mask a wrong-field bug.
    const currentRate = Number(setup.refreshed.current_exchange_rate);
    // Format the expected Balance using the same Intl.NumberFormat rules
    // as app.js helperMoney (da-DK locale, 2 decimal places, no grouping).
    const expectedBalanceNumber = new Intl.NumberFormat('da-DK', { maximumFractionDigits: 2, minimumFractionDigits: 2 }).format(sum / currentRate);
    const expectedBalanceText = `${expectedBalanceNumber} ${setup.refreshed.helper_currency}`;
    await expect(accountRow.locator('.account-summary-balance')).toHaveText(expectedBalanceText);
    // Plan / Funded still use the selected Plan Month's rate (7.5),
    // unchanged from iteration 1: Daily 500 + Savings 200 = 700 Currency;
    // the Reserved envelope has no Plan row, only a transaction.
    // 700 / 7.5 = 93,33 EUR.
    await expect(accountRow.locator('.account-summary-plan')).toHaveText('93,33 EUR');
    await expect(accountRow.locator('.account-summary-funded')).toHaveText('93,33 EUR');

    // 1b. Switching the selected Plan Month to a Month with a different
    //     exchange rate leaves the Balance cell unchanged (iteration 3
    //     decision: the Helper currency is only a helper, always
    //     presented at the current rate).
    const altRate = 9.25;
    const altMonth = await page.evaluate(async ({ year, altRate }) => {
      const data = await apiPost({
        action: 'saveMonth',
        year,
        month: { year, month: 5, salary: 0, exchange_rate: altRate },
      });
      return data.state.months.find((m) => Number(m.id) === Number(data.id));
    }, { year: setup.monthYear + 10, altRate });
    const altYear = altMonth.year;
    const altMonthNum = altMonth.month;
    const altRefreshed = await page.evaluate(async ({ altYear }) => {
      const data = await (await fetch(`api.php?action=state&year=${altYear}`)).json();
      return data;
    }, { altYear });
    await page.evaluate(({ altRefreshed, altYear, altMonthNum }) => {
      applyState(altRefreshed);
      state.selectedPlanYear = altYear;
      state.selectedPlanMonth = altMonthNum;
      renderAll();
    }, { altRefreshed, altYear, altMonthNum });
    await expect(accountRow.locator('.account-summary-balance')).toHaveText(expectedBalanceText);
    // Plan / Funded cells use the selected Plan Month's rate AND its
    // Plan rows. The alt Month has no Plan rows for this Account, so
    // both cells show 0,00 EUR. The Balance cell is the one that must
    // stay unchanged because it always uses the current rate.
    await expect(accountRow.locator('.account-summary-plan')).toHaveText('0,00 EUR');
    await expect(accountRow.locator('.account-summary-funded')).toHaveText('0,00 EUR');

    // Switch back to the original Month for the rest of the test.
    await page.evaluate(({ setup, year, month }) => {
      applyState(setup.refreshed);
      state.selectedPlanYear = year;
      state.selectedPlanMonth = month;
      renderAll();
    }, { setup, year, month });

    // 2. The Plan summary top totals equal the sums of the Account
    //    rows. The top cards aggregate in Currency (DKK) and use the
    //    integer 0-decimal formatter, so:
    //      Plan:  500 + 200 = 700 DKK
    //      Funded: 500 + 200 = 700 DKK
    //      Balance: 3100 (Test Wallet) + 6200 (Test Bank) + 0
    //        (Test Card) + 0 (Transfer Account) + 600 (new) = 9900 DKK.
    await expect(page.locator('[data-summary="plan"] .value')).toHaveText('700 DKK');
    await expect(page.locator('[data-summary="funded"] .value')).toHaveText('700 DKK');
    await expect(page.locator('[data-summary="balance"] .value')).toHaveText('9.900 DKK');

    // 3. The Accounts view (the per-Account dialog) still shows the
    //    real Account Balance of 1000, NOT the summed Envelope Balance
    //    of 600 / 80 EUR. The Account is a HELPER_CURRENCY preference,
    //    so the dialog renders 1.000 EUR.
    const accountsDetails = page.locator('#accountsDetails');
    await accountsDetails.locator(':scope > summary').click();
    // Scope the selector to the Accounts list so envelope rows in the
    // Envelopes list (which also use the managed-row class and display
    // the default_account_name) are excluded.
    const accountListRow = page.locator('#accountsList .managed-account-row').filter({ hasText: accountName });
    await expect(accountListRow).toContainText('1.000 EUR');
    await accountListRow.click();
    await expect(page.locator('#accountCurrentPosition')).toContainText('1.000 EUR');
    await expect(page.locator('#accountCurrentPosition')).not.toContainText('600');
    await expect(page.locator('#accountCurrentPosition')).not.toContainText('80,00');
    await page.locator('#closeAccountDialogBtn').click();
  });

  test('Plan summary Balance for a HELPER_CURRENCY Account with no Balance observation still converts (FIN_BACKLOG HIGH #2, iteration 4)', async ({ page }, testInfo) => {
    test.setTimeout(60_000);
    const suffix = testInfo.project.name.includes('mobile') ? 'mobile' : 'desktop';
    const accountName = `Plan Summary Unobserved Account ${suffix}`;
    const accountNumber = `TEST-PLAN-SUMMARY-UNOBSERVED-${suffix}`;
    // Use a dedicated future Year+Month so this test never depends on
    // the real current Month's Plans or Balances.
    const year = new Date().getFullYear() + 10;
    const month = 3;

    // Create a HELPER_CURRENCY Account with NO initial_balance (no
    // Balance observation). Also assign a single Envelope with a
    // known balance so the Plan summary has a row to render.
    const setup = await page.evaluate(async ({ year, month, accountName, accountNumber, suffix }) => {
      let data = await apiPost({
        action: 'saveAccount',
        year,
        account: {
          name: accountName,
          account_number: accountNumber,
          currency: 'HELPER_CURRENCY',
          // No initial_balance → balance_known=false, display_balance=null.
        },
      });
      const accountId = Number(data.id);
      const envData = await apiPost({
        action: 'saveEnvelope',
        year,
        envelope: {
          name: `Unobserved account envelope ${suffix} ${Date.now()}`,
          default_account_id: accountId,
          role: 'daily',
          currency: 'CURRENCY',
          visibility: 'normal',
        },
      });
      const envelopeId = Number(envData.id);
      const monthData = await apiPost({
        action: 'saveMonth',
        year,
        month: { year, month, salary: 0, exchange_rate: 8.0 },
      });
      const monthId = Number(monthData.id);
      // Fund 500 Currency so the Envelope has a known balance.
      await apiPost({
        action: 'createEntry',
        year,
        entry: { month_id: monthId, envelope_id: envelopeId, type: 'expense', amount: 500, status: 'paid' },
      });
      const refreshed = (await (await fetch(`api.php?action=state&year=${year}`)).json());
      const account = refreshed.accounts.find((item) => Number(item.id) === accountId);
      return {
        accountId,
        monthId,
        monthYear: refreshed.year,
        currentExchangeRate: refreshed.current_exchange_rate,
        helperCurrency: refreshed.helper_currency,
        accountBalanceKnown: account.balance_known,
        accountBalance: account.balance,
        accountDisplayBalance: account.display_balance,
        refreshed,
      };
    }, { year, month, accountName, accountNumber, suffix });

    // Sanity: the Account really has no Balance observation. This is
    // the case the iteration 3 derivation could not handle.
    expect(setup.accountBalanceKnown, 'Account must have no Balance observation').toBe(false);
    expect(setup.accountBalance, 'Account balance must be null when unknown').toBeNull();
    expect(setup.accountDisplayBalance, 'Account display_balance must be null when unknown').toBeNull();
    // The current_exchange_rate field must be present and positive
    // (the seeded current Month has exchange_rate 7.46).
    expect(Number(setup.currentExchangeRate), 'current_exchange_rate must be a positive number').toBeGreaterThan(0);

    // Switch to the Month and open Plan summary.
    await page.getByRole('button', { name: 'Month', exact: true }).click();
    await page.evaluate(({ refreshed, year, month }) => {
      applyState(refreshed);
      state.selectedPlanYear = year;
      state.selectedPlanMonth = month;
      renderAll();
    }, { refreshed: setup.refreshed, year, month });
    const planSummary = page.locator('#planSummaryDetails');
    if (!(await planSummary.evaluate((node) => node.open))) await planSummary.locator(':scope > summary').click();

    // The Balance cell must show a converted value (not "Conversion
    // unavailable"), because the Balance depends on the new
    // state.current_exchange_rate field, not on any Account's real
    // Balance or on balance_known.
    const accountRow = page.locator('.account-summary-row').filter({ hasText: accountName });
    await expect(accountRow).toHaveCount(1);
    const expectedBalanceNumber = new Intl.NumberFormat('da-DK', { maximumFractionDigits: 2, minimumFractionDigits: 2 }).format(500 / Number(setup.currentExchangeRate));
    const expectedBalanceText = `${expectedBalanceNumber} ${setup.helperCurrency}`;
    await expect(accountRow.locator('.account-summary-balance')).toHaveText(expectedBalanceText);
    await expect(accountRow.locator('.account-summary-balance')).not.toContainText('Conversion unavailable');
  });

  test('Accounts are permanent, auditable, archivable, and automatically represented in Plan summary', async ({ page }, testInfo) => {
    test.setTimeout(45_000);
    const suffix = testInfo.project.name.includes('mobile') ? 'mobile' : 'desktop';
    const accountName = `Browser Account ${suffix}`;
    const renamedAccount = `${accountName} edited`;
    const accountNumber = `TEST-BROWSER-ACCOUNT-${suffix}`;
    await page.getByRole('button', { name: 'Month', exact: true }).click();
    const accountsDetails = page.locator('#accountsDetails');
    await accountsDetails.locator(':scope > summary').click();
    await expect(page.locator('#accountsList')).toContainText('Test Wallet');

    await accountsDetails.getByRole('button', { name: 'Add Account' }).click();
    await page.locator('#accountName').fill(accountName);
    await page.locator('#accountNumber').fill(accountNumber);
    await page.locator('#accountCurrency').selectOption('HELPER_CURRENCY');
    await page.locator('#accountInitialAmount').fill('1200');
    await page.locator('#accountForm').getByRole('button', { name: 'Add account' }).click();
    const accountRow = page.locator('.managed-account-row').filter({ hasText: accountName });
    await expect(accountRow).toContainText('1.200 EUR');

    const planSummary = page.locator('#planSummaryDetails');
    if (!(await planSummary.evaluate((node) => node.open))) await planSummary.locator(':scope > summary').click();
    await expect(page.locator('#accountSummary')).toContainText(accountName);

    await accountRow.click();
    await expect(page.locator('#accountCurrentPosition')).toContainText('1.200 EUR');
    await expect(page.locator('#accountHistory')).toContainText('Initial Balance');
    await page.locator('#accountName').fill(renamedAccount);
    await page.locator('#accountForm').getByRole('button', { name: 'Save account' }).click();
    await expect(page.locator('#accountsList')).toContainText(renamedAccount);
    await expect(page.locator('#accountSummary')).toContainText(renamedAccount);

    await page.locator('.managed-account-row').filter({ hasText: renamedAccount }).click();
    await page.locator('#accountReconcileDetails > summary').click();
    await page.locator('#accountReconcileAmount').fill('900');
    // Use a normal valid Record Balance date with no synthetic baseline
    // concept. The Account dialog initializes the Date input to today
    // (effective_today); the test simply confirms the form is date-only
    // and records an authoritative absolute observation.
    const browserAccountDate = await page.evaluate(() => $('accountReconcileDate') ? $('accountReconcileDate').value : null);
    expect(browserAccountDate, 'Record Balance input must expose a date value').toBeTruthy();
    expect(browserAccountDate, 'Record Balance defaults to a valid YYYY-MM-DD date').toMatch(/^\d{4}-\d{2}-\d{2}$/);
    // Future Balance date must be rejected at the input level so the user
    // can never silently schedule a future observation.
    const futureMax = await page.evaluate(() => $('accountReconcileDate') ? $('accountReconcileDate').max : null);
    expect(futureMax, 'Date input must clamp to today via the max attribute').toBeTruthy();
    expect(futureMax, 'Date input max attribute equals effective_today').toBe(browserAccountDate);
    await page.locator('#accountReconcileNote').fill('Browser account check');
    await page.locator('#accountReconcileForm').getByRole('button', { name: 'Record Balance' }).click();
    await expect(page.locator('#accountCurrentPosition')).toContainText('900 EUR');
    await expect(page.locator('#accountHistory')).toContainText('Browser account check');
    // The newly recorded observation history entry is labelled as an
    // authoritative absolute Balance observation rather than a legacy
    // "Balance adjustment" reconciliation-delta event.
    await expect(page.locator('#accountHistory')).toContainText(/Balance observation/i);
    await expect(page.locator('#accountHistory')).not.toContainText('Balance adjustment');

    // Recording a different Balance for the SAME Account/date must
    // update the same observation (not create another). One observation
    // row is recorded with the latest absolute value, and the
    // Account history shows a single Balance observation entry per
    // recorded date.
    const observationsCountBeforeUpsert = await page.evaluate(async (accountId) => {
      const history = await apiGet(`accountHistory&id=${accountId}`);
      return history.history.filter((row) => row.history_kind === 'observation').length;
    }, Number((await page.evaluate((name) => state.accounts.find((item) => item.name === name).id, renamedAccount))));
    // The Record Balance form keeps the same Account/date when re-used;
    // its details element stays open across submissions.
    await page.locator('#accountReconcileAmount').fill('1100');
    await page.locator('#accountReconcileNote').fill('Browser account check revised');
    await page.locator('#accountReconcileForm').getByRole('button', { name: 'Record Balance' }).click();
    await expect(page.locator('#accountCurrentPosition')).toContainText('1.100 EUR');
    const observationsCountAfterUpsert = await page.evaluate(async (accountId) => {
      const history = await apiGet(`accountHistory&id=${accountId}`);
      return history.history.filter((row) => row.history_kind === 'observation').length;
    }, Number(await page.evaluate((name) => state.accounts.find((item) => item.name === name).id, renamedAccount)));
    expect(observationsCountAfterUpsert).toBe(observationsCountBeforeUpsert);

    // Account exposes Balance through the observation model; legacy
    // tracking metadata is gone. The Account is in HELPER_CURRENCY
    // (EUR); the recorded Balance is shown in the Account's display
    // currency as 1.100 EUR through the observation path.
    const balanceKnownAfterRecord = await page.evaluate((name) => {
      const account = state.accounts.find((item) => item.name === name);
      return { hasProperty: Object.hasOwn(account, 'ledger_start_date'), balance_known: account.balance_known, display_balance: account.display_balance, display_currency: account.display_currency };
    }, renamedAccount);
    expect(balanceKnownAfterRecord.hasProperty, 'Accounts must not expose ledger_start_date').toBe(false);
    expect(balanceKnownAfterRecord.balance_known).toBe(true);
    expect(balanceKnownAfterRecord.display_balance).toBeCloseTo(1100, 2);
    expect(balanceKnownAfterRecord.display_currency).toBe('EUR');

    // Deleting an observation removes only that observation and falls
    // the Account Balance back to Unknown (no prior observation has
    // survived on a later date). Delete from the Account history table
    // (rendered by app.js) and confirm state side-effects.
    const renamedAccountId = Number(await page.evaluate((name) => state.accounts.find((item) => item.name === name).id, renamedAccount));
    const remainingObservationsBefore = await page.evaluate(async (accountId) => {
      const history = await apiGet(`accountHistory&id=${accountId}`);
      return history.history.filter((row) => row.history_kind === 'observation').length;
    }, renamedAccountId);
    expect(remainingObservationsBefore).toBeGreaterThan(0);
    page.once('dialog', (dialog) => dialog.accept());
    await page.locator('#accountHistory').getByRole('button', { name: 'Delete' }).first().click();
    const remainingObservationsAfter = await page.evaluate(async (accountId) => {
      const history = await apiGet(`accountHistory&id=${accountId}`);
      return history.history.filter((row) => row.history_kind === 'observation').length;
    }, renamedAccountId);
    expect(remainingObservationsAfter).toBe(remainingObservationsBefore - 1);
    const balanceAfterDelete = await page.evaluate((name) => {
      const account = state.accounts.find((item) => item.name === name);
      return { balance_known: account.balance_known, balance: account.balance };
    }, renamedAccount);
    // The Account only ever carried one observation dated today; once
    // deleted there is no prior observation, so Balance falls back to
    // Unknown (null, never synthetic zero).
    expect(balanceAfterDelete.balance_known).toBe(false);
    expect(balanceAfterDelete.balance).toBeNull();
    // The dialog's underlying history table displays "No Account history
    // yet. Balance is unknown." once the only observation is gone.
    await expect(page.locator('#accountHistory')).toContainText('Balance is unknown');

    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: 'Archive account' }).click();
    await expect(page.locator('.archived-accounts')).toContainText(renamedAccount);
    await expect(page.locator('#accountSummary')).not.toContainText(renamedAccount);

    await page.locator('.archived-accounts > summary').click();
    await page.locator('.archived-accounts .managed-account-row').filter({ hasText: renamedAccount }).click();
    await page.getByRole('button', { name: 'Restore account' }).click();
    await expect(page.locator('#accountSummary')).toContainText(renamedAccount);
    // Restore preserves the Account and its audit history; because the
    // only observation was deleted earlier in this test, the restored
    // Account Balance remains Unknown. No ledger-start concept is
    // surfaced; the Account carries the same observations it had at
    // archive time.
    const balanceAfterRestore = await page.evaluate((name) => {
      const account = state.accounts.find((item) => item.name === name);
      return { balance_known: account.balance_known, balance: account.balance, hasProperty: Object.hasOwn(account, 'ledger_start_date') };
    }, renamedAccount);
    expect(balanceAfterRestore.hasProperty, 'Accounts must not expose ledger_start_date').toBe(false);
    expect(balanceAfterRestore.balance_known).toBe(false);
    expect(balanceAfterRestore.balance).toBeNull();
  });

  test('Account Record Balance success and failure show a visible toast above the dialog', { tag: '@responsive' }, async ({ page }, testInfo) => {
    test.setTimeout(45_000);
    const suffix = testInfo.project.name.includes('mobile') ? 'mobile' : 'desktop';
    const accountName = `Browser balance toast ${suffix}`;
    const accountNumber = `TEST-BROWSER-BALANCE-TOAST-${suffix}`;
    await page.getByRole('button', { name: 'Month', exact: true }).click();
    const accountsDetails = page.locator('#accountsDetails');
    await accountsDetails.locator(':scope > summary').click();
    await accountsDetails.getByRole('button', { name: 'Add Account' }).click();
    await page.locator('#accountName').fill(accountName);
    await page.locator('#accountNumber').fill(accountNumber);
    await page.locator('#accountForm').getByRole('button', { name: 'Add account' }).click();
    await expect(page.locator('#accountsList')).toContainText(accountName);

    // Success path: opening the dialog and recording a Balance must show
    // a visible "Account Balance recorded" toast. The toast used to be
    // hidden behind the modal dialog's top layer because it lived at
    // body level; the fix reparents it into the open dialog so the user
    // can always tell whether the save succeeded.
    await page.locator('.managed-account-row').filter({ hasText: accountName }).click();
    await page.locator('#accountReconcileDetails > summary').click();
    await page.locator('#accountReconcileAmount').fill('500');
    const balanceRecorded = waitForApiAction(page, 'saveAccountBalanceObservation');
    await page.locator('#accountReconcileForm').getByRole('button', { name: 'Record Balance' }).click();
    await balanceRecorded;
    const successToast = await page.evaluate(() => {
      const toast = document.getElementById('toast');
      const rect = toast.getBoundingClientRect();
      const cx = rect.left + rect.width / 2;
      const cy = rect.top + rect.height / 2;
      const hit = document.elementFromPoint(cx, cy);
      return {
        text: toast.textContent,
        visible: !toast.classList.contains('hidden'),
        parentId: toast.parentNode && toast.parentNode.id,
        hitElement: hit ? `${hit.tagName}#${hit.id || ''}` : null,
        insideDialog: hit ? hit.closest('dialog') !== null : false,
      };
    });
    expect(successToast.visible, 'success toast must not be hidden').toBe(true);
    expect(successToast.text).toBe('Account Balance recorded');
    expect(successToast.parentId).toBe('accountDialog');
    expect(successToast.hitElement, 'success toast must be the topmost element at its position').toBe('DIV#toast');
    expect(successToast.insideDialog, 'success toast must render above the modal dialog').toBe(true);

    // Failure path: a forced frontend validation error must also render
    // visibly. The form rejects a future Balance date with a toast; the
    // fix ensures that toast is visible while the dialog is open.
    await page.evaluate(() => {
      const input = document.getElementById('accountReconcileDate');
      input.removeAttribute('max');
      input.value = '2099-01-01';
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
    });
    await page.locator('#accountReconcileAmount').fill('600');
    await page.locator('#accountReconcileForm').getByRole('button', { name: 'Record Balance' }).click();
    await expect(page.locator('#toast')).toHaveText('Balance date cannot be in the future');
    const failureToast = await page.evaluate(() => {
      const toast = document.getElementById('toast');
      const rect = toast.getBoundingClientRect();
      const cx = rect.left + rect.width / 2;
      const cy = rect.top + rect.height / 2;
      const hit = document.elementFromPoint(cx, cy);
      return {
        text: toast.textContent,
        visible: !toast.classList.contains('hidden'),
        parentId: toast.parentNode && toast.parentNode.id,
        hitElement: hit ? `${hit.tagName}#${hit.id || ''}` : null,
        insideDialog: hit ? hit.closest('dialog') !== null : false,
      };
    });
    expect(failureToast.visible, 'failure toast must not be hidden').toBe(true);
    expect(failureToast.parentId).toBe('accountDialog');
    expect(failureToast.hitElement, 'failure toast must be the topmost element at its position').toBe('DIV#toast');
    expect(failureToast.insideDialog, 'failure toast must render above the modal dialog').toBe(true);
    // The future-date validation short-circuits before any API call.
    const balanceAfterFailure = await page.evaluate((name) => state.accounts.find((item) => item.name === name).display_balance, accountName);
    expect(balanceAfterFailure).toBeCloseTo(500, 2);
  });

  test('Account Record Balance surfaces a real API failure with the API reason, re-enables the button, and writes no Balance', { tag: '@responsive' }, async ({ page }, testInfo) => {
    test.setTimeout(45_000);
    const suffix = testInfo.project.name.includes('mobile') ? 'mobile' : 'desktop';
    const accountName = `Browser balance api fail ${suffix}`;
    const accountNumber = `TEST-BROWSER-BALANCE-FAIL-${suffix}`;
    await page.getByRole('button', { name: 'Month', exact: true }).click();
    const accountsDetails = page.locator('#accountsDetails');
    await accountsDetails.locator(':scope > summary').click();
    await accountsDetails.getByRole('button', { name: 'Add Account' }).click();
    await page.locator('#accountName').fill(accountName);
    await page.locator('#accountNumber').fill(accountNumber);
    await page.locator('#accountForm').getByRole('button', { name: 'Add account' }).click();
    await expect(page.locator('#accountsList')).toContainText(accountName);

    // Intercept the next saveAccountBalanceObservation request and return
    // a real 400 with a recognisable reason.
    await page.route('**/api.php', async (route) => {
      const request = route.request();
      if (request.method() !== 'POST') return route.continue();
      let payload = {};
      try { payload = request.postDataJSON() || {}; } catch { return route.continue(); }
      if (payload.action !== 'saveAccountBalanceObservation') return route.continue();
      return route.fulfill({
        status: 400,
        contentType: 'application/json',
        body: JSON.stringify({ error: 'Browser test: account balance rejected' }),
      });
    });

    const accountId = await page.evaluate((name) => state.accounts.find((item) => item.name === name).id, accountName);
    expect(accountId, 'account must be created before the failure scenario').toBeGreaterThan(0);

    await page.locator('.managed-account-row').filter({ hasText: accountName }).click();
    await page.locator('#accountReconcileDetails > summary').click();
    await page.locator('#accountReconcileAmount').fill('1234');
    await page.locator('#accountReconcileNote').fill('Browser failure test');
    await page.locator('#accountReconcileForm').getByRole('button', { name: 'Record Balance' }).click();
    // The visible error message must surface the API's reason, not the
    // generic fallback.
    await expect(page.locator('#toast')).toHaveText('Browser test: account balance rejected');
    // The button must be re-enabled and not in the busy state.
    const buttonState = await page.evaluate(() => {
      const button = document.querySelector('#accountReconcileForm button[type="submit"]');
      return { disabled: button.disabled, ariaBusy: button.getAttribute('aria-busy'), isLoading: button.classList.contains('is-loading') };
    });
    expect(buttonState.disabled, 'submit button must be re-enabled after API failure').toBe(false);
    expect(buttonState.ariaBusy, 'submit button must not be in busy state after API failure').not.toBe('true');
    expect(buttonState.isLoading, 'submit button must not carry the loading class after API failure').toBe(false);
    // The Account must have no observations (no Balance was written).
    const observationsCount = await page.evaluate(async (id) => {
      const history = await apiGet(`accountHistory&id=${id}`);
      return history.history.filter((row) => row.history_kind === 'observation').length;
    }, accountId);
    expect(observationsCount, 'no Balance observation must be written on API failure').toBe(0);
    // The dialog must stay open so the user can correct and retry.
    expect(await page.locator('#accountDialog').evaluate((d) => d.open), 'dialog must remain open on API failure').toBe(true);
  });

  test('Envelope Record Balance surfaces a real API failure with the API reason, re-enables the button, and writes no Balance', { tag: '@responsive' }, async ({ page }, testInfo) => {
    test.setTimeout(45_000);
    const suffix = testInfo.project.name.includes('mobile') ? 'mobile' : 'desktop';
    const envelopeName = `Browser envelope api fail ${suffix}`;
    const accountName = `Browser envelope fail acct ${suffix}`;
    await page.getByRole('button', { name: 'Month', exact: true }).click();
    // Add an Account to satisfy envelope.default_account_id validation.
    const accountsDetails = page.locator('#accountsDetails');
    await accountsDetails.locator(':scope > summary').click();
    await accountsDetails.getByRole('button', { name: 'Add Account' }).click();
    await page.locator('#accountName').fill(accountName);
    await page.locator('#accountNumber').fill(`BROWSER-ENVELOPE-FAIL-${suffix}`);
    await page.locator('#accountForm').getByRole('button', { name: 'Add account' }).click();
    await expect(page.locator('#accountsList')).toContainText(accountName);
    const accountId = await page.evaluate((name) => state.accounts.find((item) => item.name === name).id, accountName);
    // Create the envelope with a known default account so the Balance
    // record can be retried without a second open of the Envelope dialog.
    const envelopeId = await page.evaluate(async ({ name, accountId, year }) => {
      const response = await fetch('api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'saveEnvelope',
          envelope: { name, role: 'daily', visibility: 'normal', default_account_id: accountId, currency: 'CURRENCY' },
          year,
        }),
      });
      const data = await response.json();
      // Reload the JS state so the dialog opens the right one.
      await loadState(year);
      return state.envelopes.find((item) => item.name === name).id;
    }, { name: envelopeName, accountId, year: new Date().getFullYear() });
    expect(envelopeId, 'envelope must be created before the failure scenario').toBeGreaterThan(0);

    // Intercept the next saveEnvelopeBalanceObservation request and return
    // a real 400 with a recognisable reason.
    await page.route('**/api.php', async (route) => {
      const request = route.request();
      if (request.method() !== 'POST') return route.continue();
      let payload = {};
      try { payload = request.postDataJSON() || {}; } catch { return route.continue(); }
      if (payload.action !== 'saveEnvelopeBalanceObservation') return route.continue();
      return route.fulfill({
        status: 400,
        contentType: 'application/json',
        body: JSON.stringify({ error: 'Browser test: envelope balance rejected' }),
      });
    });

    await page.evaluate((id) => openBalanceDialog(id), envelopeId);
    await expect(page.locator('#balanceDialogTitle')).toContainText(envelopeName, { timeout: 5000 });
    await page.locator('#envelopeBalanceObservationDetails > summary').click();
    await page.locator('#envelopeBalanceObservationAmount').fill('4321');
    await page.locator('#envelopeBalanceObservationNote').fill('Browser failure test');
    await page.locator('#envelopeBalanceObservationForm').getByRole('button', { name: 'Record Balance' }).click();
    // The visible error message must surface the API's reason.
    await expect(page.locator('#toast')).toHaveText('Browser test: envelope balance rejected');
    const buttonState = await page.evaluate(() => {
      const button = document.querySelector('#envelopeBalanceObservationForm button[type="submit"]');
      return { disabled: button.disabled, ariaBusy: button.getAttribute('aria-busy'), isLoading: button.classList.contains('is-loading') };
    });
    expect(buttonState.disabled, 'submit button must be re-enabled after API failure').toBe(false);
    expect(buttonState.ariaBusy, 'submit button must not be in busy state after API failure').not.toBe('true');
    expect(buttonState.isLoading, 'submit button must not carry the loading class after API failure').toBe(false);
    // The Envelope must have no observations.
    const observationsCount = await page.evaluate(async (id) => {
      const state = await (await fetch(`api.php?action=state&year=${new Date().getFullYear()}`)).json();
      return state.envelope_balance_observations.filter((row) => row.envelope_id === id).length;
    }, envelopeId);
    expect(observationsCount, 'no Envelope Balance observation must be written on API failure').toBe(0);
    // The dialog must stay open so the user can correct and retry.
    expect(await page.locator('#balanceDialog').evaluate((d) => d.open), 'balance dialog must remain open on API failure').toBe(true);
  });

  test('Record Balance button shows the busy state during the request and a double click sends one request only', { tag: '@responsive' }, async ({ page }, testInfo) => {
    test.setTimeout(45_000);
    const suffix = testInfo.project.name.includes('mobile') ? 'mobile' : 'desktop';
    const accountName = `Browser balance busy ${suffix}`;
    const accountNumber = `TEST-BROWSER-BALANCE-BUSY-${suffix}`;
    await page.getByRole('button', { name: 'Month', exact: true }).click();
    const accountsDetails = page.locator('#accountsDetails');
    await accountsDetails.locator(':scope > summary').click();
    await accountsDetails.getByRole('button', { name: 'Add Account' }).click();
    await page.locator('#accountName').fill(accountName);
    await page.locator('#accountNumber').fill(accountNumber);
    await page.locator('#accountForm').getByRole('button', { name: 'Add account' }).click();
    await expect(page.locator('#accountsList')).toContainText(accountName);

    await page.locator('.managed-account-row').filter({ hasText: accountName }).click();
    await page.locator('#accountReconcileDetails > summary').click();
    await page.locator('#accountReconcileAmount').fill('100');

    // Hold the next saveAccountBalanceObservation request until release()
    // so the busy state is observable from Playwright without a fixed
    // setTimeout delay. Count requests to assert no double submission
    // despite two rapid clicks. Capture the current state before the
    // route is installed so we can return it from the fulfilled response.
    const currentState = await page.evaluate(async () => (await apiGet(`state&year=${encodeURIComponent(state.year)}`)));
    const currentStateJson = JSON.stringify(currentState);
    const held = await holdApiActionForRelease(page, {
      action: 'saveAccountBalanceObservation',
      body: { ok: true, id: 1, state: JSON.parse(currentStateJson) },
    });

    const submit = page.locator('#accountReconcileForm').getByRole('button', { name: 'Record Balance' });
    // Two rapid clicks. The second one must not fire because the button
    // is disabled by the in-flight helper.
    await submit.click();
    // Probe the button state during the in-flight window. We give the
    // request a moment to enter the busy state then verify.
    await page.waitForFunction(() => {
      const button = document.querySelector('#accountReconcileForm button[type="submit"]');
      return button && button.disabled && button.getAttribute('aria-busy') === 'true';
    }, null, { timeout: 2000 });
    const inFlightState = await page.evaluate(() => {
      const button = document.querySelector('#accountReconcileForm button[type="submit"]');
      const spinner = button.querySelector('.button-spinner');
      const spinnerStyle = spinner ? window.getComputedStyle(spinner) : null;
      return {
        disabled: button.disabled,
        ariaBusy: button.getAttribute('aria-busy'),
        isLoading: button.classList.contains('is-loading'),
        spinnerVisible: spinnerStyle ? spinnerStyle.display !== 'none' : false,
      };
    });
    expect(inFlightState.disabled, 'submit button must be disabled while the request is in flight').toBe(true);
    expect(inFlightState.ariaBusy, 'submit button must be in the busy state while the request is in flight').toBe('true');
    expect(inFlightState.isLoading, 'submit button must carry the loading class while the request is in flight').toBe(true);
    expect(inFlightState.spinnerVisible, 'spinner must be visible while the request is in flight').toBe(true);
    // Second click while busy. Playwright auto-waits for the element to
    // be enabled, so use force:true to bypass that and prove the second
    // click is dropped by the disabled state.
    await submit.click({ force: true }).catch(() => null);
    held.release();
    await held.fulfilled;
    await page.waitForFunction(() => {
      const button = document.querySelector('#accountReconcileForm button[type="submit"]');
      return button && !button.disabled && button.getAttribute('aria-busy') !== 'true';
    }, null, { timeout: 2000 });
    expect(held.requestCount(), 'double click must produce exactly one saveAccountBalanceObservation request').toBe(1);
    const finalState = await page.evaluate(() => {
      const button = document.querySelector('#accountReconcileForm button[type="submit"]');
      const spinner = button.querySelector('.button-spinner');
      const spinnerStyle = spinner ? window.getComputedStyle(spinner) : null;
      return {
        disabled: button.disabled,
        ariaBusy: button.getAttribute('aria-busy'),
        isLoading: button.classList.contains('is-loading'),
        spinnerVisible: spinnerStyle ? spinnerStyle.display !== 'none' : false,
      };
    });
    expect(finalState.disabled, 'submit button must be re-enabled after the request completes').toBe(false);
    expect(finalState.ariaBusy, 'submit button must leave the busy state after the request completes').not.toBe('true');
    expect(finalState.isLoading, 'submit button must drop the loading class after the request completes').toBe(false);
    expect(finalState.spinnerVisible, 'spinner must be hidden after the request completes').toBe(false);
  });

  test('Save Account form submit shows a real API failure with the API reason, re-enables the button, and writes no Account', { tag: '@responsive' }, async ({ page }, testInfo) => {
    // Representative coverage for a form-submit handler using
    // event.submitter as the busy button: failure path surfaces the API
    // reason via the shared toast and leaves the form intact so the user
    // can correct and retry.
    test.setTimeout(45_000);
    const suffix = testInfo.project.name.includes('mobile') ? 'mobile' : 'desktop';
    const accountName = `Browser account api fail ${suffix}`;
    const accountNumber = `TEST-BROWSER-ACCOUNT-FAIL-${suffix}`;
    await page.getByRole('button', { name: 'Month', exact: true }).click();
    const accountsDetails = page.locator('#accountsDetails');
    await accountsDetails.locator(':scope > summary').click();
    await accountsDetails.getByRole('button', { name: 'Add Account' }).click();
    await page.locator('#accountName').fill(accountName);
    await page.locator('#accountNumber').fill(accountNumber);

    await page.route('**/api.php', async (route) => {
      const request = route.request();
      if (request.method() !== 'POST') return route.continue();
      let payload = {};
      try { payload = request.postDataJSON() || {}; } catch { return route.continue(); }
      if (payload.action !== 'saveAccount') return route.continue();
      return route.fulfill({
        status: 400,
        contentType: 'application/json',
        body: JSON.stringify({ error: 'Browser test: account rejected' }),
      });
    });

    await page.locator('#accountForm').getByRole('button', { name: 'Add account' }).click();
    await expect(page.locator('#toast')).toHaveText('Browser test: account rejected');
    const buttonState = await page.evaluate(() => {
      const button = document.querySelector('#accountForm button[type="submit"]');
      return { disabled: button.disabled, ariaBusy: button.getAttribute('aria-busy'), isLoading: button.classList.contains('is-loading') };
    });
    expect(buttonState.disabled, 'Add account button must be re-enabled after API failure').toBe(false);
    expect(buttonState.ariaBusy, 'Add account button must not be in busy state after API failure').not.toBe('true');
    expect(buttonState.isLoading, 'Add account button must not carry the loading class after API failure').toBe(false);
    // Account must not have been written.
    const accountExists = await page.evaluate((name) => state.accounts.some((item) => item.name === name), accountName);
    expect(accountExists, 'no Account must be written on API failure').toBe(false);
    // Dialog must stay open so the user can correct and retry.
    expect(await page.locator('#accountDialog').evaluate((d) => d.open), 'account dialog must remain open on API failure').toBe(true);
  });

  test('Delete Envelope button shows busy state, surfaces API failure with API reason, and re-enables the button', { tag: '@responsive' }, async ({ page }, testInfo) => {
    // Representative coverage for a click handler that uses
    // event.currentTarget as the busy button: the success toast confirms
    // deletion, the failure toast surfaces the API reason, and the
    // button re-enables after the request completes.
    test.setTimeout(45_000);
    const suffix = testInfo.project.name.includes('mobile') ? 'mobile' : 'desktop';
    const envelopeName = `Browser envelope delete busy ${suffix}`;
    const accountName = `Browser envelope delete acct ${suffix}`;
    await page.getByRole('button', { name: 'Month', exact: true }).click();
    // Create an Account so the envelope has a default account.
    const accountsDetails = page.locator('#accountsDetails');
    await accountsDetails.locator(':scope > summary').click();
    await accountsDetails.getByRole('button', { name: 'Add Account' }).click();
    await page.locator('#accountName').fill(accountName);
    await page.locator('#accountNumber').fill(`BROWSER-DEL-ACCT-${suffix}`);
    await page.locator('#accountForm').getByRole('button', { name: 'Add account' }).click();
    await expect(page.locator('#accountsList')).toContainText(accountName);
    const accountId = await page.evaluate((name) => state.accounts.find((item) => item.name === name).id, accountName);
    // Create the envelope via the existing API helper, then reload state.
    const envelopeId = await page.evaluate(async ({ name, accountId, year }) => {
      const response = await fetch('api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'saveEnvelope',
          envelope: { name, role: 'daily', visibility: 'normal', default_account_id: accountId, currency: 'CURRENCY' },
          year,
        }),
      });
      const data = await response.json();
      await loadState(year);
      return state.envelopes.find((item) => item.name === name).id;
    }, { name: envelopeName, accountId, year: new Date().getFullYear() });
    expect(envelopeId, 'envelope must be created before the busy test').toBeGreaterThan(0);

    // Intercept the next deleteEnvelope request and return a real 400 so
    // we exercise the failure path through the shared busy helper.
    await page.route('**/api.php', async (route) => {
      const request = route.request();
      if (request.method() !== 'POST') return route.continue();
      let payload = {};
      try { payload = request.postDataJSON() || {}; } catch { return route.continue(); }
      if (payload.action !== 'deleteEnvelope') return route.continue();
      return route.fulfill({
        status: 400,
        contentType: 'application/json',
        body: JSON.stringify({ error: 'Browser test: delete envelope rejected' }),
      });
    });

    // Open the Envelope dialog and click Delete.
    await page.locator('.managed-account-row, .managed-envelope-row, button').first(); // ensure DOM is fresh
    await page.evaluate((id) => openEnvelopeDialog(id), envelopeId);
    page.once('dialog', (dialog) => dialog.accept());
    await page.locator('#deleteEnvelopeBtn').click();
    await expect(page.locator('#toast')).toHaveText('Browser test: delete envelope rejected');
    const buttonState = await page.evaluate(() => {
      const button = document.getElementById('deleteEnvelopeBtn');
      return { disabled: button.disabled, ariaBusy: button.getAttribute('aria-busy'), isLoading: button.classList.contains('is-loading') };
    });
    expect(buttonState.disabled, 'Delete button must be re-enabled after API failure').toBe(false);
    expect(buttonState.ariaBusy, 'Delete button must not be in busy state after API failure').not.toBe('true');
    expect(buttonState.isLoading, 'Delete button must not carry the loading class after API failure').toBe(false);
    // Envelope must still exist (delete was rejected).
    const envelopeExists = await page.evaluate((id) => state.envelopes.some((item) => item.id === id), envelopeId);
    expect(envelopeExists, 'envelope must not be deleted on API failure').toBe(true);
    // Dialog must stay open so the user can retry.
    expect(await page.locator('#envelopeDialog').evaluate((d) => d.open), 'envelope dialog must remain open on API failure').toBe(true);
  });

  test('Delete Account Balance observation button shows busy state, surfaces API failure with API reason, and re-enables the button', { tag: '@responsive' }, async ({ page }, testInfo) => {
    // Representative coverage for an inline-onclick history-row button
    // that passes the button as an argument: failure surfaces the API
    // reason via toast, and the button re-enables after the request.
    test.setTimeout(45_000);
    const suffix = testInfo.project.name.includes('mobile') ? 'mobile' : 'desktop';
    const accountName = `Browser acct obs busy ${suffix}`;
    const accountNumber = `TEST-BROWSER-OBS-BUSY-${suffix}`;
    await page.getByRole('button', { name: 'Month', exact: true }).click();
    const accountsDetails = page.locator('#accountsDetails');
    await accountsDetails.locator(':scope > summary').click();
    await accountsDetails.getByRole('button', { name: 'Add Account' }).click();
    await page.locator('#accountName').fill(accountName);
    await page.locator('#accountNumber').fill(accountNumber);
    await page.locator('#accountForm').getByRole('button', { name: 'Add account' }).click();
    await expect(page.locator('#accountsList')).toContainText(accountName);

    // Open the Account dialog, expand Record Balance, and record a Balance
    // so we have an observation we can attempt to delete.
    await page.locator('.managed-account-row').filter({ hasText: accountName }).click();
    await page.locator('#accountReconcileDetails > summary').click();
    await page.locator('#accountReconcileAmount').fill('500');
    const balanceRecorded = waitForApiAction(page, 'saveAccountBalanceObservation');
    await page.locator('#accountReconcileForm').getByRole('button', { name: 'Record Balance' }).click();
    await balanceRecorded;
    await expect(page.locator('#accountHistorySection')).toContainText('500');

    // Intercept the next deleteAccountBalanceObservation request and
    // return a real 400 with a recognisable reason.
    await page.route('**/api.php', async (route) => {
      const request = route.request();
      if (request.method() !== 'POST') return route.continue();
      let payload = {};
      try { payload = request.postDataJSON() || {}; } catch { return route.continue(); }
      if (payload.action !== 'deleteAccountBalanceObservation') return route.continue();
      return route.fulfill({
        status: 400,
        contentType: 'application/json',
        body: JSON.stringify({ error: 'Browser test: observation rejected' }),
      });
    });

    const deleteButton = page.locator('#accountHistory button.ghost.small.danger').filter({ hasText: 'Delete' }).first();
    page.once('dialog', (dialog) => dialog.accept());
    await deleteButton.click();
    await expect(page.locator('#toast')).toHaveText('Browser test: observation rejected');
    const buttonState = await page.evaluate(() => {
      const button = Array.from(document.querySelectorAll('#accountHistory button')).find((b) => b.textContent.trim() === 'Delete');
      return { disabled: button.disabled, ariaBusy: button.getAttribute('aria-busy'), isLoading: button.classList.contains('is-loading') };
    });
    expect(buttonState.disabled, 'Delete observation button must be re-enabled after API failure').toBe(false);
    expect(buttonState.ariaBusy, 'Delete observation button must not be in busy state after API failure').not.toBe('true');
    expect(buttonState.isLoading, 'Delete observation button must not carry the loading class after API failure').toBe(false);
    // Observation must still exist (delete was rejected).
    const accountId = await page.evaluate((name) => state.accounts.find((item) => item.name === name).id, accountName);
    const observationsCount = await page.evaluate(async (id) => {
      const history = await apiGet(`accountHistory&id=${id}`);
      return history.history.filter((row) => row.history_kind === 'observation').length;
    }, accountId);
    expect(observationsCount, 'no observation must be deleted on API failure').toBe(1);
  });

  test('Save Account submit button shows the busy state during the request and returns to idle afterwards', { tag: '@responsive' }, async ({ page }, testInfo) => {
    // Representative coverage for a form-submit handler using
    // event.submitter as the busy button: while the request is in
    // flight, the button is disabled, aria-busy="true", carries
    // is-loading, and the spinner is visible; afterwards it returns
    // to idle.
    test.setTimeout(45_000);
    const suffix = testInfo.project.name.includes('mobile') ? 'mobile' : 'desktop';
    const accountName = `Browser account busy ${suffix}`;
    const accountNumber = `TEST-BROWSER-ACCOUNT-BUSY-${suffix}`;
    await page.getByRole('button', { name: 'Month', exact: true }).click();
    const accountsDetails = page.locator('#accountsDetails');
    await accountsDetails.locator(':scope > summary').click();
    await accountsDetails.getByRole('button', { name: 'Add Account' }).click();
    await page.locator('#accountName').fill(accountName);
    await page.locator('#accountNumber').fill(accountNumber);

    // Hold the next saveAccount request until release() so the busy
    // state is observable from Playwright without a fixed setTimeout
    // delay.
    const currentState = await page.evaluate(async () => apiGet(`state&year=${state.year}`));
    const currentStateJson = JSON.stringify(currentState);
    const held = await holdApiActionForRelease(page, {
      action: 'saveAccount',
      body: { ok: true, state: JSON.parse(currentStateJson) },
    });
    const submit = page.locator('#accountForm').getByRole('button', { name: 'Add account' });
    await submit.click();
    await page.waitForFunction(() => {
      const button = document.querySelector('#accountForm button[type="submit"]');
      return button && button.disabled && button.getAttribute('aria-busy') === 'true';
    }, null, { timeout: 2000 });
    const inFlightState = await page.evaluate(() => {
      const button = document.querySelector('#accountForm button[type="submit"]');
      const spinner = button.querySelector('.button-spinner');
      const spinnerStyle = spinner ? window.getComputedStyle(spinner) : null;
      return {
        disabled: button.disabled,
        ariaBusy: button.getAttribute('aria-busy'),
        isLoading: button.classList.contains('is-loading'),
        spinnerVisible: spinnerStyle ? spinnerStyle.display !== 'none' : false,
      };
    });
    expect(inFlightState.disabled, 'submit button must be disabled while the request is in flight').toBe(true);
    expect(inFlightState.ariaBusy, 'submit button must be in the busy state while the request is in flight').toBe('true');
    expect(inFlightState.isLoading, 'submit button must carry the loading class while the request is in flight').toBe(true);
    expect(inFlightState.spinnerVisible, 'spinner must be visible while the request is in flight').toBe(true);
    held.release();
    await held.fulfilled;
    await page.waitForFunction(() => {
      const button = document.querySelector('#accountForm button[type="submit"]');
      return button && !button.disabled && button.getAttribute('aria-busy') !== 'true';
    }, null, { timeout: 2000 });
    const finalState = await page.evaluate(() => {
      const button = document.querySelector('#accountForm button[type="submit"]');
      const spinner = button.querySelector('.button-spinner');
      const spinnerStyle = spinner ? window.getComputedStyle(spinner) : null;
      return {
        disabled: button.disabled,
        ariaBusy: button.getAttribute('aria-busy'),
        isLoading: button.classList.contains('is-loading'),
        spinnerVisible: spinnerStyle ? spinnerStyle.display !== 'none' : false,
      };
    });
    expect(finalState.disabled, 'submit button must be re-enabled after the request completes').toBe(false);
    expect(finalState.ariaBusy, 'submit button must leave the busy state after the request completes').not.toBe('true');
    expect(finalState.isLoading, 'submit button must drop the loading class after the request completes').toBe(false);
    expect(finalState.spinnerVisible, 'spinner must be hidden after the request completes').toBe(false);
    expect(held.requestCount(), 'saveAccount must have been called exactly once').toBe(1);
  });

  test('cycleAllocationStatus shows a busy spinner while the request is in flight and re-enables afterwards', { tag: '@responsive' }, async ({ page }, testInfo) => {
    // The P/F/S toggle button must show a busy animation (spinner
    // replacing the letter) while the request is in flight, and the
    // letter must return afterwards. One representative run.
    test.setTimeout(45_000);
    const suffix = testInfo.project.name.includes('mobile') ? 'mobile' : 'desktop';
    // Ensure we are on the Month screen and the Funding section is open
    // so the .allocation-status-toggle controls are rendered.
    await page.getByRole('button', { name: 'Month', exact: true }).click();
    await page.locator('#fundingDetails').evaluate((node) => { node.open = true; });
    await page.evaluate(() => renderMonths());
    const toggle = page.locator('.allocation-status-toggle').first();
    await expect(toggle).toBeVisible();
    // Capture the current state and intercept the next
    // setAllocationStatus request with a hold until release() instead
    // of a fixed setTimeout delay.
    const currentState = await page.evaluate(async () => apiGet(`state&year=${state.year}`));
    const currentStateJson = JSON.stringify(currentState);
    const held = await holdApiActionForRelease(page, {
      action: 'setAllocationStatus',
      body: { ok: true, state: JSON.parse(currentStateJson) },
    });
    await toggle.click();
    // Probe the in-flight state.
    await page.waitForFunction(() => {
      const button = document.querySelector('.allocation-status-toggle.is-loading');
      return button !== null;
    }, null, { timeout: 2000 });
    const inFlightState = await page.evaluate(() => {
      const button = document.querySelector('.allocation-status-toggle.is-loading');
      if (!button) return null;
      const letter = button.querySelector('.allocation-status-letter');
      const spinner = button.querySelector('.allocation-status-spinner');
      const letterStyle = letter ? window.getComputedStyle(letter) : null;
      const spinnerStyle = spinner ? window.getComputedStyle(spinner) : null;
      return {
        disabled: button.disabled,
        ariaBusy: button.getAttribute('aria-busy'),
        isLoading: button.classList.contains('is-loading'),
        letterVisible: letterStyle ? letterStyle.display !== 'none' : false,
        spinnerVisible: spinnerStyle ? spinnerStyle.display !== 'none' : false,
      };
    });
    expect(inFlightState, 'toggle must carry is-loading during the request').not.toBeNull();
    expect(inFlightState.disabled, 'toggle must be disabled while the request is in flight').toBe(true);
    expect(inFlightState.ariaBusy, 'toggle must be in the busy state while the request is in flight').toBe('true');
    expect(inFlightState.spinnerVisible, 'spinner must be visible while the request is in flight').toBe(true);
    expect(inFlightState.letterVisible, 'letter must be hidden while the request is in flight').toBe(false);
    held.release();
    await held.fulfilled;
    await page.waitForFunction(() => {
      const button = document.querySelector('.allocation-status-toggle.is-loading');
      return button === null;
    }, null, { timeout: 2000 });
  });

  test('setRecommendationMonthExcluded checkbox shows the busy state during the request and re-enables afterwards', { tag: '@responsive' }, async ({ page }, testInfo) => {
    // The recommendation-history Exclude checkbox must be disabled,
    // aria-busy="true", and have its spinner visible while the request
    // is in flight, and must return to idle afterwards. This test
    // exercises the real renderAllocationRecommendation markup by
    // intercepting the fundingRecommendations API to return one
    // recommendation item that has representative_months and a
    // single observation. The seed-browser fixture seeds months and
    // envelopes but no transactions, so without the intercepted
    // response the real renderer would have no observations to render
    // and no checkbox would exist.
    test.setTimeout(45_000);
    const suffix = testInfo.project.name.includes('mobile') ? 'mobile' : 'desktop';
    // Resolve the envelope id + month id from state (these come from
    // the seeded baseline, which is shared with every test).
    const target = await page.evaluate(() => {
      const envelope = state.envelopes.find((item) => item.role === 'daily') || state.envelopes[0];
      const month = selectedPlanMonth() || activeMonth();
      if (!envelope || !month) throw new Error('Test prerequisite: baseline must include at least one envelope and month');
      return { envelopeId: envelope.id, year: month.year, month: month.month };
    });
    // Intercept the fundingRecommendations GET and return a payload
    // whose recommendation item has one observation; the real
    // renderAllocationRecommendation will then emit the
    // .recommendation-history-control row used here.
    await page.route('**/api.php*fundingRecommendations*', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          recommendations: [{
            envelope_id: target.envelopeId,
            envelope_name: 'Browser recommendation fixture',
            role: 'daily',
            amount: 1000,
            confidence: 'moderate',
            confidence_label: 'Moderate',
            basis: 'plan-supported',
            representative_months: 1,
            observations: [{ month: `${target.year}-${String(target.month).padStart(2, '0')}`, actual: 1000, outlier_limited: false, excluded: false }],
            stats: { average: 1000, median: 1000, range_low: 1000, range_high: 1000, trend: 'stable' },
            buffer: 0.05,
            pattern: 'stable',
            manual_baseline: null,
            manual_baseline_note: '',
            assessment: { code: 'appropriate', label: 'Matches your plan', source: 'fin' },
          }],
        }),
      });
    });
    // Intercept the next setRecommendationExclusion request with a
    // hold until release() so the in-flight busy state is observable
    // without a fixed setTimeout delay.
    const held = await holdApiActionForRelease(page, {
      action: 'setRecommendationExclusion',
      body: { ok: true },
    });
    // Open the entry dialog for the target envelope / month, so
    // openEntryDialog calls loadAndRenderAllocationRecommendation and
    // the real renderAllocationRecommendation markup is installed.
    await page.getByRole('button', { name: 'Month', exact: true }).click();
    await page.evaluate(({ envelopeId, year, month }) => {
      const monthRow = state.months.find((item) => item.year === year && item.month === month);
      openEntryDialog(null, monthRow.id, envelopeId);
    }, target);
    // Wait for the recommendation history checkbox. The recommendation
    // is rendered inside a collapsed <details class="recommendation-why">,
    // so we expand it before clicking the checkbox.
    const checkbox = page.locator('.recommendation-history-control input[type="checkbox"]').first();
    await expect(checkbox).toBeAttached();
    await page.evaluate(() => {
      const details = document.querySelector('.recommendation-history')?.closest('details');
      if (details) details.open = true;
    });
    await expect(checkbox).toBeVisible();
    await checkbox.click();
    await page.waitForFunction(() => {
      const input = document.querySelector('.recommendation-history-control input[aria-busy="true"]');
      return input !== null;
    }, null, { timeout: 2000 });
    const inFlightState = await page.evaluate(() => {
      const input = document.querySelector('.recommendation-history-control input[aria-busy="true"]');
      if (!input) return null;
      const control = input.closest('.recommendation-history-control');
      const spinner = control ? control.querySelector('.recommendation-spinner') : null;
      const spinnerStyle = spinner ? window.getComputedStyle(spinner) : null;
      return {
        disabled: input.disabled,
        ariaBusy: input.getAttribute('aria-busy'),
        controlIsLoading: control ? control.classList.contains('is-loading') : false,
        spinnerVisible: spinnerStyle ? spinnerStyle.display !== 'none' : false,
      };
    });
    expect(inFlightState, 'checkbox must carry aria-busy during the request').not.toBeNull();
    expect(inFlightState.disabled, 'checkbox must be disabled while the request is in flight').toBe(true);
    expect(inFlightState.controlIsLoading, 'control wrapper must carry is-loading while the request is in flight').toBe(true);
    expect(inFlightState.spinnerVisible, 'spinner must be visible while the request is in flight').toBe(true);
    held.release();
    await held.fulfilled;
    await page.waitForFunction(() => {
      const input = document.querySelector('.recommendation-history-control input[aria-busy="true"]');
      return input === null;
    }, null, { timeout: 2000 });
    const finalState = await page.evaluate(() => {
      const input = document.querySelector('.recommendation-history-control input');
      return {
        disabled: input.disabled,
        ariaBusy: input.getAttribute('aria-busy'),
      };
    });
    expect(finalState.disabled, 'checkbox must be re-enabled after the request completes').toBe(false);
    expect(finalState.ariaBusy, 'checkbox must not be in busy state after the request completes').not.toBe('true');
  });

  test('Delete Envelope button shows the busy state during the request and a double click sends one request only', { tag: '@responsive' }, async ({ page }, testInfo) => {
    // Same coverage as the Record Balance double-click test, but for a
    // non-Record-Balance handler: the click handler used here is
    // Delete Envelope, which routes through runSaveAction with
    // event.currentTarget as the busy button.
    test.setTimeout(45_000);
    const suffix = testInfo.project.name.includes('mobile') ? 'mobile' : 'desktop';
    const envelopeName = `Browser envelope delete busy ${suffix}`;
    const accountName = `Browser envelope delete acct ${suffix}`;
    await page.getByRole('button', { name: 'Month', exact: true }).click();
    const accountsDetails = page.locator('#accountsDetails');
    await accountsDetails.locator(':scope > summary').click();
    await accountsDetails.getByRole('button', { name: 'Add Account' }).click();
    await page.locator('#accountName').fill(accountName);
    await page.locator('#accountNumber').fill(`BROWSER-DEL-BUSY-${suffix}`);
    await page.locator('#accountForm').getByRole('button', { name: 'Add account' }).click();
    await expect(page.locator('#accountsList')).toContainText(accountName);
    const accountId = await page.evaluate((name) => state.accounts.find((item) => item.name === name).id, accountName);
    const envelopeId = await page.evaluate(async ({ name, accountId, year }) => {
      const response = await fetch('api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'saveEnvelope',
          envelope: { name, role: 'daily', visibility: 'normal', default_account_id: accountId, currency: 'CURRENCY' },
          year,
        }),
      });
      const data = await response.json();
      await loadState(year);
      return state.envelopes.find((item) => item.name === name).id;
    }, { name: envelopeName, accountId, year: new Date().getFullYear() });
    expect(envelopeId, 'envelope must be created before the busy test').toBeGreaterThan(0);

    // Hold the next deleteEnvelope request until release() so the busy
    // state is observable from Playwright without a fixed setTimeout
    // delay.
    const currentState = await page.evaluate(async () => apiGet(`state&year=${state.year}`));
    const currentStateJson = JSON.stringify(currentState);
    const held = await holdApiActionForRelease(page, {
      action: 'deleteEnvelope',
      body: { ok: true, state: JSON.parse(currentStateJson) },
    });

    await page.evaluate((id) => openEnvelopeDialog(id), envelopeId);
    page.once('dialog', (dialog) => dialog.accept());
    const deleteBtn = page.locator('#deleteEnvelopeBtn');
    await deleteBtn.click();
    // Two rapid clicks. The second one must not fire because the button
    // is disabled by the in-flight helper.
    await page.waitForFunction(() => {
      const button = document.getElementById('deleteEnvelopeBtn');
      return button && button.disabled && button.getAttribute('aria-busy') === 'true';
    }, null, { timeout: 2000 });
    await deleteBtn.click({ force: true }).catch(() => null);
    held.release();
    await held.fulfilled;
    await page.waitForFunction(() => {
      const button = document.getElementById('deleteEnvelopeBtn');
      return button && !button.disabled && button.getAttribute('aria-busy') !== 'true';
    }, null, { timeout: 2000 });
    expect(held.requestCount(), 'double click must produce exactly one deleteEnvelope request').toBe(1);
  });

  test('renameDeviceCredential shows the Device login renamed toast and the Rename button returns to idle', async ({ page }, testInfo) => {
    // Representative coverage for the inline-onclick renameDevice
    // handler: the success toast must appear and the Rename button
    // must return to idle after the request completes.
    test.setTimeout(45_000);
    const suffix = testInfo.project.name.includes('mobile') ? 'mobile' : 'desktop';
    // Intercept the next renameWebauthnCredential request with a hold
    // until release() so the in-flight busy state is observable without
    // a fixed setTimeout delay.
    const held = await holdApiActionForRelease(page, {
      action: 'renameWebauthnCredential',
      body: (payload) => ({ ok: true, credentials: [{ id: payload.id, label: payload.label, last_used_at: null }] }),
    });
    // The test storage state is created via masterPinLogin, which
    // does not auto-enroll a WebAuthn credential; inject one into the
    // client state and re-render the security list so the test exercises
    // the real renameDeviceCredential handler + real API request. No
    // fixture change: this is a one-line in-memory setup that doesn't
    // reach into the database.
    await page.getByRole('button', { name: 'Month', exact: true }).click();
    await page.locator('#securityBtn').click();
    await page.evaluate(() => {
      state.deviceCredentials = [{ id: 777001, label: 'Browser rename fixture', last_used_at: null }];
      renderDeviceCredentials();
    });
    const renameBtn = page.locator('[data-device-rename]').first();
    await expect(renameBtn).toBeVisible();
    // Click Rename, accept the prompt with a new label.
    page.once('dialog', (dialog) => dialog.accept(`Renamed browser credential ${suffix}`));
    await renameBtn.click();
    // Probe the in-flight busy state on the same button.
    await page.waitForFunction(() => {
      const button = document.querySelector('[data-device-rename].is-loading');
      return button !== null;
    }, null, { timeout: 2000 });
    const inFlightState = await page.evaluate(() => {
      const button = document.querySelector('[data-device-rename].is-loading');
      if (!button) return null;
      const spinner = button.querySelector('.button-spinner');
      const spinnerStyle = spinner ? window.getComputedStyle(spinner) : null;
      return {
        disabled: button.disabled,
        ariaBusy: button.getAttribute('aria-busy'),
        isLoading: button.classList.contains('is-loading'),
        spinnerVisible: spinnerStyle ? spinnerStyle.display !== 'none' : false,
      };
    });
    expect(inFlightState, 'Rename button must carry is-loading during the request').not.toBeNull();
    expect(inFlightState.disabled, 'Rename button must be disabled while the request is in flight').toBe(true);
    expect(inFlightState.ariaBusy, 'Rename button must be in the busy state while the request is in flight').toBe('true');
    expect(inFlightState.spinnerVisible, 'Rename button spinner must be visible while the request is in flight').toBe(true);
    held.release();
    await held.fulfilled;
    await page.waitForFunction(() => {
      const button = document.querySelector('[data-device-rename].is-loading');
      return button === null;
    }, null, { timeout: 2000 });
    const finalState = await page.evaluate(() => {
      const button = document.querySelector('[data-device-rename]');
      return {
        disabled: button.disabled,
        ariaBusy: button.getAttribute('aria-busy'),
        isLoading: button.classList.contains('is-loading'),
      };
    });
    expect(finalState.disabled, 'Rename button must be re-enabled after the request completes').toBe(false);
    expect(finalState.ariaBusy, 'Rename button must not be in busy state after the request completes').not.toBe('true');
    expect(finalState.isLoading, 'Rename button must drop the loading class after the request completes').toBe(false);
    // Success toast must be visibly displayed.
    await expect(page.locator('#toast')).toHaveText('Device login renamed.');
    expect(held.requestCount(), 'renameWebauthnCredential must have been called exactly once').toBe(1);
  });

  test('an Account keeps one exact negative signed Balance through reload', async ({ page }, testInfo) => {
    const suffix = testInfo.project.name.includes('mobile') ? 'mobile' : 'desktop';
    const accountName = `Browser negative Account ${suffix}`;
    const accountNumber = `TEST-BROWSER-NEGATIVE-${suffix}`;

    await page.getByRole('button', { name: 'Month', exact: true }).click();
    const accountsDetails = page.locator('#accountsDetails');
    await accountsDetails.locator(':scope > summary').click();
    await accountsDetails.getByRole('button', { name: 'Add Account' }).click();
    await page.locator('#accountName').fill(accountName);
    await page.locator('#accountNumber').fill(accountNumber);
    await expect(page.locator('#accountInitialAmountLabel')).toContainText('Balance DKK');
    await page.locator('#accountInitialAmount').fill('-19609.23');
    await page.locator('#accountForm').getByRole('button', { name: 'Add account' }).click();

    let accountRow = page.locator('.managed-account-row').filter({ hasText: accountName });
    await expect(accountRow).toContainText('-19.609,23 DKK');
    expect(await page.evaluate((name) => {
      const account = state.accounts.find((item) => item.name === name);
      return { hasType: Object.hasOwn(account, 'type'), balance: account.balance };
    }, accountName)).toEqual({ hasType: false, balance: -19609.23 });

    await page.reload();
    await page.waitForFunction((name) => state.accounts.some((account) => account.name === name), accountName);
    await page.getByRole('button', { name: 'Month', exact: true }).click();
    await page.locator('#accountsDetails > summary').click();
    accountRow = page.locator('.managed-account-row').filter({ hasText: accountName });
    await expect(accountRow).toContainText('-19.609,23 DKK');
    await accountRow.click();
    await expect(page.locator('#accountCurrentPosition')).toContainText('-19.609,23 DKK');
    await expect(page.locator('#accountType')).toHaveCount(0);
  });

  test('null and cross-source expenses edit by spent date without changing source metadata', async ({ page }) => {
    const created = await page.evaluate(async () => {
      const year = new Date().getFullYear();
      const current = await (await fetch(`api.php?action=state&year=${year}`)).json();
      const envelopeResponse = await fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'saveEnvelope', envelope: { name: `Source audit ${Date.now()} ${Math.random()}`, account: 'Audit isolated', role: 'savings', visibility: 'hidden' }, year }) });
      const envelope = { id: (await envelopeResponse.json()).id };
      const now = new Date();
      const actualPrefix = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
      const spentDate = `${actualPrefix}-05`;
      const source = current.months.find((item) => `${item.year}-${String(item.month).padStart(2, '0')}` !== actualPrefix);
      if (!source) {
        throw new Error(`null and cross-source expenses test requires a non-current reference Month; none found in state.months (current actualPrefix=${actualPrefix}). The seed-browser.php fixture must create at least one Month whose year-month differs from Today.`);
      }
      const create = async (monthId, note, includeDate = true) => {
        const transaction = { month_id: monthId, envelope_id: envelope.id, amount: 123, note };
        if (includeDate) transaction.spent_date = spentDate;
        const response = await fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'createTransaction', transaction, year }) });
        return { status: response.status, body: await response.json() };
      };
      const nullSource = await create(null, 'Null source');
      const crossSource = await create(source.id, 'Cross source');
      const missingDate = await create(null, 'Missing date', false);
      return { nullId: nullSource.body.id, crossId: crossSource.body.id, sourceId: source.id, spentDate, actualPrefix, missingDateStatus: missingDate.status };
    });
    expect(created.missingDateStatus).toBe(400);
    await page.reload();
    await expect(page.locator('#appView')).toBeVisible();
    await expect.poll(() => page.evaluate((id) => Boolean(state.transactions.find((transaction) => Number(transaction.id) === Number(id))), created.nullId)).toBe(true);

    await page.evaluate((id) => openExpenseDialog(id), created.nullId);
    await expect(page.locator('#expenseDate')).toHaveAttribute('min', `${created.actualPrefix}-01`);
    await page.locator('#expenseNote').fill('Null source edited');
    await page.locator('#expenseForm').getByRole('button', { name: 'Save changes' }).click();
    await expect(page.locator('#expenseDialog')).not.toBeVisible();

    await page.evaluate((id) => openExpenseDialog(id), created.crossId);
    await expect(page.locator('#expenseDate')).toHaveValue(created.spentDate);
    await expect(page.locator('#expenseDate')).toHaveAttribute('min', `${created.actualPrefix}-01`);
    await page.locator('#expenseNote').fill('Cross source edited');
    await page.locator('#expenseForm').getByRole('button', { name: 'Save changes' }).click();

    const sources = await page.evaluate(async ({ nullId, crossId }) => {
      const year = new Date().getFullYear();
      const loaded = await (await fetch(`api.php?action=state&year=${year}`)).json();
      const byId = (id) => loaded.transactions.find((item) => Number(item.id) === Number(id));
      return { nullMonth: byId(nullId).month_id, crossMonth: byId(crossId).month_id, nullNote: byId(nullId).note, crossNote: byId(crossId).note };
    }, created);
    expect(sources).toEqual({ nullMonth: null, crossMonth: created.sourceId, nullNote: 'Null source edited', crossNote: 'Cross source edited' });
  });

  test('Paid from defaults by envelope and stays overrideable when editing', { tag: '@responsive' }, async ({ page }, testInfo) => {
    const note = `Payment account ${testInfo.project.name}`;
    await page.getByRole('button', { name: 'Add expense' }).click();
    const envelopeAId = await page.evaluate(() => state.envelopes.find((envelope) => envelope.name === 'Envelope A').id);
    await page.locator('#expenseEnvelope').selectOption(String(envelopeAId));
    await expect(page.locator('#expensePaymentAccount')).toHaveValue(await page.evaluate(() => String(state.accounts.find((account) => account.name === 'Test Wallet').id)));
    await expect(page.locator('#expensePaymentAccount')).toContainText('Test Card');
    const testCardId = await page.evaluate(() => String(state.accounts.find((account) => account.name === 'Test Card').id));
    await page.locator('#expensePaymentAccount').selectOption(testCardId);
    await page.locator('#expenseAmount').fill('125');
    await page.locator('#expenseNote').fill(note);
    await page.locator('#expenseForm').getByRole('button', { name: 'Save expense' }).click();
    await expect(page.locator('#expenseDialog')).toBeHidden();
    const transactionId = await page.evaluate((paymentNote) => state.transactions.find((transaction) => transaction.note === paymentNote).id, note);
    expect(await page.evaluate((id) => state.transactions.find((transaction) => transaction.id === id).payment_account_id, transactionId)).toBe(Number(testCardId));

    await page.evaluate((id) => openExpenseDialog(id), transactionId);
    await expect(page.locator('#expensePaymentAccount')).toHaveValue(testCardId);
    const testBankId = await page.evaluate(() => String(state.accounts.find((account) => account.name === 'Test Bank').id));
    await page.locator('#expensePaymentAccount').selectOption(testBankId);
    await page.locator('#expenseForm').getByRole('button', { name: 'Save changes' }).click();
    await expect(page.locator('#expenseDialog')).toBeHidden();
    expect(await page.evaluate((id) => ({ count: state.transactions.filter((transaction) => transaction.id === id).length, account: state.transactions.find((transaction) => transaction.id === id).payment_account_id }), transactionId)).toEqual({ count: 1, account: Number(testBankId) });
    await page.evaluate((id) => openExpenseDialog(id), transactionId);
    expect(await page.locator('#expenseDialog').evaluate((node) => node.scrollWidth <= node.clientWidth + 1)).toBeTruthy();
    await page.evaluate(() => { window.confirm = () => true; });
    await page.locator('#deleteExpenseBtn').click();
    await expect(page.locator('#expenseDialog')).toBeHidden();
  });

  test('manual baseline remains distinct from FIN guidance in allocation and Funding Review', async ({ page }) => {
    await page.getByRole('button', { name: 'Month', exact: true }).click();
    // The browser fixture seeds a Plan entry for Envelope A in the
    // fixed September 2026 reference Month. Select it explicitly so
    // this test is independent of which calendar month happens to be
    // "current" at the time it runs.
    await page.evaluate(() => {
      const target = state.months.find((month) => month.year === 2026 && month.month === 9);
      if (!target) throw new Error('September 2026 reference month is missing from the browser fixture');
      state.selectedPlanYear = target.year;
      state.selectedPlanMonth = target.month;
      renderMonths();
    });
    await page.evaluate(() => openEnvelopeDialog(state.envelopes.find((envelope) => envelope.name === 'Envelope A').id));
    await page.locator('#envelopeManualBaseline').fill('3500');
    await page.locator('#envelopeManualBaselineNote').fill('Manual historical review');
    await page.locator('#envelopeForm').getByRole('button', { name: 'Save envelope' }).click();
    await expect(page.locator('#envelopeDialog')).toBeHidden();

    await page.evaluate(() => {
      const month = selectedPlanMonth();
      const envelope = state.envelopes.find((item) => item.name === 'Envelope A');
      const entry = budgetEntryForEnvelope(month.id, envelope.id);
      if (!entry) throw new Error(`Envelope A Plan entry is missing for ${month.year}-${month.month}`);
      openEntryDialog(entry.id);
    });
    const guidance = page.locator('#allocationRecommendation');
    await expect(guidance).toContainText('Historical baseline: 3.500 DKK/month');
    await expect(guidance).toContainText('Source: Manual historical review');
    await expect(guidance).toContainText('FIN recommendation: Not available yet');
    await guidance.getByRole('button', { name: 'Use baseline' }).click();
    await expect(page.locator('#entryAmount')).toHaveValue('3500');
    await page.locator('#closeEntryDialogBtn').click();

    await page.locator('#monthDetails').locator(':scope > summary').click();
    await page.getByRole('button', { name: 'Report / export' }).click();
    await page.getByRole('button', { name: 'Funding Review' }).click();
    await expect(page.locator('#fundingReviewDialog')).toBeVisible();
    const row = page.locator('.funding-review-row').filter({ hasText: 'Envelope A' });
    await expect(row).toContainText('3.500 DKK');
    await expect(row).toContainText('Baseline-based');
    await expect(row).toContainText('Insufficient');
    expect(await page.locator('#fundingReviewContent').evaluate((node) => node.scrollWidth <= node.clientWidth + 1)).toBeTruthy();
  });

  test('historical baseline is shown in the preferred currency of its Envelope', async ({ page }) => {
    await page.getByRole('button', { name: 'Month', exact: true }).click();
    await page.evaluate(() => {
      const target = state.months.find((month) => month.year === 2026 && month.month === 9);
      if (!target) throw new Error('September 2026 reference month is missing from the browser fixture');
      state.selectedPlanYear = target.year;
      state.selectedPlanMonth = target.month;
      renderMonths();
    });
    await page.evaluate(() => openEnvelopeDialog(state.envelopes.find((envelope) => envelope.name === 'Envelope A').id));
    await page.locator('#envelopeCurrency').selectOption('HELPER_CURRENCY');
    await page.locator('#envelopeManualBaseline').fill('3500');
    await page.locator('#envelopeManualBaselineNote').fill('Manual historical review');
    await page.locator('#envelopeForm').getByRole('button', { name: 'Save envelope' }).click();
    await expect(page.locator('#envelopeDialog')).toBeHidden();

    await page.evaluate(() => {
      const month = selectedPlanMonth();
      const envelope = state.envelopes.find((item) => item.name === 'Envelope A');
      const entry = budgetEntryForEnvelope(month.id, envelope.id);
      if (!entry) throw new Error('Envelope A Plan entry is missing for the selected Month');
      openEntryDialog(entry.id);
    });
    const guidance = page.locator('#allocationRecommendation');
    // The baseline is one authoritative Currency amount, shown in the currency the
    // Envelope plans in, with the Currency amount kept as the secondary value.
    await expect(guidance).toContainText('Historical baseline: 469,17 EUR/month');
    await expect(guidance).toContainText('3.500 DKK');
    await guidance.getByRole('button', { name: 'Use baseline' }).click();
    await expect(page.locator('#entryAmount')).toHaveValue('469,17');
    expect(await page.locator('#allocationRecommendation').evaluate((node) => node.scrollWidth <= node.clientWidth + 1)).toBeTruthy();
  });

  test('an envelope offers exactly the Fixed and Monthly reset checkboxes and keeps both attributes', async ({ page }) => {
    await page.evaluate(() => openEnvelopeDialog(state.envelopes.find((envelope) => envelope.name === 'Envelope A').id));
    await expect(page.locator('#envelopeDialog')).toBeVisible();
    await expect(page.locator('#envelopeDialog input[type="checkbox"]')).toHaveCount(2);
    await expect(page.getByRole('checkbox', { name: 'Fixed', exact: true })).toBeVisible();
    await expect(page.getByRole('checkbox', { name: 'Monthly reset', exact: true })).toBeVisible();
    await expect(page.locator('#envelopeFixed')).not.toBeChecked();
    await expect(page.locator('#envelopeReset')).not.toBeChecked();
    // Both checkboxes share one row, side by side, without a tall gap.
    const checkboxRows = await page.evaluate(() => {
      const fixed = document.querySelector('#envelopeFixed').getBoundingClientRect();
      const reset = document.querySelector('#envelopeReset').getBoundingClientRect();
      return { sameRow: Math.abs(fixed.y - reset.y) < 2, gap: Math.round(reset.x - fixed.x) };
    });
    expect(checkboxRows.sameRow, 'Fixed and Monthly reset must share one row').toBeTruthy();
    expect(checkboxRows.gap, 'Fixed and Monthly reset must sit next to each other').toBeLessThan(200);

    await page.locator('#envelopeFixed').check();
    await page.locator('#envelopeReset').check();
    await page.locator('#envelopeForm').getByRole('button', { name: 'Save envelope' }).click();
    await expect(page.locator('#envelopeDialog')).toBeHidden();
    expect(await page.evaluate(() => {
      const envelope = state.envelopes.find((item) => item.name === 'Envelope A');
      return { amount_source: envelope.amount_source, month_end_behavior: envelope.month_end_behavior };
    })).toEqual({ amount_source: 'fixed', month_end_behavior: 'reset' });

    await page.evaluate(() => openEnvelopeDialog(state.envelopes.find((envelope) => envelope.name === 'Envelope A').id));
    await expect(page.locator('#envelopeFixed')).toBeChecked();
    await expect(page.locator('#envelopeReset')).toBeChecked();
    await page.locator('#envelopeFixed').uncheck();
    await page.locator('#envelopeReset').uncheck();
    await page.locator('#envelopeForm').getByRole('button', { name: 'Save envelope' }).click();
    await expect(page.locator('#envelopeDialog')).toBeHidden();
    expect(await page.evaluate(() => {
      const envelope = state.envelopes.find((item) => item.name === 'Envelope A');
      return { amount_source: envelope.amount_source, month_end_behavior: envelope.month_end_behavior };
    })).toEqual({ amount_source: 'history', month_end_behavior: 'accumulate' });
  });
});

unauthenticatedTest('each browser automatically enrolls and uses only its own device credential', async ({ page, browser, baseURL }) => {
  test.setTimeout(120_000);

  const laptopCdp = await page.context().newCDPSession(page);
  await laptopCdp.send('WebAuthn.enable');
  const laptopAuthenticator = await laptopCdp.send('WebAuthn.addVirtualAuthenticator', {
    options: {
      protocol: 'ctap2',
      transport: 'internal',
      hasResidentKey: false,
      hasUserVerification: true,
      isUserVerified: true,
      automaticPresenceSimulation: true,
    },
  });

  await signInWithMasterPin(page);
  await expect(page.locator('#enableDeviceLoginBtn')).toHaveCount(0);
  const laptopCredentialId = await page.evaluate(() => localStorage.getItem('fin.deviceCredentialId'));
  expect(laptopCredentialId).toBeTruthy();

  const laptopCredentials = await laptopCdp.send('WebAuthn.getCredentials', { authenticatorId: laptopAuthenticator.authenticatorId });
  expect(laptopCredentials.credentials).toHaveLength(1);
  expect(laptopCredentials.credentials[0].isResidentCredential).toBe(false);
  const credentialCountAfterLaptop = await page.evaluate(async () => (await apiGet('deviceCredentials')).credentials.length);
  const laptopOptions = await page.evaluate((credentialId) => apiPost({ action: 'webauthnAssertionOptions', credential_id: credentialId }), laptopCredentialId);
  expect(laptopOptions.options.publicKey).toMatchObject({ rpId: 'localhost', userVerification: 'required' });
  expect(laptopOptions.options.publicKey.allowCredentials).toHaveLength(1);
  expect(laptopOptions.options.publicKey.allowCredentials[0].id).toBe(laptopCredentialId);
  expect(laptopOptions.options.publicKey.allowCredentials[0].transports).toEqual(['internal']);

  await page.addInitScript(() => {
    const originalGet = navigator.credentials.get.bind(navigator.credentials);
    Object.defineProperty(navigator.credentials, 'get', {
      configurable: true,
      value: async (...args) => {
        localStorage.setItem('fin.testDeviceGetCalls', String(Number(localStorage.getItem('fin.testDeviceGetCalls') || 0) + 1));
        return originalGet(...args);
      },
    });
    localStorage.setItem('fin.testDeviceGetCalls', '0');
  });
  await page.reload();
  await expect(page.locator('#appView')).toBeVisible();
  expect(await page.evaluate(() => localStorage.getItem('fin.testDeviceGetCalls'))).toBe('0');

  await page.getByRole('button', { name: 'Logout' }).click();
  await expect(page.locator('#deviceAuthProgress')).toBeVisible();
  await expect(page.locator('#appView')).toBeVisible();
  await expect(page.locator('#pinLoginForm')).toBeHidden();

  const phoneContext = await browser.newContext();
  const phone = await phoneContext.newPage();
  const phoneCdp = await phoneContext.newCDPSession(phone);
  await phoneCdp.send('WebAuthn.enable');
  const phoneAuthenticator = await phoneCdp.send('WebAuthn.addVirtualAuthenticator', {
    options: {
      protocol: 'ctap2',
      transport: 'internal',
      hasResidentKey: false,
      hasUserVerification: true,
      isUserVerified: true,
      automaticPresenceSimulation: true,
    },
  });
  let phoneAssertionOptionRequests = 0;
  phone.on('request', (request) => {
    if (request.method() === 'POST' && request.postData()?.includes('webauthnAssertionOptions')) phoneAssertionOptionRequests++;
  });

  await phone.goto(`${baseURL}/`);
  await expect(phone.locator('#pinLoginForm')).toBeVisible();
  expect(phoneAssertionOptionRequests).toBe(0);
  await signInWithMasterPin(phone, null);
  const phoneCredentialId = await phone.evaluate(() => localStorage.getItem('fin.deviceCredentialId'));
  expect(phoneCredentialId).toBeTruthy();
  expect(phoneCredentialId).not.toBe(laptopCredentialId);
  const phoneCredentials = await phoneCdp.send('WebAuthn.getCredentials', { authenticatorId: phoneAuthenticator.authenticatorId });
  expect(phoneCredentials.credentials).toHaveLength(1);
  expect(phoneCredentials.credentials[0].isResidentCredential).toBe(false);

  await phone.getByRole('button', { name: 'Month', exact: true }).click();
  await phone.getByRole('button', { name: 'Security' }).click();
  await expect(phone.locator('.device-credential-row')).toHaveCount(credentialCountAfterLaptop + 1);
  await expect(phone.locator('#enableDeviceLoginBtn')).toHaveCount(0);
  await phone.locator('#closeSecurityDialogBtn').click();
  await phone.getByRole('button', { name: 'Logout' }).click();
  await expect(phone.locator('#appView')).toBeVisible();
  await expect(phone.locator('#pinLoginForm')).toBeHidden();

  await page.getByRole('button', { name: 'Logout' }).click();
  await expect(page.locator('#appView')).toBeVisible();
  expect(await page.evaluate(() => localStorage.getItem('fin.deviceCredentialId'))).toBe(laptopCredentialId);

  const fakeContext = await browser.newContext();
  await fakeContext.addInitScript(() => localStorage.setItem('fin.deviceCredentialId', 'ZmFrZS1jcmVkZW50aWFs'));
  const fakePage = await fakeContext.newPage();
  const fakeCdp = await fakeContext.newCDPSession(fakePage);
  await fakeCdp.send('WebAuthn.enable');
  await fakeCdp.send('WebAuthn.addVirtualAuthenticator', {
    options: {
      protocol: 'ctap2',
      transport: 'internal',
      hasResidentKey: false,
      hasUserVerification: true,
      isUserVerified: true,
      automaticPresenceSimulation: true,
    },
  });
  await fakePage.goto(`${baseURL}/`);
  await expect(fakePage.locator('#pinLoginForm')).toBeVisible();
  await expect(fakePage.locator('#appView')).toBeHidden();
  expect(await fakePage.evaluate(() => localStorage.getItem('fin.deviceCredentialId'))).toBeNull();
  await fakeContext.close();

  const phoneCredentialRows = await phone.evaluate(() => apiGet('deviceCredentials'));
  const phoneCredentialRow = phoneCredentialRows.credentials.at(-1);
  await phone.evaluate((id) => apiPost({ action: 'revokeWebauthnCredential', id }), phoneCredentialRow.id);
  await phone.getByRole('button', { name: 'Logout' }).click();
  await expect(phone.locator('#pinLoginForm')).toBeVisible();
  expect(await phone.evaluate(() => localStorage.getItem('fin.deviceCredentialId'))).toBeNull();

  await page.evaluate(() => localStorage.removeItem('fin.deviceCredentialId'));
  await page.getByRole('button', { name: 'Logout' }).click();
  await expect(page.locator('#pinLoginForm')).toBeVisible();
  await signInWithMasterPin(page, null);
  const reEnrolledLaptopId = await page.evaluate(() => localStorage.getItem('fin.deviceCredentialId'));
  expect(reEnrolledLaptopId).toBeTruthy();
  expect(reEnrolledLaptopId).not.toBe(laptopCredentialId);

  await page.evaluate(() => {
    Object.defineProperty(navigator.credentials, 'get', {
      configurable: true,
      value: async () => { throw new DOMException('Cancelled', 'NotAllowedError'); },
    });
  });
  await page.getByRole('button', { name: 'Logout' }).click();
  await expect(page.locator('#deviceAuthCancelled')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Try again' })).toBeVisible();
  await page.getByRole('button', { name: 'Use Master PIN' }).click();
  await expect(page.locator('#pinLoginForm')).toBeVisible();

  await phoneContext.close();
  await laptopCdp.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId: laptopAuthenticator.authenticatorId });
});

test('Statement import with a synthetic debit/credit pre-header fixture previews, selects an Account, and imports evidence', async ({ page, request }) => {
  const pageErrors = [];
  page.on('pageerror', (error) => pageErrors.push(error.message));
  const consoleErrors = [];
  page.on('console', (msg) => { if (msg.type() === 'error') consoleErrors.push(msg.text()); });

  await openAuthenticatedApp(page);

  // Generate a self-owned synthetic Test Bank statement with a unique invented
  // account number. Every assertion below targets the values this spec
  // produces; nothing depends on real/private statement files.
  const suffix = 'cf-' + Date.now().toString(36) + '-' + Math.floor(Math.random() * 1e6).toString(36);
  const foreignAccountNumber = '0000' + Date.now().toString() + String(Math.floor(Math.random() * 1e6)).padStart(6, '0');
  const foreignRows = [];
  for (let i = 1; i <= 12; i++) {
    foreignRows.push({
      booking_date: '2026-04-' + (i < 10 ? '0' + i : i),
      amount: i % 2 === 0 ? -50 - i : 80 + i,
      description: 'Synthetic Test Bank row ' + suffix + ' #' + i,
      counterparty: 'Synthetic counterparty ' + i,
    });
  }
  const foreignBytes = buildSyntheticDebitCreditPreheaderXlsx({ accountNumber: foreignAccountNumber, currency: 'EUR', rows: foreignRows });
  const foreignTmp = writeTempXlsx('fin-cf-debit-credit', foreignBytes);

  // Create a dedicated Test Bank Account so the operator can select it through
  // the real API. account_number deliberately does NOT match the
  // statement's informational identifier to verify explicit Account authority.
  const foreignAccountResponse = await page.request.post('/api.php', {
    headers: { 'content-type': 'application/json' },
    data: {
      action: 'saveAccount',
      account: {
        name: 'Test Bank UI ' + suffix,
        account_number: 'TEST-BANK-UI-' + suffix,
        currency: 'CURRENCY',
      },
    },
  });
  expect(foreignAccountResponse.ok()).toBeTruthy();
  const foreignAccountId = (await foreignAccountResponse.json()).id;

  const accounts = await page.evaluate(async () => (await apiGet(`state&year=${new Date().getFullYear()}`)).accounts);
  await page.evaluate((list) => { state.accounts = list; }, accounts);

  const before = await page.evaluate(async () => (await apiGet(`state&year=${new Date().getFullYear()}`)));

  await page.getByRole('button', { name: 'Month', exact: true }).click();
  await page.locator('#accountsDetails > summary').click();
  await page.getByRole('button', { name: 'Import statement' }).click();

  await page.locator('#statementFile').setInputFiles(foreignTmp.filepath);
  await page.locator('#statementAccountChoice').selectOption(String(foreignAccountId));
  await page.getByRole('button', { name: 'Preview' }).click();

  await expect(page.locator('#statementPreview')).toBeVisible();
  // Self-owned assertions: the preview surface exposes the same parsed
  // metadata the real path extracts through the saved neutral profile.
  expect(await page.evaluate(() => state.statementPreview.import_profile_name)).toBe('Test Foreign Bank');
  await expect(page.locator('#statementPreviewFileAccountNumber')).toHaveText(foreignAccountNumber);
  await expect(page.locator('#statementPreviewRows')).toHaveText(String(foreignRows.length));
  await expect(page.locator('#statementPreviewNew')).toHaveText(String(foreignRows.length));
  await expect(page.locator('#statementPreviewKnown')).toHaveText('0');
  await expect(page.locator('#statementPreviewAccount')).toHaveText('Test Bank UI ' + suffix);
  await expect(page.locator('#statementAccountChoiceSection')).toBeVisible();
  expect(pageErrors, 'Unexpected page errors during preview').toEqual([]);
  expect(consoleErrors, 'Unexpected console errors during preview').toEqual([]);

  const foreignAccountIdOnPage = await page.evaluate((id) => id, foreignAccountId);
  await page.getByRole('button', { name: 'Import evidence' }).click();
  await expect(page.locator('#statementImportMessage')).toContainText('Evidence imported');

  const recon = await page.evaluate(async (accountId) => {
    const data = await apiPost({ action: 'loadStatementReconciliation', account_id: accountId });
    return data.state;
  }, foreignAccountIdOnPage);
  expect(recon.unresolved.length).toBeGreaterThan(0);
  expect(recon.unresolved[0].currency).toBe('EUR');
  expect(Math.abs(Number(recon.unresolved[0].amount))).toBeGreaterThan(0);
  expect(recon.unresolved[0].id).toBeGreaterThan(0);

  const after = await page.evaluate(async () => (await apiGet(`state&year=${new Date().getFullYear()}`)));
  expect(after.transactions).toEqual(before.transactions);
  expect(after.movements).toEqual(before.movements);
  expect(after.accounts).toEqual(before.accounts);
  expect(after.envelopes).toEqual(before.envelopes);
  expect(after.entries).toEqual(before.entries);

  // Clean up the synthetic temp file even if the test fails before this
  // line (Playwright would also clean it via the tmp dir lifetime, but
  // removing it explicitly keeps the failure diagnostic tidy).
  try { fs.rmSync(foreignTmp.dir, { recursive: true, force: true }); } catch {}
});

test('Statement pre-header Account identifier stays informational until explicit selection', async ({ page }) => {
  const pageErrors = [];
  page.on('pageerror', (error) => pageErrors.push(error.message));
  const consoleErrors = [];
  page.on('console', (msg) => { if (msg.type() === 'error') consoleErrors.push(msg.text()); });

  await openAuthenticatedApp(page);

  // Synthetic statement with an invented partial/domestic Account identifier.
  const suffix = 'cfnosel-' + Date.now().toString(36) + '-' + Math.floor(Math.random() * 1e6).toString(36);
  const foreignAccountNumber = '0001' + Date.now().toString() + String(Math.floor(Math.random() * 1e6)).padStart(6, '0');
  const foreignBytes = buildSyntheticDebitCreditPreheaderXlsx({
    accountNumber: foreignAccountNumber,
    currency: 'EUR',
    rows: [
      { booking_date: '2026-05-01', amount: -100, description: 'Synthetic Test Bank preview row', counterparty: 'Synthetic counterparty' },
    ],
  });
  const foreignTmp = writeTempXlsx('fin-cf-preview', foreignBytes);

  const foreignAccountResponse = await page.request.post('/api.php', {
    headers: { 'content-type': 'application/json' },
    data: {
      action: 'saveAccount',
      account: {
        name: 'Test Bank UI NoSelection ' + suffix,
        account_number: 'TEST-BANK-NOSEL-' + suffix,
        currency: 'CURRENCY',
      },
    },
  });
  expect(foreignAccountResponse.ok()).toBeTruthy();
  const foreignAccountId = (await foreignAccountResponse.json()).id;
  const accounts = await page.evaluate(async () => (await apiGet(`state&year=${new Date().getFullYear()}`)).accounts);
  await page.evaluate((list) => { state.accounts = list; }, accounts);

  await page.getByRole('button', { name: 'Month', exact: true }).click();
  await page.locator('#accountsDetails > summary').click();
  await page.getByRole('button', { name: 'Import statement' }).click();

  await page.locator('#statementFile').setInputFiles(foreignTmp.filepath);
  await expect(page.locator('#statementAccountChoice')).toHaveValue('');
  await page.getByRole('button', { name: 'Preview' }).click();
  await expect(page.locator('#statementImportMessage')).toHaveText('Select an Account.');
  await expect(page.locator('#statementPreview')).toBeHidden();

  await page.locator('#statementAccountChoice').selectOption(String(foreignAccountId));
  await page.getByRole('button', { name: 'Preview' }).click();
  await expect(page.locator('#statementPreview')).toBeVisible();
  await expect(page.locator('#statementPreviewAccount')).toHaveText('Test Bank UI NoSelection ' + suffix);
  await expect(page.locator('#statementPreviewFileAccountNumber')).toHaveText(foreignAccountNumber);
  expect(pageErrors, 'Unexpected page errors').toEqual([]);
  expect(consoleErrors, 'Unexpected console errors').toEqual([]);

  try { fs.rmSync(foreignTmp.dir, { recursive: true, force: true }); } catch {}
});

test('Accounts list shows the identification columns and hides the tracking-since date', async ({ page }) => {
  await openAuthenticatedApp(page);
  await page.getByRole('button', { name: 'Month', exact: true }).click();
  await page.locator('#accountsDetails > summary').click();
  const list = page.locator('#accountsList');
  await expect(list).toBeVisible();
  // The seeded accounts must each show name, IBAN, and Currency.
  await expect(list).toContainText('Test Bank');
  await expect(list).toContainText('TEST-BANK-');
  await expect(list).toContainText('Test Wallet');
  await expect(list).toContainText('TEST-WALLET-');
  await expect(list).toContainText('Test Card');
  await expect(list).toContainText('TEST-CARD-');
  // Each row shows a Balance (a non-empty position string, e.g. a sign and amount).
  for (const row of await page.locator('.managed-account-row').all()) {
    await expect(row).not.toContainText('tracking since');
  }
  // The list itself must not contain the legacy phrase.
  await expect(list).not.toContainText('tracking since');
  await expect(list).not.toContainText('Tracking since');
});
