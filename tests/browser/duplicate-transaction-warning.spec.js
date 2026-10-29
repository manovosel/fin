const { test, expect } = require('./fixtures.cjs');
const { openAuthenticatedApp } = require('./auth.cjs');

function isoToday() {
  const now = new Date();
  const month = String(now.getMonth() + 1).padStart(2, '0');
  const day = String(now.getDate()).padStart(2, '0');
  return `${now.getFullYear()}-${month}-${day}`;
}

// A repeated expense - the same date, Envelope, amount and description - is
// reported to the owner instead of being written silently. The owner confirms
// it, because identical same-day entries are sometimes legitimate.
test('a repeated expense asks for confirmation and is only written when confirmed', async ({ page }) => {
  test.setTimeout(60_000);
  const warnings = [];
  let answer = true;
  page.on('dialog', async (dialog) => {
    warnings.push(dialog.message());
    await (answer ? dialog.accept() : dialog.dismiss());
  });

  await openAuthenticatedApp(page);
  const suffix = `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`;
  const note = `Repeated expense ${suffix}`;
  const spentDate = isoToday();

  const addExpense = async () => {
    await page.getByRole('button', { name: 'Add expense' }).click();
    await expect(page.locator('#expenseDialog')).toBeVisible();
    await page.locator('#expenseAmount').fill('125,50');
    await page.locator('#expenseDate').fill(spentDate);
    await page.locator('#expenseNote').fill(note);
    await page.locator('#expenseForm').getByRole('button', { name: 'Save expense' }).click();
  };

  const countByNote = () => page.evaluate((text) => state.transactions.filter((transaction) => transaction.note === text).length, note);

  await addExpense();
  await expect(page.locator('#expenseDialog')).toBeHidden();
  expect(await countByNote()).toBe(1);
  expect(warnings).toEqual([]);

  // The second, identical expense is confirmed by the owner.
  await addExpense();
  await expect(page.locator('#expenseDialog')).toBeHidden();
  expect(warnings).toHaveLength(1);
  expect(warnings[0]).toContain('already exists');
  expect(warnings[0]).toContain(note);
  expect(await countByNote()).toBe(2);

  // The third, identical expense is declined and is never written.
  answer = false;
  await addExpense();
  await expect(page.locator('#expenseDialog')).toBeVisible();
  expect(warnings).toHaveLength(2);
  expect(await countByNote()).toBe(2);
});
