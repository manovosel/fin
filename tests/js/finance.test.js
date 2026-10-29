'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const Finance = require('../../finance.js');

test('remaining days includes today in the current month', () => {
  const today = new Date(2026, 1, 10);
  assert.equal(Finance.remainingDaysInMonth(2026, 2, today), 19);
});

test('another month uses all calendar days', () => {
  const today = new Date(2026, 1, 10);
  assert.equal(Finance.remainingDaysInMonth(2026, 1, today), 31);
  assert.equal(Finance.remainingDaysInMonth(2024, 2, today), 29);
});

test('plan amounts derive Helper presentation from one Currency amount', () => {
  assert.deepEqual(Finance.planAmounts(750, 'CURRENCY', 7.5), { amount: 750, helperAmount: 100, preference: 'CURRENCY' });
  assert.deepEqual(Finance.planAmounts(800, 'HELPER_CURRENCY', 8), { amount: 800, helperAmount: 100, preference: 'HELPER_CURRENCY' });
});

test('the same Currency amount has date-specific Helper presentation and no invented fallback', () => {
  assert.equal(Finance.planAmounts(800, 'HELPER_CURRENCY', 8).helperAmount, 100);
  assert.equal(Finance.planAmounts(800, 'HELPER_CURRENCY', 10).helperAmount, 80);
  assert.equal(Finance.planAmounts(800, 'HELPER_CURRENCY', 0).helperAmount, null);
});

test('month plan summary includes every non-skipped envelope allocation', () => {
  const month = { salary: 30000 };
  const entries = [
    { type: 'income', amount: 2000, status: 'planned' },
    { type: 'expense', envelope_id: 1, amount: 3000, status: 'paid' },
    { type: 'expense', envelope_id: 2, amount: 4000, status: 'planned' },
    { type: 'expense', envelope_id: 3, amount: 5000, status: 'planned' },
    { type: 'expense', envelope_id: 3, amount: 900, status: 'skipped' },
  ];

  assert.deepEqual(Finance.monthPlanSummary(month, entries), {
    income: 32000,
    allocations: 12000,
    balance: 20000,
  });
});

test('account Plan summary sums Envelope balances per Account and Plan/Funded per selected Month', () => {
  // FIN_BACKLOG HIGH #2: Plan summary's Account-group row Balance is the
  // sum of the assigned Envelopes' persistent balances, NOT the real
  // Account Balance. The real Account Balance is preserved in the
  // Accounts view. The Accounts above carry their real signed
  // Balance/balance_known fields only to confirm the function ignores
  // them and reads Envelope balances instead.
  const accounts = [
    { id: 1, name: 'Test Wallet', active: 1, currency: 'CURRENCY', balance_known: true, balance: 1000 },
    { id: 2, name: 'Test Bank', active: 1, currency: 'HELPER_CURRENCY', balance_known: true, balance: -700 },
    { id: 3, name: 'Archived', active: 0, currency: 'CURRENCY', balance_known: true, balance: 5000 },
    { id: 4, name: 'No Plan', active: 1, currency: 'CURRENCY', balance_known: false, balance: null },
  ];
  const envelopes = [
    { id: 1, default_account_id: 1, balance: 600 },
    { id: 2, default_account_id: 1, balance: 400 },
    { id: 3, default_account_id: 2, balance: -200 },
    { id: 4, default_account_id: 3, balance: 999 },
    { id: 5, default_account_id: null, balance: 300 },
  ];
  const entries = [
    { type: 'expense', envelope_id: 1, amount: 1000, status: 'planned' },
    { type: 'expense', envelope_id: 2, amount: 250, status: 'paid' },
    { type: 'expense', envelope_id: 3, amount: 500, status: 'paid' },
    { type: 'expense', envelope_id: 3, amount: 100, status: 'skipped' },
    { type: 'expense', envelope_id: 4, amount: 900, status: 'paid' },
    { type: 'expense', envelope_id: 5, amount: 800, status: 'paid' },
  ];

  // Archived Account is filtered out. Envelope 4 belongs to the archived
  // Account and is therefore ignored. Envelope 5 has no default_account_id
  // and is also ignored, preserving the existing behavior.
  // - Test Wallet: Envelopes 1+2 balances sum to 1000 (real Account
  //   Balance of 1000 coincidentally matches here; not asserted).
  // - Test Bank: Envelope 3 balance is -200 (real Account Balance is
  //   -700, deliberately different).
  // - No Plan: no Envelope assigned, Balance is 0.
  assert.deepEqual(Finance.accountPlanSummary(accounts, entries, envelopes), [
    { id: 4, name: 'No Plan', currency: 'CURRENCY', plan: 0, funded: 0, balance_known: true, balance: 0 },
    { id: 2, name: 'Test Bank', currency: 'HELPER_CURRENCY', plan: 500, funded: 500, balance_known: true, balance: -200 },
    { id: 1, name: 'Test Wallet', currency: 'CURRENCY', plan: 1250, funded: 250, balance_known: true, balance: 1000 },
  ]);
});

test('account Plan summary uses the Envelope balance sum, not the real Account Balance', () => {
  // Real Account Balance is 9999 (Unknown or arbitrary); Envelope sums
  // intentionally differ so the test would fail if Plan summary read
  // the real Account Balance by mistake.
  const accounts = [
    { id: 1, name: 'Real High', active: 1, currency: 'CURRENCY', balance_known: true, balance: 9999 },
  ];
  const envelopes = [
    { id: 11, name: 'Daily', role: 'daily', default_account_id: 1, balance: 600 },
    { id: 12, name: 'Reserved', role: 'reserved', default_account_id: 1, balance: -100 },
    { id: 13, name: 'Savings', role: 'savings', default_account_id: 1, balance: 200 },
  ];
  const entries = [];
  const rows = Finance.accountPlanSummary(accounts, entries, envelopes);
  assert.equal(rows.length, 1);
  assert.equal(rows[0].balance, 700);
});

test('account Plan summary sums two Envelopes on the same Account into one row Balance', () => {
  const accounts = [
    { id: 1, name: 'Joint', active: 1, currency: 'CURRENCY', balance_known: true, balance: 0 },
  ];
  const envelopes = [
    { id: 1, default_account_id: 1, balance: 250 },
    { id: 2, default_account_id: 1, balance: 175.5 },
  ];
  const rows = Finance.accountPlanSummary(accounts, [], envelopes);
  assert.equal(rows.length, 1);
  assert.equal(rows[0].balance, 425.5);
});

test('account Plan summary shows zero for an Account with no assigned Envelopes instead of inventing an Unknown state', () => {
  // The Account has no balance observation and no assigned Envelopes.
  // Plan summary no longer surfaces the old real-Account-Balance
  // "Unknown" state for this row: the Envelope sum is exactly zero.
  assert.deepEqual(Finance.accountPlanSummary([
    { id: 1, name: 'Empty', active: 1, currency: 'CURRENCY', balance_known: false, balance: null },
  ], [], []), [
    { id: 1, name: 'Empty', currency: 'CURRENCY', plan: 0, funded: 0, balance_known: true, balance: 0 },
  ]);
});

test('account Plan summary ignores Envelopes with no default_account_id for both Plan/Funded and Balance', () => {
  // An unassigned Envelope must not contribute to any Account row, in
  // either column. The selected-Month Plan/Funded of that Envelope is
  // also not added to any Account.
  const accounts = [
    { id: 1, name: 'Wallet', active: 1, currency: 'CURRENCY', balance_known: true, balance: 0 },
  ];
  const envelopes = [
    { id: 1, default_account_id: 1, balance: 100 },
    { id: 2, default_account_id: null, balance: 999 },
  ];
  const entries = [
    { type: 'expense', envelope_id: 2, amount: 50, status: 'paid' },
  ];
  const rows = Finance.accountPlanSummary(accounts, entries, envelopes);
  assert.equal(rows.length, 1);
  assert.equal(rows[0].plan, 0);
  assert.equal(rows[0].funded, 0);
  assert.equal(rows[0].balance, 100);
});

test('account Plan summary top Balance equals the sum of every Account-row Balance', () => {
  // Mirrors the renderer-level totals reduce in app.js so a regression
  // in either side is caught at the lowest level possible.
  const accounts = [
    { id: 1, name: 'Wallet', active: 1, currency: 'CURRENCY', balance_known: true, balance: 0 },
    { id: 2, name: 'Bank', active: 1, currency: 'CURRENCY', balance_known: true, balance: 0 },
    { id: 3, name: 'Card', active: 1, currency: 'CURRENCY', balance_known: true, balance: 0 },
  ];
  const envelopes = [
    { id: 1, default_account_id: 1, balance: 100 },
    { id: 2, default_account_id: 2, balance: 250 },
    { id: 3, default_account_id: 2, balance: -50 },
    { id: 4, default_account_id: null, balance: 999 },
  ];
  const rows = Finance.accountPlanSummary(accounts, [], envelopes);
  const topBalance = rows.reduce((sum, row) => sum + row.balance, 0);
  assert.equal(topBalance, 300);
});

test('savings goal progress shows motivation without capping the percentage', () => {
  assert.deepEqual(Finance.goalProgress(5000, 10000), {
    percent: 50,
    displayPercent: 50,
    fill: 50,
    reached: false,
  });
  assert.deepEqual(Finance.goalProgress(10850, 10000), {
    percent: 108.5,
    displayPercent: 108,
    fill: 100,
    reached: true,
  });
  assert.equal(Finance.goalProgress(-50, 10000).displayPercent, 0);
});

test('safe to spend is conservatively rounded down to ten Currency units', () => {
  const result = Finance.safeToSpend(1000, 2026, 2, new Date(2026, 1, 10));
  assert.equal(result.remainingDays, 19);
  assert.equal(result.amount, 50);
  assert.equal(result.label, 'safe to spend today');
});

test('negative daily balance shows zero and over plan', () => {
  const result = Finance.safeToSpend(-100, 2026, 2, new Date(2026, 1, 10));
  assert.equal(result.amount, 0);
  assert.equal(result.label, 'over plan');
});

test('small positive amount that rounds to zero has no caption', () => {
  const result = Finance.safeToSpend(9, 2026, 2, new Date(2026, 1, 10));
  assert.equal(result.amount, 0);
  assert.equal(result.label, '');
});

test('the current month figure matches the rule the history API reports', () => {
  // Same fixture as tests/php/HistorySafeToSpendTest.php: 3500 spread over the
  // 18 days that remain in August 2026 from the 14th.
  const result = Finance.safeToSpend(3500, 2026, 8, new Date(2026, 7, 14));
  assert.equal(result.remainingDays, 18);
  assert.equal(result.rawPerDay, 3500 / 18);
  assert.equal(result.amount, 190);
  assert.equal(result.label, 'safe to spend today');
});

test('current month status evaluates Daily spend as one aggregate pool', () => {
  const month = { id: 1, year: 2026, month: 8 };
  const envelopes = [
    { id: 1, name: 'Envelope A', role: 'daily', balance: -100 },
    { id: 2, name: 'Fun', role: 'daily', balance: 1100 },
    { id: 3, name: 'Dues', role: 'reserved', balance: 0 },
    { id: 4, name: 'Savings', role: 'savings', balance: 500 },
  ];
  const entries = [
    { month_id: 1, envelope_id: 3, type: 'expense', amount: 700, status: 'paid' },
    { month_id: 1, envelope_id: 4, type: 'expense', amount: 500, status: 'paid' },
  ];
  const transactions = [
    { month_id: 1, envelope_id: 1, amount: 100, spent_date: '2026-08-05' },
    { month_id: 1, envelope_id: 3, amount: 5000, spent_date: '2026-08-05' },
  ];

  const result = Finance.monthStatus(month, entries, transactions, envelopes, new Date(2026, 7, 10));
  assert.equal(result.state, 'on-track');
  assert.equal(result.daily.remaining, 1000);
  assert.equal(result.daily.spent, 100);
  assert.equal(result.daily.safe.amount, 40);
  assert.equal(result.reserved.funded, 700);
  assert.equal(result.savings.funded, 500);
});

test('current month status gets tight with an unsustainable Daily spend pace and needs attention when the pool is exhausted', () => {
  const month = { id: 1, year: 2026, month: 8 };
  const transactions = [{ month_id: 1, envelope_id: 1, amount: 1000, spent_date: '2026-08-05' }];
  const tight = Finance.monthStatus(month, [], transactions, [{ id: 1, role: 'daily', balance: 100 }], new Date(2026, 7, 10));
  const negative = Finance.monthStatus(month, [], transactions, [
    { id: 1, role: 'daily', balance: -500 },
    { id: 2, role: 'daily', balance: 499 },
  ], new Date(2026, 7, 10));

  assert.equal(tight.state, 'getting-tight');
  assert.equal(negative.state, 'needs-attention');
  assert.equal(negative.daily.remaining, -1);
});

test('a low safe-to-spend amount alone does not make the current month tight', () => {
  const result = Finance.monthStatus(
    { id: 1, year: 2026, month: 8 },
    [],
    [],
    [{ id: 1, role: 'daily', balance: 100 }],
    new Date(2026, 7, 10),
  );

  assert.equal(result.daily.safe.amount, 0);
  assert.equal(result.daily.paceRatio > 0.75, true);
  assert.equal(result.state, 'on-track');
});

test('current Daily spend pace uses 0.75 as an inclusive on-track boundary', () => {
  const month = { id: 1, year: 2026, month: 8 };
  const monthRemainingFraction = 21 / 31;
  const boundaryRemaining = 1000 * 0.75 * monthRemainingFraction;
  const atBoundary = Finance.monthStatus(
    month,
    [],
    [{ month_id: 1, envelope_id: 1, amount: 1000 - boundaryRemaining, spent_date: '2026-08-05' }],
    [{ id: 1, role: 'daily', balance: boundaryRemaining }],
    new Date(2026, 7, 11),
  );
  const belowBoundary = Finance.monthStatus(
    month,
    [],
    [{ month_id: 1, envelope_id: 1, amount: 1000 - boundaryRemaining + 0.01, spent_date: '2026-08-05' }],
    [{ id: 1, role: 'daily', balance: boundaryRemaining - 0.01 }],
    new Date(2026, 7, 11),
  );

  assert.equal(Math.abs(atBoundary.daily.paceRatio - 0.75) < 1e-12, true);
  assert.equal(atBoundary.state, 'on-track');
  assert.equal(belowBoundary.daily.paceRatio < 0.75, true);
  assert.equal(belowBoundary.state, 'getting-tight');
});

test('current August data is tight because money is being consumed faster than the month', () => {
  const result = Finance.monthStatus(
    { id: 1, year: 2026, month: 8 },
    [{ month_id: 1, envelope_id: 1, type: 'expense', amount: 1200, status: 'paid' }],
    [{ month_id: 1, envelope_id: 1, amount: 745.9, spent_date: '2026-08-05' }],
    [
      { id: 1, role: 'daily', balance: -345.9 },
      { id: 2, role: 'daily', balance: 400 },
      { id: 3, role: 'daily', balance: 400 },
    ],
    new Date(2026, 7, 11),
  );

  assert.equal(result.daily.pool, 1200);
  assert.equal(Math.abs(result.daily.moneyRemainingFraction - (454.1 / 1200)) < 1e-12, true);
  assert.equal(result.daily.monthRemainingFraction, 21 / 31);
  assert.equal(result.daily.paceRatio < 0.75, true);
  assert.equal(result.daily.safe.amount, 20);
  assert.equal(result.state, 'getting-tight');
});

test('month status uses signed Refund spending without clamping the Daily pool', () => {
  const month = { id: 1, year: 2026, month: 8 };
  const today = new Date(2026, 7, 10);
  const expenseAndPartialRefund = Finance.monthStatus(month, [], [
    { envelope_id: 1, amount: 100, kind: 'expense', spent_date: '2026-08-05' },
    { envelope_id: 1, amount: 40, kind: 'refund', spent_date: '2026-08-06' },
  ], [{ id: 1, role: 'daily', balance: 440 }], today);
  const overRefund = Finance.monthStatus(month, [], [
    { envelope_id: 1, amount: 100, kind: 'expense', spent_date: '2026-08-05' },
    { envelope_id: 1, amount: 150, kind: 'refund', spent_date: '2026-08-06' },
  ], [{ id: 1, role: 'daily', balance: 550 }], today);
  const refundOnly = Finance.monthStatus(month, [], [
    { envelope_id: 1, amount: 50, kind: 'refund', spent_date: '2026-08-06' },
  ], [{ id: 1, role: 'daily', balance: 450 }], today);

  assert.equal(expenseAndPartialRefund.daily.spent, 60);
  assert.equal(expenseAndPartialRefund.daily.pool, 500);
  assert.equal(expenseAndPartialRefund.daily.moneyRemainingFraction, 0.88);
  assert.equal(overRefund.daily.spent, -50);
  assert.equal(overRefund.daily.pool, 500);
  assert.equal(overRefund.daily.moneyRemainingFraction, 1.1);
  assert.equal(refundOnly.daily.spent, -50);
  assert.equal(refundOnly.daily.pool, 400);
  assert.equal(refundOnly.daily.moneyRemainingFraction, 1.125);
  assert.equal(overRefund.state, 'on-track');
  assert.equal(refundOnly.state, 'on-track');
});

test('zero current Daily balance needs attention before the final day but is on track on the final day', () => {
  const month = { id: 1, year: 2026, month: 8 };
  const entries = [];
  const transactions = [{ month_id: 1, envelope_id: 1, amount: 1200, spent_date: '2026-08-05' }];
  const zeroEnvelope = [{ id: 1, role: 'daily', balance: 0 }];
  const negativeEnvelope = [{ id: 1, role: 'daily', balance: -0.01 }];

  assert.equal(Finance.monthStatus(month, entries, transactions, zeroEnvelope, new Date(2026, 7, 30)).state, 'needs-attention');
  assert.equal(Finance.monthStatus(month, entries, transactions, zeroEnvelope, new Date(2026, 7, 31)).state, 'on-track');
  assert.equal(Finance.monthStatus(month, entries, transactions, negativeEnvelope, new Date(2026, 7, 31)).state, 'needs-attention');
});

test('day-one lump spending has a proportional tolerance and zero-plan carryover remains evaluable', () => {
  const month = { id: 1, year: 2026, month: 8 };
  const atTolerance = Finance.monthStatus(month, [], [{ month_id: 1, envelope_id: 1, amount: 250, spent_date: '2026-08-01' }], [{ id: 1, role: 'daily', balance: 750 }], new Date(2026, 7, 1));
  const beyondTolerance = Finance.monthStatus(month, [], [{ month_id: 1, envelope_id: 1, amount: 250.01, spent_date: '2026-08-01' }], [{ id: 1, role: 'daily', balance: 749.99 }], new Date(2026, 7, 1));
  const carryover = Finance.monthStatus(month, [], [], [{ id: 1, role: 'daily', balance: 400 }], new Date(2026, 7, 11));
  const noDailyEnvelopes = Finance.monthStatus(month, [], [], [{ id: 2, role: 'reserved', balance: 0 }], new Date(2026, 7, 11));

  assert.equal(atTolerance.state, 'on-track');
  assert.equal(beyondTolerance.state, 'getting-tight');
  assert.equal(carryover.state, 'on-track');
  assert.equal(noDailyEnvelopes.state, 'on-track');
});

test('overdue funding matters while normal Reserved spending does not', () => {
  const month = { id: 1, year: 2026, month: 8 };
  const envelopes = [
    { id: 1, name: 'Daily', role: 'daily', balance: 2000 },
    { id: 2, name: 'Dues', role: 'reserved', balance: 0 },
  ];
  const entries = [{ month_id: 1, envelope_id: 2, type: 'expense', amount: 700, status: 'planned', due_date: '2026-08-01' }];
  const transactions = [{ month_id: 1, envelope_id: 2, amount: 900, spent_date: '2026-08-05' }];

  const result = Finance.monthStatus(month, entries, transactions, envelopes, new Date(2026, 7, 10));
  assert.equal(result.state, 'needs-attention');
  assert.equal(result.reserved.overdue, 700);
  assert.equal(result.daily.spent, 0);
});

test('past month status compares Daily plan to transactions and contribution funding to plan', () => {
  const month = { id: 1, year: 2026, month: 7 };
  const envelopes = [
    { id: 1, name: 'Daily', role: 'daily', balance: 0 },
    { id: 2, name: 'Dues', role: 'reserved', balance: 0 },
    { id: 3, name: 'Savings', role: 'savings', balance: 0 },
  ];
  const entries = [
    { month_id: 1, envelope_id: 1, type: 'expense', amount: 1000, status: 'paid' },
    { month_id: 1, envelope_id: 2, type: 'expense', amount: 700, status: 'paid' },
    { month_id: 1, envelope_id: 3, type: 'expense', amount: 500, status: 'planned' },
  ];
  const transactions = [
    { month_id: 1, envelope_id: 1, amount: 1200, spent_date: '2026-07-05' },
    { month_id: 1, envelope_id: 2, amount: 5000, spent_date: '2026-07-05' },
  ];

  const result = Finance.monthStatus(month, entries, transactions, envelopes, new Date(2026, 7, 10));
  assert.equal(result.period, 'past');
  assert.equal(result.state, 'needs-attention');
  assert.equal(result.daily.difference, -200);
  assert.equal(result.reserved.state, 'on-track');
  assert.equal(result.savings.pending, 500);
});

test('month status buckets actual spending by spent date and excludes future transactions', () => {
  const envelopes = [{ id: 1, name: 'Daily', role: 'daily', balance: 500 }];
  const scheduled = [{ month_id: 8, envelope_id: 1, amount: 300, spent_date: '2026-09-01' }];
  const august = Finance.monthStatus({ id: 8, year: 2026, month: 8 }, [], scheduled, envelopes, new Date(2026, 7, 14));
  assert.equal(august.daily.spent, 0);

  const september = Finance.monthStatus({ id: 9, year: 2026, month: 9 }, [], scheduled, envelopes, new Date(2026, 8, 2));
  assert.equal(september.daily.spent, 300);
});

test('month status ignores incomplete transactions without spent date instead of using source month', () => {
  const result = Finance.monthStatus(
    { id: 1, year: 2026, month: 8 },
    [],
    [{ month_id: 1, envelope_id: 1, amount: 900 }],
    [{ id: 1, name: 'Daily', role: 'daily', balance: 1000 }],
    new Date(2026, 7, 10),
  );
  assert.equal(result.daily.spent, 0);
});

test('future month status is not evaluated', () => {
  const result = Finance.monthStatus({ id: 1, year: 2026, month: 9 }, [], [], [], new Date(2026, 7, 10));
  assert.equal(result.state, 'not-started');
  assert.equal(result.label, 'Not started');
});
