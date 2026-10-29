const { test, expect } = require('./fixtures.cjs');
const { openAuthenticatedApp } = require('./auth.cjs');
const cp = require('node:child_process');

function runDatabasePhp(finWorker, source) {
  const result = cp.spawnSync('php', ['-r', source], {
    cwd: finWorker.codeDir,
    env: { ...process.env, FIN_DB_FILE: finWorker.dbPath },
    encoding: 'utf8',
  });
  expect(result.status).toBe(0);
  return (result.stdout || '').trim();
}

// An imported statement row that would repeat an existing Transaction exactly
// - the same date, Envelope, amount and description - is reported to the owner
// instead of being reconciled silently. The owner confirms it, because
// identical same-day entries are sometimes legitimate.
test('a repeated statement row asks for confirmation before it is reconciled', async ({ page, finWorker }) => {
  test.setTimeout(60_000);
  const warnings = [];
  page.on('dialog', async (dialog) => {
    warnings.push(dialog.message());
    await dialog.accept();
  });

  await openAuthenticatedApp(page);
  const suffix = `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`;
  const description = `Repeated import ${suffix}`;
  const year = new Date().getFullYear();

  const accountResponse = await page.request.post('/api.php', {
    headers: { 'content-type': 'application/json' },
    data: { action: 'saveAccount', year, account: { name: `Statement Account ${suffix}`, account_number: `STATEMENT-${suffix}`, currency: 'CURRENCY' } },
  });
  expect(accountResponse.ok()).toBeTruthy();
  const accountId = Number((await accountResponse.json()).id);

  const envelopeResponse = await page.request.post('/api.php', {
    headers: { 'content-type': 'application/json' },
    data: { action: 'saveEnvelope', year, envelope: { name: `Statement Envelope ${suffix}`, role: 'daily', currency: 'CURRENCY', visibility: 'normal' } },
  });
  expect(envelopeResponse.ok()).toBeTruthy();
  const envelopeId = Number((await envelopeResponse.json()).id);

  // The existing Transaction has no payment Account, so the statement row
  // cannot be linked to it and stays an exception for the owner.
  const transactionResponse = await page.request.post('/api.php', {
    headers: { 'content-type': 'application/json' },
    data: { action: 'createTransaction', year, transaction: { envelope_id: envelopeId, amount: 25, spent_date: '2026-09-02', note: description } },
  });
  expect(transactionResponse.ok()).toBeTruthy();

  runDatabasePhp(finWorker, `
    $db = new PDO('sqlite:' . getenv('FIN_DB_FILE'));
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA foreign_keys=ON');
    $insertImport = $db->prepare("INSERT INTO statement_imports
      (account_id,provider,original_filename,file_checksum,parser_version,row_count,valid_row_count,problem_count)
      VALUES (:account_id,'test_provider','duplicate.xlsx',:checksum,'2',1,1,0)");
    $insertImport->execute([':account_id' => ${accountId}, ':checksum' => hash('sha256', '${suffix}|import')]);
    $insertRow = $db->prepare("INSERT INTO statement_rows
      (import_id,account_id,provider,source_row_number,booking_date,value_date,amount,currency,
       description_original,description_normalized,row_fingerprint,occurrence_index,parse_problem,raw_fields_json)
      VALUES (:import_id,:account_id,'test_provider',1,'2026-09-02','2026-09-02',-25,'DKK',
       :description,:normalized,:fingerprint,0,'','{}')");
    $insertRow->execute([
      ':import_id' => (int)$db->lastInsertId(), ':account_id' => ${accountId},
      ':description' => '${description}', ':normalized' => strtolower('${description}'),
      ':fingerprint' => hash('sha256', '${suffix}|row'),
    ]);
  `);

  // Reload so the freshly created Envelope is part of the client state the
  // Reconcile dialog renders its classification options from.
  await page.reload();
  await expect(page.locator('#appView')).toBeVisible();

  const unresolved = await page.evaluate(async (statementAccountId) => {
    const data = await apiPost({ action: 'loadStatementReconciliation', account_id: statementAccountId });
    state.reconciliation = { account_id: data.account.id, account_name: data.account.name, pending: {}, ...data.state };
    renderReconciliationState();
    document.querySelector('#reconciliationDialog').showModal();
    return data.state.unresolved;
  }, accountId);

  expect(unresolved).toHaveLength(1);
  const rowId = Number(unresolved[0].id);

  await page.locator(`[data-exception-classification="${rowId}"]`).selectOption(`envelope:${envelopeId}`);
  await page.locator('#saveReconciliationExceptionsBtn').click();

  await expect.poll(() => warnings.length).toBe(1);
  expect(warnings[0]).toContain('already have');
  expect(warnings[0]).toContain(description);

  await expect.poll(async () => page.evaluate(async (statementAccountId) => {
    const data = await apiPost({ action: 'loadStatementReconciliation', account_id: statementAccountId });
    return data.state.unresolved.length;
  }, accountId)).toBe(0);

  const transactionCount = await page.evaluate(async (text) => {
    const response = await fetch(`api.php?action=state&year=${new Date().getFullYear()}`);
    const data = await response.json();
    return data.transactions.filter((transaction) => transaction.note === text).length;
  }, description);
  expect(transactionCount).toBe(2);
});
