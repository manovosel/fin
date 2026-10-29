'use strict';

const { test, expect } = require('./fixtures.cjs');
const { openAuthenticatedApp } = require('./auth.cjs');

// Regression coverage for the Account history Delete button.
//
// The Delete button on a Balance observation row renders a
// `.button-spinner` child so it can show a legitimate pending state
// while the delete request runs. In its idle state that spinner must
// stay hidden (text "Delete" only): it used to be forced visible by
// the Balance history Event-cell layout rule, so the button rendered a
// permanently spinning icon before anybody clicked it.
async function openAccountWithObservation(page, testInfo) {
  const suffix = testInfo.project.name.includes('mobile') ? 'mobile' : 'desktop';
  const accountName = `Account History Delete ${suffix}`;
  await page.getByRole('button', { name: 'Month', exact: true }).click();
  const accountsDetails = page.locator('#accountsDetails');
  await accountsDetails.locator(':scope > summary').click();
  await accountsDetails.getByRole('button', { name: 'Add Account' }).click();
  await page.locator('#accountName').fill(accountName);
  await page.locator('#accountNumber').fill(`DELETE-BTN-${suffix}`);
  // An initial Balance creates an `account_creation` observation, so
  // the Account history shows a Balance observation row with a Delete
  // button.
  await page.locator('#accountInitialAmount').fill('1200');
  await page.locator('#accountForm').getByRole('button', { name: 'Add account' }).click();
  await expect(page.locator('#accountsList')).toContainText(accountName);
  return accountName;
}

function accountHistoryDeleteButton(page) {
  return page.locator('#accountHistory button', { hasText: 'Delete' }).first();
}

async function spinnerState(locator) {
  return locator.evaluate((button) => {
    const spinner = button.querySelector('.button-spinner');
    if (!spinner) return { missing: true };
    const style = getComputedStyle(spinner);
    return {
      display: style.display,
      animationName: style.animationName,
      text: button.textContent.trim(),
      isLoading: button.classList.contains('is-loading'),
      busy: button.getAttribute('aria-busy'),
    };
  });
}

async function openAccountDialog(page, accountName) {
  await page.locator('.managed-account-row').filter({ hasText: accountName }).click();
  await expect(page.locator('#accountDialogTitle')).toHaveText('Edit account');
  await expect(page.locator('#accountHistory')).toContainText('Initial Balance');
}

test.describe('Account history Delete button', () => {
  test.beforeEach(async ({ page }) => {
    await openAuthenticatedApp(page);
    await expect(page.locator('#safeEnvelopeRows')).toContainText('Envelope A');
  });

  test('shows no spinner before it is clicked, also after reopening the modal and reloading', { tag: '@responsive' }, async ({ page }, testInfo) => {
    test.setTimeout(60_000);
    const accountName = await openAccountWithObservation(page, testInfo);

    await openAccountDialog(page, accountName);
    const deleteButton = accountHistoryDeleteButton(page);
    await expect(deleteButton).toBeVisible();
    await expect(deleteButton).toHaveText('Delete');

    let state = await spinnerState(deleteButton);
    expect(state.missing, 'the Delete button keeps its spinner element for the pending state').toBeFalsy();
    expect(state.display, 'idle spinner must not be rendered').toBe('none');
    expect(state.isLoading, 'idle button is not busy').toBe(false);
    expect(state.busy, 'idle button is not aria-busy').toBeNull();
    await expect(deleteButton.locator('.button-spinner')).toBeHidden();

    // Reopening the same Account renders the history again.
    await page.keyboard.press('Escape');
    await expect(page.locator('#accountDialog')).not.toBeVisible();
    await openAccountDialog(page, accountName);
    state = await spinnerState(accountHistoryDeleteButton(page));
    expect(state.display, 'reopened modal: idle spinner must not be rendered').toBe('none');

    // Reloading the page and opening the Account again must behave the same.
    await page.keyboard.press('Escape');
    await page.reload();
    await expect(page.locator('#appView')).toBeVisible();
    await page.getByRole('button', { name: 'Month', exact: true }).click();
    await page.locator('#accountsDetails').locator(':scope > summary').click();
    await openAccountDialog(page, accountName);
    state = await spinnerState(accountHistoryDeleteButton(page));
    expect(state.display, 'after reload: idle spinner must not be rendered').toBe('none');
    await expect(page.locator('#accountHistory .button-spinner:visible')).toHaveCount(0);
  });

  test('shows the spinner only while the delete request is pending', { tag: '@responsive' }, async ({ page }, testInfo) => {
    test.setTimeout(60_000);
    const accountName = await openAccountWithObservation(page, testInfo);

    let release;
    const holdPromise = new Promise((resolve) => { release = resolve; });
    let held = 0;
    await page.route('**/api.php', async (route) => {
      const request = route.request();
      if (request.method() !== 'POST') return route.continue();
      let payload = {};
      try { payload = request.postDataJSON() || {}; } catch { return route.continue(); }
      if (payload.action !== 'deleteAccountBalanceObservation') return route.continue();
      held += 1;
      await holdPromise;
      // Perform the real request once released so the API answer is the
      // production one.
      const response = await route.fetch();
      return route.fulfill({ response });
    });
    page.on('dialog', (dialog) => dialog.accept());

    await openAccountDialog(page, accountName);
    const deleteButton = accountHistoryDeleteButton(page);
    expect((await spinnerState(deleteButton)).display, 'idle spinner must not be rendered').toBe('none');

    await deleteButton.click();
    await expect.poll(() => held, { timeout: 10_000 }).toBe(1);

    const pending = await spinnerState(deleteButton);
    expect(pending.isLoading, 'the pending button carries the busy state').toBe(true);
    expect(pending.busy, 'the pending button is aria-busy').toBe('true');
    expect(pending.display, 'the pending spinner is rendered').toBe('inline-block');

    release();
    await expect(page.locator('#toast')).toHaveText('Balance observation deleted');
    // The deleted observation leaves the Account history, so no spinner
    // can survive the completed request either.
    await expect(page.locator('#accountHistory button', { hasText: 'Delete' })).toHaveCount(0);
    await expect(page.locator('#accountHistory .button-spinner:visible')).toHaveCount(0);
  });
});
