# Daily Questions

## Can I safely spend this money today?

- Why the user asks it: The user needs quick confidence before making an everyday purchase.
- Which screen should answer it: Today screen.
- Answer type: Immediate.
- Product answer: A large safe-to-spend number calculated from Daily spend envelopes only, rounded down to the nearest 10 DKK.

## How much money is available for normal day-to-day spending?

- Why the user asks it: The user needs to know whether ordinary spending categories still have room.
- Which screen should answer it: Today screen.
- Answer type: Immediate.
- Product answer: Daily spend envelopes are visible directly under the safe-to-spend number.

## Which envelope should this expense come from?

- Why the user asks it: The user wants to record spending without thinking like an accountant.
- Which screen should answer it: Add expense flow from the Today screen.
- Answer type: Immediate.

## Did I already record today’s spending?

- Why the user asks it: The user wants to avoid forgetting expenses or entering the same expense twice.
- Which screen should answer it: Today screen.
- Answer type: Immediate.
- Product answer: When a new Expense or an imported statement row would repeat an existing Transaction exactly — the same date, Envelope, amount, and description — FIN says which existing entry it repeats and asks before writing it again. The answer is never automatic, because identical same-day entries are sometimes legitimate.

## Am I creating a problem for later in the month?

- Why the user asks it: The user needs to understand whether today’s spending will make the rest of the month stressful.
- Which screen should answer it: Today screen.
- Answer type: Calculated.
- Product answer: If Daily spend money is over plan, the hero shows `0 dkk` with the caption `over plan`.

# Weekly Questions

## Which envelopes are running low?

- Why the user asks it: The user needs to adjust behavior before a category becomes a problem.
- Which screen should answer it: Today screen or Envelopes screen.
- Answer type: Immediate.

## Is my actual spending matching the plan?

- Why the user asks it: The user wants reassurance that the month is still under control.
- Which screen should answer it: Month plan screen.
- Answer type: Calculated.

## Are there expenses I forgot to record?

- Why the user asks it: The user wants the plan to stay trustworthy without doing full accounting.
- Which screen should answer it: Today screen.
- Answer type: Requires navigation.

## Do I need to slow down spending before the month ends?

- Why the user asks it: The user wants early warning before money becomes tight.
- Which screen should answer it: Today screen.
- Answer type: Calculated.

# Monthly Questions

## Is the current month available?

- Why the user asks it: The user wants the app to be ready without setup work at the start of a month.
- Which screen should answer it: Today screen.
- Answer type: Immediate.
- Product answer: If the current calendar month does not exist, the application creates an empty month.

## Is next month planned?

- Why the user asks it: The user wants confidence before a new month begins.
- Which screen should answer it: Month plan screen.
- Answer type: Immediate.

## What changed compared with last month?

- Why the user asks it: The user wants to adjust only what is different instead of rebuilding the plan.
- Which screen should answer it: Month plan screen.
- Answer type: Requires navigation.

## Have all fixed obligations been accounted for?

- Why the user asks it: The user needs to know that bills, transfers, and recurring commitments will not be missed.
- Which screen should answer it: Month plan screen.
- Answer type: Calculated.

## How much is expected to be left after the plan?

- Why the user asks it: The user wants to know whether the month is financially safe before it starts.
- Which screen should answer it: Month plan screen.
- Answer type: Calculated.

## Did the month end as expected?

- Why the user asks it: The user wants closure and confidence that the plan reflected reality.
- Which screen should answer it: Month plan screen or report/export screen.
- Answer type: Calculated.

# Long-term Questions

## Am I making progress toward savings goals?

- Why the user asks it: The user needs motivation and reassurance that planning is producing visible results.
- Which screen should answer it: Savings rows in Month, and later a long-term goals screen.
- Answer type: Calculated.
- Product answer: Savings envelopes with goals can show percentage-only progress in Month or in both Today and Month. Exact current and target amounts are omitted from progress, and display is independently configurable per envelope.

## Can I afford a future purchase without creating stress?

- Why the user asks it: The user wants to make larger decisions without damaging daily financial confidence.
- Which screen should answer it: Long-term goals screen.
- Answer type: Calculated.

## When will I have enough money for a goal?

- Why the user asks it: The user wants a realistic timeline instead of guessing.
- Which screen should answer it: Long-term goals screen.
- Answer type: Calculated.

## Is my savings rate good enough for the future I want?

- Why the user asks it: The user wants to know whether current planning habits support longer-term priorities.
- Which screen should answer it: Long-term goals screen or Month plan screen.
- Answer type: Calculated.

## What future commitment will affect my ability to spend?

- Why the user asks it: The user wants to avoid surprises from upcoming obligations or planned purchases.
- Which screen should answer it: Long-term goals screen or Month plan screen.
- Answer type: Requires navigation.

# Account Follow-up Questions

Accounts and their absolute end-of-day Balance observations are now ordinary permanent features. Remaining decisions are:

- Which real default account should each production envelope use? No legacy label is authoritative for this choice.
- Should the existing card Reserved envelope be renamed separately, and what ordinary envelope reconciliation is required? It remains unrelated to card debt.
- What user-facing neutral overall Month Status should represent unknown Plan Funding?
- Should a future-month known funding deficit override Not started, or appear as a separate planning warning?
- How should future reconciliation confirm complete account/month coverage before zero recommendation observations are trusted? Inert imports alone never establish recommendation coverage.
- Should account movement posting remain DKK-base initially, or does the first real fee-format or debit/credit sample require native-currency account movements?
- Which narrow permanent UI should later expose ordinary Account-to-Account Transfers without turning FIN into accounting software?

# Potential Future Month Status Issues

- Savings contribution status follows the allocation's Funded status. Since database v2.1, a Funded Direct debit allocation has a corresponding funding movement plus a scheduled/actual expense movement, so its contribution is financially represented; whether Savings should permit Direct debit remains a separate product question.
- Envelope roles are global rather than historically snapshotted. Changing an envelope's role can therefore reinterpret Month Status and role tables for earlier months. This task does not change that behavior.
- FIN stores planned income metadata and a separate Income account-event ledger. The report shows planned income and, for a month that has at least one Income event, that month's recorded income; it still cannot claim a realized cash surplus/leftover.
- A transaction whose envelope was later deleted retains its expense envelope name and remains in Actual Expenses, but its historical role is unavailable; it cannot be assigned reliably to a Daily/Reserved/Savings table without role snapshots.
- Reports can select only months that still have a `months` row in the running database. Full detail for archived months remains available from the verified archive database rather than the cleaned running database.
