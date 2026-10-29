const { test, expect } = require('./fixtures.cjs');
const { openAuthenticatedApp } = require('./auth.cjs');

test('unordered UI collections use Danish alphabetical display order without changing Add Expense priority', async ({ page }) => {
  await openAuthenticatedApp(page);

  const ordering = await page.evaluate(() => {
    state.accounts = [
      { id: 101, name: 'Zulu Account', active: 1, currency: 'CURRENCY' },
      { id: 102, name: 'Alpha Account', active: 1, currency: 'CURRENCY' },
      { id: 103, name: 'Æble Account', active: 1, currency: 'CURRENCY' },
    ];
    const envelope = (id, name, defaultAccountId, usageCount, visibility = 'normal') => ({
      id,
      name,
      default_account_id: defaultAccountId,
      role: 'reserved',
      currency: 'CURRENCY',
      visibility,
      goal_visibility: 'hidden',
      savings_goal: 0,
      balance: 0,
      sort_order: id * 10,
      usage_count: usageCount,
    });
    state.envelopes = [
      envelope(201, 'Zulu Row', 101, 90),
      envelope(202, 'Alpha Row', 101, 10),
      envelope(203, 'Æble Row', 101, 50),
      envelope(204, 'Account Alpha Item', 102, 70),
      envelope(205, 'Account Æble Item', 103, 30),
      envelope(206, 'No Account Item', null, 20),
      envelope(207, 'Hidden Frequent', 102, 999, 'hidden'),
    ];
    state.entries = [];
    state.transactions = [];
    state.groupPrefs = {};

    renderEnvelopeManagementList();
    const managementNames = [...document.querySelectorAll('#envelopesManagementList .managed-account-row strong')]
      .map((node) => node.textContent.trim());

    const month = { id: 901, year: 2026, month: 9, salary: 0, exchange_rate: 7.5 };
    const planRows = monthSections(month, []).envelopePlan;
    document.querySelector('#months').innerHTML = renderFundingPlanSection(month, planRows);
    const accountHeaders = [...document.querySelectorAll('#months .account-header-envelope-allocations .account-label')]
      .map((node) => node.textContent.replace(/[▾▸]/g, '').trim());
    const zuluHeader = [...document.querySelectorAll('#months .account-header-envelope-allocations')]
      .find((node) => node.querySelector('.account-label')?.textContent.includes('Zulu Account'));
    const zuluEnvelopeNames = [];
    for (let row = zuluHeader?.nextElementSibling; row && !row.classList.contains('account-header') && !row.classList.contains('role-header'); row = row.nextElementSibling) {
      const name = row.querySelector('.envelope-name-button')?.textContent.trim();
      if (name) zuluEnvelopeNames.push(name);
    }

    const reviewItem = (id, name) => ({
      envelope_id: id,
      envelope_name: name,
      role: 'reserved',
      current_plan: 0,
      current_plan_status: 'planned',
      manual_baseline: null,
      amount: null,
      assessment: { code: 'none', label: 'Not enough history yet', source: 'none' },
      confidence_label: 'Insufficient',
      representative_months: 0,
      observations: [],
    });
    renderFundingReview({ recommendations: [
      reviewItem(301, 'Zulu Review'),
      reviewItem(302, 'Alpha Review'),
      reviewItem(303, 'Æble Review'),
    ] });
    const reviewNames = [...document.querySelectorAll('#fundingReviewContent .review-envelope-link')]
      .map((node) => node.textContent.trim());

    document.querySelector('#months').innerHTML = renderUncategorizedWarning([
      { id: 401, type: 'expense', status: 'planned', envelope_id: null, name: 'Zulu Legacy' },
      { id: 402, type: 'expense', status: 'planned', envelope_id: null, name: 'Alpha Legacy' },
      { id: 403, type: 'expense', status: 'planned', envelope_id: null, name: 'Æble Legacy' },
    ]);
    const legacyWarning = document.querySelector('#months .warning')?.textContent.trim();

    renderExpenseOptions();
    const expenseGroups = [...document.querySelectorAll('#expenseEnvelope optgroup')].map((group) => ({
      label: group.label,
      names: [...group.querySelectorAll('option')].map((option) => option.textContent.trim()),
    }));

    return { managementNames, accountHeaders, zuluEnvelopeNames, reviewNames, legacyWarning, expenseGroups };
  });

  expect(ordering.managementNames).toEqual([
    'Account Alpha Item',
    'Account Æble Item',
    'Alpha Row',
    'Hidden Frequent',
    'No Account Item',
    'Zulu Row',
    'Æble Row',
  ]);
  expect(ordering.accountHeaders).toEqual(['Alpha Account', 'No Account', 'Zulu Account', 'Æble Account']);
  expect(ordering.zuluEnvelopeNames).toEqual(['Alpha Row', 'Zulu Row', 'Æble Row']);
  expect(ordering.reviewNames).toEqual(['Alpha Review', 'Zulu Review', 'Æble Review']);
  expect(ordering.legacyWarning).toBe('You have 3 non-categorized expense entries without envelope: Alpha Legacy, Zulu Legacy, Æble Legacy');

  expect(ordering.expenseGroups).toEqual([
    {
      label: 'Visible envelopes',
      names: ['Zulu Row', 'Account Alpha Item', 'Æble Row', 'Account Æble Item', 'No Account Item', 'Alpha Row'],
    },
    { label: 'Out of sight envelopes', names: ['Hidden Frequent'] },
  ]);
});
