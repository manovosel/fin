# Envelope Model

## Core Definition

Everything that holds money for a purpose is an envelope.

An envelope is a persistent bucket of money for a specific purpose. Its legacy account label remains for planning compatibility, while an optional real default account is only new-expense convenience.

An envelope balance does not reset at the end of a month. It carries forward until money is added, spent, transferred, or adjusted.

An envelope may explicitly opt out of that carry-over. A **Reset** envelope starts the new month with exactly that month's allocation: the balance it carried over is not inherited, neither a leftover nor a negative. Every envelope accumulates unless its owner says otherwise.

## Universal Rule

If money is set aside for something, that thing should be represented as an envelope.

This includes:

- daily spending,
- food,
- fuel,
- subscriptions,
- rent,
- obligations owed in another country,
- savings,
- investments,
- future purchases,
- travel goals,
- emergency funds,
- any fixed or irregular purpose.

Savings outside the envelope model should not exist in the product.

Anything that does not fit the envelope model should be removed or redesigned until it does.

## Envelope Properties

An envelope should represent:

- a unique name, compared after trimming and without regard to capitalization,
- purpose,
- legacy account label used by existing planning/grouping/reminder behavior,
- optional active real default payment account for new-expense preselection,
- preferred currency for monthly planning and manual expense entry (DKK or EUR),
- amount source (`history` or `fixed`),
- month-end behavior (`accumulate` or `reset`),
- role,
- current persistent balance,
- optional goal amount for Savings envelopes only,
- goal-progress visibility for Savings (`Hidden`, `Month only`, or `Today and Month`),
- balance visibility for Reserved/Savings (`Normal`, `Hide balance`, or `Show on Today`),
- ordering.

## Amount Source and Month-End Behavior

Two independent attributes shape how an envelope's monthly amount and its balance behave.

**Amount source** is either `history` or `fixed`. A `history` envelope's monthly amount is derived from what it actually spends, and FIN recommends an amount for it. A `fixed` envelope keeps the monthly amount its owner set; FIN derives no amount for it and suggests none. The owner can still edit the amount by hand at any time.

**Month-end behavior** is either `accumulate` or `reset`. An `accumulate` envelope keeps its balance across months, as every envelope always has. A `reset` envelope starts the new month with exactly that month's allocation: when that month's allocation is funded, the envelope's balance becomes exactly the allocation and the balance it carried over is dropped. The reset is a dated ledger event, so the previous month's own result — allocated, spent, and its closing balance — stays visible in history, and the reset changes only the new balance. The funding that starts the month is the rollover; an amount moved forward for a later month is simply added to the envelope and leaves its carried-over balance alone.

Neither attribute implies the other: a fixed envelope may accumulate, and a history-based envelope may reset. Both are per envelope. Changing one affects future months only; it never rewrites money that is already recorded.

## Envelope Roles

Role does not change what an envelope is. Every role still represents a persistent bucket of money.

Role only controls how the envelope affects the daily experience.

### Daily spend

Daily spend envelopes contain money that may be used for ordinary day-to-day spending.

Daily spend envelopes:

- are eligible to appear on Today, with exact zero balances omitted to reduce clutter,
- always contribute to the safe-to-spend number,
- cannot hide their balance or opt out of Today while retaining the Daily spend role,
- keep their balance across months.

### Reserved

Reserved envelopes contain money protected for a known purpose.

Reserved envelopes:

- do not contribute to safe-to-spend,
- normally remain in the Month plan rather than Today,
- may hide their Month balance or be shown on Today without affecting safe-to-spend,
- keep their balance across months.

Examples include rent, bills, fuel, subscriptions, and obligations owed in another country.

### Savings

Savings envelopes contain protected money for a future goal or long-term purpose.

Savings is not one special envelope named "Savings". Savings means any envelope whose role is Savings.

Savings envelopes:

- do not contribute to safe-to-spend,
- are normally shown as protected money in the Month plan,
- may hide their Month balance or be shown on Today without affecting safe-to-spend,
- may show derived goal progress as a percentage and progress bar without exposing current or target amounts,
- configure goal progress independently as Hidden, Month only, or Today and Month,
- keep their balance across months.

Only envelopes with the Savings role may have an optional goal amount.

Expenses may be recorded against savings envelopes, but this means using protected money. Editing a manual expense updates the same transaction and its linked movement so changing the amount or envelope immediately corrects the affected persistent balances. Direct debit expenses remain derived from their monthly allocations and are not edited directly.

## Visibility Relationship

Balance visibility is behavioral presentation, not an archive state, and never changes safe-to-spend calculations. Normal keeps Reserved/Savings off Today and shows Balance in Month. Hide balance masks Balance in Month while Plan remains visible. Show on Today temporarily surfaces the balance without making it Daily spend. Role remains the sole source of safe-to-spend inclusion.

Savings goal-progress visibility is independent from balance visibility. Hidden omits progress, Month only shows it in the Savings allocation row, and Today and Month additionally shows it in Today beneath a simple separator without a title or collapse control. Goal progress is derived from persistent balance divided by goal, never stored; negative progress displays 0%, the bar visually caps at 100%, and the percentage may exceed 100% after the goal is reached. Goal progress never displays current balance or target amounts.

## Month Plan Relationship

The month plan should not define envelope balances directly.

The month plan describes how much money should be allocated to each envelope during the month.

Every month plan shows every envelope. There is one planned funding amount per envelope per month, defaulting to 0 DKK / 0 EUR when unsaved. This monthly plan value resets; the persistent envelope balance does not. A new empty month starts with planned funding of 0 DKK / 0 EUR unless the user explicitly copies another month.

An allocation contains the envelope, planned amount in both DKK and EUR, funding status, optional funding date, and a user-entered note. Status is changed directly from the Month row with a compact P/F/S control rather than inside the allocation editor. Name, role, account, and preferred plan currency come from the envelope rather than being duplicated as independent allocation properties. Only the preferred-currency amount is edited; the other amount is converted with the exchange rate belonging to that allocation's month. Allocation group and subgroup totals show both currencies in aligned columns with EUR on the left and DKK on the far right. Derived helpers such as days or weeks remaining must be calculated when needed and never stored in allocation notes.

Balances and movements remain DKK-based. A balance is always calculated as of a cutoff date and includes only movements whose `movement_date` is on or before that cutoff; current views use today. Expenses store both DKK and EUR plus the original entry currency. Manual expense entry follows the selected envelope's preferred currency and converts with the expense month's exchange rate; both amounts are shown in Recent expenses and reports. Month allocation rows display each persistent DKK balance together with its selected-month EUR conversion, but that conversion is derived and never stored. A balance is the latest absolute Balance observation on or before the cutoff plus movements whose `movement_date` is strictly after that observation through the cutoff; without an applicable observation, the API falls back to the full movement sum from zero. Recording an Envelope Balance observation creates no movement, transfer, transaction, or correction event; same-day movements are already represented by the observation, and one observation per envelope/date is upserted in place. Future observation dates are rejected. Deleting an observation restores the movement-derived balance from the latest remaining observation (or zero), never inserts a reversal movement, and never undoes any transfer, expense, refund, or funding record. Moving money creates linked transfer-out and transfer-in movements whose sum is zero, does not affect income, allocation, or spending, and can be undone atomically. Same-account movement is internal reallocation; cross-account movement is recorded only after confirmation that the real bank transfer is complete. Every positive Funded allocation creates a funding movement into its linked envelope on the actual Funded transition/action date; cycling away from Funded removes that movement. A Direct debit allocation changed to Funded additionally creates an automatic transaction and matching negative expense movement on its payment date. Future payments are stored as scheduled records but do not count as actual spending or affect current balances until `spent_date` / `movement_date` arrives. Actual spending is grouped by the calendar date in `spent_date`, while transaction `month_id` and `entry_id` preserve the source allocation relationship. Editing a DD amount synchronizes funding, transaction, and expense amounts; editing only payment date preserves funding date and moves the transaction/expense dates. Planned and Skipped allocations create no funding or generated payment records. Which Account an allocation or expense uses never changes the allocation's status, the envelope's movements, or the envelope's balance.

A month ending does not reset envelopes. Month boundaries are planning and reporting boundaries, not envelope lifecycle boundaries. Only an envelope that explicitly asks for it — a `reset` envelope — starts its new month from that month's allocation instead of from its carried-over balance. Historical PDF role tables therefore use movement-derived balances as of that month's final day (or today for the current month), actual transactions in the calendar month of `spent_date`, and funding movements linked to that source month's allocations effective by the same cutoff.

The running database normally retains the newest 12 month rows; an older source month with a linked movement after the retention cutoff is temporarily protected so the future movement is not rolled up or made effective early. Before older history is removed, FIN creates a complete verified SQLite archive. Historical movements before the retention boundary are consolidated into one non-undoable brought-forward movement per affected envelope at the first retained month. The sum of movements—and therefore every persistent envelope balance—must remain identical before and after cleanup. Detailed old plans, expenses, transfers, and movement audit history remain available in the archive rather than the running database.

Deleting an envelope removes its monthly planned-funding allocations from every Month plan. Historical expense transactions keep their recorded envelope name for reporting.

## Safe-to-Spend Relationship

Safe to spend today is calculated only from current persistent balances of envelopes marked as Daily spend.

Reserved and Savings balances must never be included in safe-to-spend.

## Envelope Balance Observations

Envelope Balance observations give the user one absolute end-of-day signed Balance per envelope/date that captures a known truth about the bucket. Each observation is the authoritative Balance for that envelope on that date; movements dated strictly after it through the cutoff adjust that observation into later dates. Without an observation, the balance remains the ordinary movement sum from zero, so existing envelopes that have never recorded one keep their historical behavior unchanged. Observations are persisted in `envelope_balance_observations` (DB v2.21) with columns `envelope_id`, `effective_date`, `balance` (Currency, signed), `source`, `note`, and `updated_at`. The `source` column is currently `manual`; future statement-driven promotions may extend it without changing the calculation. Observations are displayed with ordinary movement history under Balance tools, are deletable, and never produce a movement, transfer, transaction, or correction event. The Envelope Balance dialog exposes Record Balance rather than the obsolete Envelope Reconciliation / Apply adjustment / Adjustment undo controls. DB v2.22 deletes every pre-observation `opening_balance` / `adjustment` / `reversal` movement without compensation: those obsolete corrections are not authoritative under the observation model and a row after a user observation must not silently change that observed Balance. Existing observations and ordinary Funding, Expense, Refund, and Transfer movements remain byte-identical; affected Balances intentionally change when the broken rows are removed.

## Concepts to Remove or Redesign

The following concepts conflict with the universal envelope model unless redesigned as envelope behavior:

- standalone savings sections outside envelopes,
- savings entry types not connected to savings envelopes,
- category-based savings rows not linked to envelopes,
- monthly envelope balances that reset implicitly at month end (a reset is an explicit per-envelope choice, never implicit),
- fixed bills that are not represented as Reserved envelopes,
- imported spreadsheet categories that remain outside envelopes,
- any parallel category model that competes with envelopes as the source of money purpose,
- Envelope Reconciliation / Apply adjustment / Adjustment undo as user-facing operations, replaced by Record Balance observations that match the Account Balance model.

## Product Goal

The user should be able to trust that every unit of money is either:

- inside an envelope,
- moving into an envelope,
- moving out of an envelope,
- or intentionally unassigned and needing attention.
