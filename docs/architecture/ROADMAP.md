# Roadmap

## Current Baseline

The application has a Today-centered safe-to-spend experience. Current source is application v1.37.1 and database v2.23.

Current behavior:

- one installation owner authenticates normally through automatic user-verified platform WebAuthn restricted to this browser's locally remembered credential ID; a new/cleared browser uses the external server-managed Master PIN once and is then enrolled automatically, with no discovery, username/password model, authentication chooser, or manual enable step; one explicit `config.php` / `config.local.php` auth section resolves the same PIN/throttle paths for CLI and web,
- authenticated Security management lists labels/devices and supports rename/independent revocation only; unknown or revoked local credentials fall back to PIN and automatic re-enrollment, while cancelled verification offers retry/PIN recovery,
- current calendar month is created automatically if missing,
- safe-to-spend is shown as the main Today number,
- Daily spend envelope balances drive the safe-to-spend calculation,
- every Envelope carries two independent attributes: a `fixed` amount source keeps the owner's own monthly amount and is skipped by the recommender, and a `reset` month-end behavior starts the new month with exactly that month's allocation instead of carrying the previous balance over,
- the Today card contains the Add expense action directly and does not need a separate date/title panel or month/year selectors,
- Recent expenses is title-click collapsible with per-user remembered state; manual and effective Direct debit expenses can be edited/deleted through the shared dialog, while only future scheduled DD expenses remain allocation-controlled,
- Today has no Monthly summary; Reserved and Savings remain in Month unless explicitly shown on Today,
- Month unifies envelope management and monthly planning, with collapsible role and account groups,
- each Month allocation row shows monthly Plan and persistent Balance in Currency and the selected Month's Helper-currency conversion, followed by a small far-right P/F/S control; actual spending stays in Recent expenses and reports, while allocation status is no longer edited in the dialog,
- every Month displays every Envelope with one monthly allocation, defaulting to zero in both configured currency displays when unsaved,
- every positive allocation changed individually to Funded creates date-effective funding, while Funded Direct debit allocations additionally create synchronized automatic transactions/expense movements on their payment date—defaulting to the first day of the allocation's own Month—with future payments excluded from balances and actual spending until effective, after which planning cannot erase them,
- allocation name, role, and account come from the envelope,
- legacy duplicate allocations are combined while distinct purposes are split into envelopes,
- envelope balances come from the latest absolute Envelope Balance observation on or before the cutoff plus movements strictly after that observation through the cutoff; without an observation the API falls back to the ordinary movement sum from zero. Balance tools expose Record Balance, observation deletion, and linked zero-sum envelope transfers alongside ordinary movement history; the obsolete Envelope Reconciliation / Apply adjustment / Adjustment undo controls are absent, and DB v2.22 deletes their leftover opening/adjustment/reversal movements without compensation so they cannot override later observations,
- confirming an allocation as funded creates a funding movement,
- expenses create spending movements,
- a new Expense or imported statement row that would repeat an existing Transaction exactly - the same date, Envelope, amount, and description - is reported to the owner and written only after confirmation; identical same-day entries stay legitimate, and the Plan-derived Direct debit Transaction is a Plan effect that is never blocked by this rule,
- a left-aligned × button logs out without a hamburger menu, while the right header button switches directly between Today and Month,
- the HTTPS-served web app is installable from Android Chrome as a standalone PWA; its service worker caches only the public shell and never API or private financial data,
- month settings persist salary and precise exchange-rate changes with visible save validation and errors,
- month copying warns before overwriting existing plan data and preserves Skipped while resetting all other copied statuses to Planned,
- Envelopes choose `CURRENCY` or `HELPER_CURRENCY` as their plan-entry preference; every Plan row derives both configured displays, while totals align Helper currency and Currency in separate columns with Currency farthest right, using the owning Month's `exchange_rate`,
- Daily spend is always visible and solely controls safe-to-spend inclusion, while Reserved/Savings balance visibility can keep them in Month, hide Balance, or show Balance on Today without affecting calculations,
- Savings goal progress is derived from persistent balance and displayed without financial amounts; each Savings envelope independently chooses Hidden, Month only, or Today and Month, with Today goals always visible beneath a simple untitled separator,
- Month has a floating, default-collapsed Month status above an unboxed action row, followed in the main content by a default-collapsed Plan summary with a compact Plan / Funded / Balance totals row and per-Account Plan / Funded / Envelopes detail shown in each Account's Currency or Helper-currency preference; the Plan summary shows no Income and no Allocations, and account rows are not clickable and carry no transfer reminder,
- Plan summary, Month status, and Allocations share one top-level accordion design; Plan summary and Allocations use a neutral accent, while Month status alone uses its derived status color,
- the compact Month status section above Plan summary interprets current or past Daily spend as an aggregate pool, Reserved as funding rather than overspending, and Savings as planned contributions made; its On track / Getting tight / Needs attention state also colors Today's main card green / orange / red without changing calculations,
- the allocation plan is default-collapsed with visually distinct role/account hierarchy; plan, role, account, and summary collapse states are remembered locally for the installation owner,
- the Month action row sits beneath Month status without its own panel and above Plan summary, and all six actions, including Accounts, remain on one concise row on mobile,
- the human-readable PDF report separates monthly plan, date-effective funding, actual transactions by `spent_date`, role-specific activity, cutoff-effective balances, and user-meaningful transfers/adjustments in printable Unicode tables; every role table shows Plan, Funded, and Actual, a month with at least one Income event also shows that month's recorded income and its difference against the planned income, a range of two or more months adds one compact range summary with the range totals and the per-role split, a restrained Daily Spend Plan/Actual totals chart appears only once for meaningful ranges with at least two started months, while single-month tables remain uncharted, while the detailed XLSX layout remains one month-stacked worksheet whose month blocks additionally carry that month's actual spending total and plan-vs-actual difference,
- allocation editing and a responsive Funding Review provide deterministic, optional funding guidance from completed native actual history; envelope/month exclusions affect only recommendation statistics, manual historical baselines remain explicitly external reference information, and Savings receives no spending-derived recommendation,
- manual CLI history retention normally keeps the newest 12 month rows after first creating a verified full backup and checksum manifest; expired envelope movements become balance-preserving rollups, transaction-linked Account events remain as detached signed evidence, both envelope balances and Account positions are verified, and old source months linked to post-cutoff movements remain protected,
- the Account ledger, signed ordinary Account-event domain, nullable Transaction payment Account and source-plan Month, Month Funding context, and statement identity/reconciliation invariants remain the v1.18 foundation; DB v2.15 makes Asset/Liability/Credit card classification presentation-only, DB v2.16 implements one immutable database Currency plus configurable Helper-currency input/presentation, DB v2.20 replaces Account opening/reconciliation movements with absolute Balance observations, DB v2.21 mirrors the same absolute observation model for Envelopes, and DB v2.22 removes invalid legacy Envelope Balance corrections while preserving ordinary movements and observations,
- Accounts are permanent and month-independent: list, add, edit, observation-anchored current Balance, combined observation/movement history, Record Balance, observation deletion, archive, and restore are normal workflows available at any time; without an applicable observation Balance is explicitly Unknown/null, while Transactions and Transfers remain valid at every date,
- Accounts also exposes compact statement-evidence preview/import for the real statement exports of the installation, matched against the saved Import Profiles; checksums/fingerprints protect duplicate and overlapping imports, malformed or ambiguous rows remain inert evidence, and a day-close running balance stays evidence instead of becoming an independent absolute Account Balance observation,
- the normal saved-Import-Profile statement path accepts at most 1,000 parsed rows per file and rejects an oversized file atomically with its detected count and the limit, without partial evidence ingestion,
- every new Account may leave Current Balance unknown or record an explicit signed Balance, including zero, as today's absolute `account_creation` observation without creating Income, Expense, Account movement, Envelope Funding, allocation, or Month Funding context,
- archived accounts retain every historical reference but are excluded from new Paid from and envelope-default choices,
- nullable real envelope defaults, explicit nullable DD payment instructions, and overrideable Paid from use one canonical posting path: transaction payment account is authoritative, legacy labels/defaults are never inferred for DD, Unknown remains valid, and every complete expense uses one model regardless of date/provenance,
- the September clean-start product concept is removed; DB v2.6 drops only its three empty workflow tables while retaining generic account and Plan Funding foundations,
- automated tests cover financial calculations, migrations, envelope movements, history archive/cleanup safety, month copying, production-update safety, Master PIN hashing/throttling/CLI, WebAuthn challenge/verification and virtual platform authenticators, API authentication, and critical desktop/mobile browser workflows using isolated synthetic data.

## Next Product Questions

- How should using protected Savings money feel in the expense flow?
- Should the Month plan help assign envelope roles more directly after import?
- How should the app communicate that a newly created current month has no plan data yet?

## Envelope Model Work Needed

The first implementation of persistent envelope balances exists, but parallel legacy concepts still remain.

Remaining product/model cleanup:

- remove standalone savings concepts outside envelopes,
- remove or redesign monthly categories that compete with envelopes,
- improve the language around confirming planned funding,
- decide how expected outgoing obligations from Reserved envelopes should be represented,
- improve imported envelope naming and account assignment,
- decide whether all new envelopes must require an account immediately.

## Engineering Follow-up

- Statement evidence now runs through deterministic conservative recognition after import: exactly one compatible existing record is linked, exact configured Account identifiers may create internal Transfers, and exact prior description/Account/direction history may supply one Envelope. A Transaction without a payment Account is a candidate only when no Transaction paid from the statement Account matches, and a linked one adopts the statement Account; the Plan-derived Direct debit Transaction adopts the real statement amount when the planned and the real amount differ, while several real debits claiming one planned payment stay manual. Accounts → Reconcile is exception-only and uses one compact list where each selected row chooses an Envelope (Expense/Refund according to evidence direction), another Account (Transfer according to evidence direction), or the final explicit `IGNORE` option (durable No FIN action resolution for that Account's evidence row). On Reconcile load, the maximum of punctuation-insensitive whole normalized-string and compact same-length local-window Levenshtein scores over ordinary Transaction notes, ordinary Transfer-event notes, and prior reconciled statement descriptions—including `no_fin_action` outcomes—selects the best historical outcome at a threshold of 75. Local windows are disabled for compact strings shorter than four characters; Transfer candidates retain same-Account/same-direction restrictions, and the most recent applicable source record remains the exact-score tie-breaker. An Envelope or Transfer winner becomes ordinary transient dropdown state. A Transfer destination is preselected only when the statement evidence itself names a counterpart Account; a row whose evidence names none keeps the destination to the owner's choice. One real movement reported by two statements is one Transfer: the second side links to the Transfer the first side recorded instead of recording the same payment twice. A `no_fin_action` winner emits no preselection and does not fall back to a lower-scoring categorized outcome, so `IGNORE` remains an explicit owner choice. Partial Save keeps the existing per-row path, manual changes replace a categorized preselection directly, and Close writes nothing. Reconcile displays the current Account's full unresolved total from a separate count-only query, assesses and preselects only the next 32 rows, asynchronously updates the count and refills that batch after Save, then advances through later active Accounts in the existing alphabetical order; a load failure is shown as an error rather than converted to a false empty result. No fuzzy automatic reconciliation, confidence UI, persistent suggestion/rule model, AI, or speculative Save exists.
- Add CI execution of `./scripts/test.sh` once the repository's CI runner is selected.
- Continue expanding report/export coverage for retained historical archives and legacy deleted-envelope data as those areas change.
- Continue extracting pure domain behavior from `api.php` and `app.js` when doing so directly improves testability.

## Not Yet in Scope

- Multi-user authentication or financial separation.
- Bank automation.
- Full accounting replacement.
- Complex historical analytics.
