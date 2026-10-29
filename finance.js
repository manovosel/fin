(function (root, factory) {
  const finance = factory();
  if (typeof module === 'object' && module.exports) module.exports = finance;
  root.Finance = finance;
}(typeof globalThis !== 'undefined' ? globalThis : this, function () {
  'use strict';

  // Experimental V1 UX tolerance, not a financial invariant.
  const CURRENT_MONTH_PACE_RATIO_THRESHOLD = 0.75;

  function daysInMonth(year, month) {
    return new Date(year, month, 0).getDate();
  }

  function remainingDaysInMonth(year, month, today = new Date()) {
    if (today.getFullYear() !== year || today.getMonth() + 1 !== month) {
      return daysInMonth(year, month);
    }
    return Math.max(1, daysInMonth(year, month) - today.getDate() + 1);
  }

  function planAmounts(amount, preference, exchangeRate) {
    const rate = Number(exchangeRate || 0);
    const value = Number(amount || 0);
    return { amount: value, helperAmount: rate > 0 ? value / rate : null, preference: preference === 'HELPER_CURRENCY' ? 'HELPER_CURRENCY' : 'CURRENCY' };
  }

  function monthPlanSummary(month, entries) {
    let income = Number(month?.salary || 0);
    let allocations = 0;
    for (const entry of entries || []) {
      if (entry.status === 'skipped') continue;
      const amount = Number(entry.amount || 0);
      if (entry.type === 'income') {
        income += amount;
      } else {
        allocations += amount;
      }
    }
    return { income, allocations, balance: income - allocations };
  }

  function accountPlanSummary(accounts, entries, envelopes) {
    // Plan summary semantics (FIN_BACKLOG HIGH #2):
    //   - Plan and Funded are selected-Month sums of non-skipped Plan rows
    //     whose Envelope's default_account_id matches the Account.
    //   - Balance is the sum of Envelope balances for Envelopes whose
    //     default_account_id matches the Account, NOT the real Account
    //     Balance. The real Account Balance remains visible in the
    //     Accounts view.
    // Envelopes without a default_account_id contribute to no Account
    // row, preserving the existing behavior for unassigned Envelopes.
    const currentAccounts = (accounts || []).filter((account) => Number(account.active || 0) === 1);
    const groups = new Map(currentAccounts.map((account) => [Number(account.id), {
      id: Number(account.id),
      name: String(account.name || ''),
      currency: account.currency === 'HELPER_CURRENCY' ? 'HELPER_CURRENCY' : 'CURRENCY',
      plan: 0,
      funded: 0,
      balance_known: true,
      balance: 0,
    }]));
    const envelopeById = new Map((envelopes || []).map((envelope) => [Number(envelope.id), envelope]));
    for (const envelope of envelopes || []) {
      const accountId = Number(envelope.default_account_id || 0);
      const group = groups.get(accountId);
      if (!group) continue;
      const balance = Number(envelope.balance || 0);
      if (Number.isFinite(balance)) group.balance += balance;
    }
    for (const entry of entries || []) {
      if (entry.type === 'income' || entry.status === 'skipped') continue;
      const envelope = envelopeById.get(Number(entry.envelope_id || 0));
      const group = groups.get(Number(envelope?.default_account_id || 0));
      if (!group) continue;
      const amount = Number(entry.amount || 0);
      group.plan += amount;
      if (entry.status === 'paid') group.funded += amount;
    }
    return [...groups.values()].sort((a, b) => a.name.localeCompare(b.name, 'da', { sensitivity: 'base' }));
  }

  function goalProgress(balance, goal) {
    const target = Number(goal || 0);
    const saved = Number(balance || 0);
    const percent = target > 0 ? Math.max(0, (saved / target) * 100) : 0;
    return {
      percent,
      displayPercent: Math.floor(percent),
      fill: Math.min(100, percent),
      reached: target > 0 && saved >= target,
    };
  }

  function safeToSpend(dailyBalance, year, month, today = new Date()) {
    const remainingDays = remainingDaysInMonth(year, month, today);
    const rawPerDay = Number(dailyBalance || 0) / remainingDays;
    const rounded = rawPerDay > 0 ? Math.floor(rawPerDay / 10) * 10 : 0;
    return {
      remainingDays,
      rawPerDay,
      amount: Math.max(0, rounded),
      label: rawPerDay < 0 ? 'over plan' : (rounded > 0 ? 'safe to spend today' : ''),
    };
  }

  function monthStatus(month, entries, transactions, envelopes, today = new Date()) {
    const roles = ['daily', 'reserved', 'savings'];
    const roleFor = (envelope) => roles.includes(envelope?.role) ? envelope.role : 'daily';
    const envelopeById = new Map((envelopes || []).map((envelope) => [Number(envelope.id), envelope]));
    const envelopeByName = new Map((envelopes || []).map((envelope) => [String(envelope.name || '').trim().toLocaleLowerCase(), envelope]));
    const selectedKey = (Number(month?.year || 0) * 12) + Number(month?.month || 0);
    const todayKey = (today.getFullYear() * 12) + today.getMonth() + 1;
    const period = selectedKey < todayKey ? 'past' : (selectedKey > todayKey ? 'future' : 'current');
    const todayIso = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
    const selectedMonthPrefix = `${month?.year}-${String(month?.month).padStart(2, '0')}`;
    const monthEntries = (entries || []).filter((entry) => Number(entry.month_id || month?.id || 0) === Number(month?.id || 0) && entry.type !== 'income');
    const monthTransactions = (transactions || []).filter((transaction) => {
      const spentDate = String(transaction.spent_date || '');
      if (!spentDate) return false;
      return spentDate.startsWith(selectedMonthPrefix) && spentDate <= todayIso;
    });
    const allocations = monthEntries.filter((entry) => entry.status !== 'skipped' && Number(entry.amount || 0) > 0);
    const roleTotals = Object.fromEntries(roles.map((role) => [role, { planned: 0, funded: 0, overdue: 0 }]));
    const defaultDueDate = `${month?.year}-${String(month?.month).padStart(2, '0')}-${String(daysInMonth(Number(month?.year), Number(month?.month))).padStart(2, '0')}`;

    for (const entry of allocations) {
      const envelope = envelopeById.get(Number(entry.envelope_id || 0));
      if (!envelope) continue;
      const role = roleFor(envelope);
      const amount = Number(entry.amount || 0);
      roleTotals[role].planned += amount;
      if (entry.status === 'paid') {
        roleTotals[role].funded += amount;
      } else if (period === 'current' && String(entry.due_date || defaultDueDate) <= todayIso) {
        roleTotals[role].overdue += amount;
      }
    }

    const spentByRole = Object.fromEntries(roles.map((role) => [role, 0]));
    for (const transaction of monthTransactions) {
      const envelope = envelopeById.get(Number(transaction.envelope_id || 0))
        || envelopeByName.get(String(transaction.envelope || '').trim().toLocaleLowerCase());
      if (!envelope) continue;
      const signed = Number(transaction.amount || 0);
      const effective = transaction.kind === 'refund' ? -Math.abs(signed) : Math.abs(signed);
      spentByRole[roleFor(envelope)] += effective;
    }

    const dailyEnvelopes = (envelopes || []).filter((envelope) => roleFor(envelope) === 'daily');
    const dailyRemaining = dailyEnvelopes.reduce((sum, envelope) => sum + Number(envelope.balance || 0), 0);
    const safe = safeToSpend(dailyRemaining, Number(month?.year), Number(month?.month), today);
    const dailyDifference = roleTotals.daily.planned - spentByRole.daily;
    const totalDays = daysInMonth(Number(month?.year), Number(month?.month));
    const dailyPool = dailyRemaining + spentByRole.daily;
    const moneyRemainingFraction = dailyPool > 0 ? dailyRemaining / dailyPool : 1;
    const monthRemainingFraction = safe.remainingDays / totalDays;
    const paceRatio = monthRemainingFraction > 0 ? moneyRemainingFraction / monthRemainingFraction : 1;
    let dailyState = 'on-track';
    if (period === 'current' && dailyEnvelopes.length) {
      if (dailyRemaining < 0) dailyState = 'needs-attention';
      else if (dailyRemaining === 0) dailyState = today.getDate() === totalDays ? 'on-track' : 'needs-attention';
      else if (paceRatio < CURRENT_MONTH_PACE_RATIO_THRESHOLD) dailyState = 'getting-tight';
    } else if (period === 'past' && dailyDifference < 0) {
      dailyState = 'needs-attention';
    }

    const reservedPending = Math.max(0, roleTotals.reserved.planned - roleTotals.reserved.funded);
    const savingsPending = Math.max(0, roleTotals.savings.planned - roleTotals.savings.funded);
    const reservedState = (period === 'past' && reservedPending > 0) || (period === 'current' && roleTotals.reserved.overdue > 0) ? 'needs-attention' : 'on-track';
    const savingsState = (period === 'past' && savingsPending > 0) || (period === 'current' && roleTotals.savings.overdue > 0) ? 'needs-attention' : 'on-track';
    const severity = { 'on-track': 0, 'getting-tight': 1, 'needs-attention': 2 };
    const evaluatedStates = [dailyState, reservedState, savingsState];
    const state = period === 'future' ? 'not-started' : evaluatedStates.reduce((worst, candidate) => severity[candidate] > severity[worst] ? candidate : worst, 'on-track');
    const labels = { 'on-track': 'On track', 'getting-tight': 'Getting tight', 'needs-attention': 'Needs attention', 'not-started': 'Not started' };

    return {
      period,
      state,
      label: labels[state],
      daily: {
        planned: roleTotals.daily.planned,
        spent: spentByRole.daily,
        remaining: dailyRemaining,
        difference: dailyDifference,
        pool: dailyPool,
        moneyRemainingFraction,
        monthRemainingFraction,
        paceRatio,
        safe,
        state: dailyState,
      },
      reserved: { ...roleTotals.reserved, pending: reservedPending, state: reservedState },
      savings: { ...roleTotals.savings, pending: savingsPending, state: savingsState },
    };
  }

  return { daysInMonth, remainingDaysInMonth, planAmounts, monthPlanSummary, accountPlanSummary, goalProgress, safeToSpend, monthStatus };
}));
