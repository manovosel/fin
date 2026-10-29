# Current State

## Current Project Structure

The application is a small PHP, SQLite, HTML, CSS, and vanilla JavaScript web app located at the repository root.

```text
.
├── README.md
├── VERSION
├── api.php
├── report.php
├── recommendation.php
├── accounting.php
├── statement_import.php
├── auth.php
├── app.js
├── finance.js
├── composer.json
├── composer.lock
├── package.json
├── package-lock.json
├── phpunit.xml
├── playwright.config.js
├── config.php
├── favicon.svg
├── icons/
│   ├── fin-180.png
│   ├── fin-192.png
│   └── fin-512.png
├── index.html
├── manifest.webmanifest
├── service-worker.js
├── style.css
├── data/
│   ├── .htaccess
│   └── fin.sqlite
├── docs/
│   └── architecture/
│       ├── ARCHITECT_REVIEW.md
│       ├── CURRENT_STATE.md
│       ├── PRINCIPLES.md
│       ├── PROJECT.md
│       ├── QUESTIONS.md
│       ├── ROADMAP.md
│       └── VISION.md
├── scripts/
│   ├── __pycache__/
│   ├── import_source_budget.py
│   ├── refresh-dev-from-prod.sh
│   ├── run-protected.sh
│   ├── scrub-audit.sh
│   ├── test.sh
│   └── update.sh
└── tests/
    ├── browser/
    ├── fixtures/
    ├── js/
    ├── php/
    └── shell/
```

Observed supporting files:

- `.gitignore` excludes `AGENTS.md`, `config.local.php`, runtime data under `data/`, and the source budget workbook, while allowing `data/.htaccess`.
- `data/.htaccess` denies web access to the data directory.
- `data/fin.sqlite` exists in the working tree as the current runtime SQLite database.
- PHPUnit and Playwright are development-only dependencies; `vendor/`, `node_modules/`, temporary test databases, traces, screenshots, and HTML reports are ignored by git.

## Existing Modules

### Backend API and CLI

`api.php` contains the primary backend implementation, `auth.php` isolates Master PIN/WebAuthn/session-security behavior, `recommendation.php` contains deterministic funding-recommendation domain calculations, `accounting.php` contains the typed-account persistence/domain and Plan Funding foundations, and `statement_import.php` contains the four narrow XLSX/OOXML evidence parsers plus preview/import persistence. WebAuthn parsing and cryptographic verification use maintained `lbuchs/webauthn` v2.2 rather than custom CBOR/COSE/signature code. The API is both:

- a web API endpoint, and
- a CLI utility for migration, verified history archiving/cleanup, and user creation.

The backend currently handles:

- one installation security principal with no username or per-user financial scope,
- root-scoped, strict-mode, HTTP-only application sessions that remain valid when production maps the public origin root to an internal `/fin` path; forwarded HTTPS is recognized for Secure cookies,
- automatic platform WebAuthn registration after a new browser's successful Master PIN proof, followed by assertions restricted to that browser's locally remembered non-secret credential ID, with exact origin/RP validation, required user verification, short-lived single-use challenges, counters, labels, last use, and revocation,
- server-only Argon2id Master PIN configuration outside SQLite/web root plus persistent progressive brute-force throttling,
- application and database version reporting,
- ordered database migrations tracked in `schema_migrations`,
- one recursively merged application configuration loaded from tracked `config.php` and optional ignored `config.local.php`; its nested `auth.master_pin_file`, `auth.throttle_file`, and `auth.api_token_file` values are the single path source for CLI and web, and its `webauthn_rp_id` / `webauthn_origin` values are the installation's own relying-party id and origin,
- SQLite connection creation,
- database migration,
- PHP session setup/regeneration, authentication checks, CSRF protection for device-security changes, and logout invalidation,
- month CRUD,
- monthly envelope-allocation CRUD,
- envelope CRUD,
- Expense/Refund transaction creation, editing, and deletion,
- month duplication,
- report data generation,
- one authenticated history endpoint that returns the report model's per-month Plan, Funded, Spent, and Balance figures plus actual Income events for trusted external clients, extended with the per-envelope actual spending of the months that exist only as transactions, with Savings goal targets and progress, and with the current month's safe-to-spend figure together with every input behind it,
- one read-only transaction endpoint that returns individual effective Expense and Refund rows selected with the PDF report's calendar-month rules, with an optional exact Envelope filter and a bounded three-month range,
- one Account balance endpoint that returns the current balance, status and latest Balance observation date of every Account, archived Accounts included, with the same balance the Accounts screen shows and no Account numbers,
- one transfers endpoint that returns the Envelope Transfers and the Account Transfers of a bounded three-month range, selected with the monthly report's calendar-month rules, with one shared payment id per real payment so a client cannot add the same payment up twice, and with no Account numbers,
- one narrow Plan write endpoint (`setPlanAmounts`) that sets the planned amount of Plan rows for one existing Month and changes nothing else,
- one API token that lets an external client call the five allow-listed GET actions (`history`, `transactions`, `transfers`, `fundingRecommendations`, and `accountBalances`) without a browser session, plus the single `setPlanAmounts` write, and nothing else,
- Unicode, human-readable PDF export through the report domain model in `report.php` and Dompdf,
- detailed month-stacked XLSX export,
- on-demand funding recommendations, manual historical baseline metadata, and envelope/month recommendation exclusions, with `fixed` envelopes skipped by the recommender,
- typed account/event/movement persistence and one-model transaction synchronization,
- optional real envelope default-account references and authoritative transaction payment accounts,
- permanent month-independent Account list/add/edit/archive/history/Record Balance workflows,
- absolute end-of-day Account Balance observations with explicit known/Unknown state, optional today-dated Account-creation Balance, manual upsert/delete, and no opening/reconciliation Account events,
- financially inert statement-evidence preview/import matched against the installation's saved Import Profiles by header structure, with per-profile effective Account-delta normalization, mixed escaped/mojibake text repair that preserves valid UTF-8 segments, plus exact-file and overlapping-row duplicate protection,
- deterministic statement auto-processing to an exact existing Transaction/Transfer/Income event — including a Transaction that carries no payment Account and the Plan-derived Direct debit Transaction that really paid — an exact-identifier internal Transfer, a history-proven Expense/Refund Envelope, or Income from a profile-backed row whose persisted canonical `classification` is `income` — any other credit stays an unresolved exception for the owner; only unresolved exceptions reach Accounts → Reconcile,
- checked JSON response serialization that fails closed with a valid HTTP 500 JSON error, plus a buffered JSON-request shutdown guard that replaces runtime-fatal partial output with JSON while leaving binary report downloads unbuffered,
- CLI commands:
  - `php api.php migrate`,
  - `php api.php version`,
  - `php api.php archive-preview --keep-months=12`,
  - `php api.php archive --keep-months=12 [--backup-dir=PATH]`,
  - `php api.php set-master-pin [--stdin]`,
  - `php api.php master-pin-status [--require]`,
  - `php api.php create-api-token`,
  - `php api.php refresh-snapshot [--backup-dir=PATH]` (read-only source backup for the one-way PROD → DEV operator workflow).

### Frontend Application

`app.js` contains the client-side application. `finance.js` contains pure, shared safe-to-spend date and rounding calculations so the exact browser logic can be tested without the DOM. `report.php` builds the human-readable PDF domain model, ports the existing Month Status semantics for backend report generation, and renders printable Unicode HTML through Dompdf without changing the XLSX builder. The frontend manages:

- global frontend state,
- existing-session startup, automatic Device login, cancelled/failed/unavailable fallback states, Master PIN recovery, and logout,
- authenticated Device login list/rename/revocation UI, with no manual enrollment action,
- loading state from the backend,
- screen switching between Today and Month,
- rendering Today and Month, including the derived Month Plan summary,
- rendering and submitting modal dialogs,
- one authoritative Currency amount plus derived Helper-currency allocation presentation,
- local storage of expanded/collapsed group preferences,
- client-side calculations for monthly totals, envelope balances, and available amounts,
- calls to `api.php` using `fetch`,
- consistent `dd/mm/yyyy` presentation for full dates in the application and reports while native date controls retain ISO values internally; every date field exposes the browser's native picker through FIN's locale-independent date-field presentation, and Recent expenses retains its deliberately compact `dd.mm` cell with the full date in the title,
- overrideable Paid from selection defaulted from envelope convenience metadata,
- compact Statement import preview/import followed by deterministic processing; Accounts → Reconcile shows the current Account's full unresolved count and renders only its next bounded batch of up to 32 exceptions in a compact Date / Description / Amount / Reconcile table with one grouped Envelope-or-Transfer classification dropdown ending in explicit `IGNORE` per row, history-derived ordinary preselection where similarity reaches 75, changed-row highlighting, partial Save, asynchronous refill/Account advancement, visible load errors, and persistent bottom Save/Close controls; prior `IGNORE` outcomes compete in the same history matching, but an `IGNORE` winner leaves the dropdown neutral so the owner must always choose it explicitly; Save is disabled with an inline loading indicator throughout both the save request and refill, then returns to its selection-dependent enabled state after fresh content or an error is rendered.

### Automated Tests

The test system has three layers behind `./scripts/test.sh`:

- Node's built-in test runner checks deterministic financial calculations in `finance.js`,
- PHPUnit checks fresh and legacy migrations, migration idempotency, envelope movements and persistent balances, the fixed/reset envelope attributes including the month rollover, envelope deletion history, month copying, the read-only history endpoint including its authentication gate, its extended range and its Savings goals, the read-only transaction endpoint including its range limit, Envelope filter, Refund rule and PDF-selection parity, the Account balance endpoint including its Accounts-screen parity, archived Accounts, unknown balances and missing Account numbers, the Transfers endpoint including its range limit, its Month-report and Accounts-screen parity, its one-payment-id linking, and its excluded records, the Plan amounts write endpoint including its Month, status, batch, and all-or-nothing rules, and the API token including its action and method limits against isolated SQLite databases,
- Playwright checks public/authenticated API behavior and critical Today/Month workflows in Chromium. Each Playwright worker starts one private FIN server, creates its Master PIN through the cheap test-only Argon2 path, migrates and seeds its SQLite database once, snapshots that pristine baseline, and atomically restores the worker database before every test. Ordinary application tests reuse one worker-scoped authenticated storage state while authentication, wrong-PIN, logout/session-regeneration, and WebAuthn tests keep the real unauthenticated flow. All tracked workflows run on desktop; only explicitly tagged responsive layout/overflow/navigation coverage also runs on mobile. Browser contexts remain test-scoped, retries remain disabled, and debug specs follow a non-default naming convention with `debug-*.spec.js` explicitly excluded from ordinary discovery.

Shell integration tests verify production-update-script dirty-tree protection, consistent upgrade backup creation, pull behavior, migration/version reporting, archive preview, backup integrity/checksums, twelve-month cleanup, balance rollups, and the complete one-way PROD → DEV refresh mechanism through synthetic databases plus fake SSH/SCP. Test fixtures are synthetic and never use the private runtime database or real production transport.

### Progressive Web App Shell

`manifest.webmanifest`, the FIN app icons, and `service-worker.js` make the deployed site at its own origin (for example `https://example.com`) installable from Android Chrome in a standalone window. The service worker pre-caches only public shell assets and explicitly bypasses every `api.php` request, so authentication status, PIN requests, WebAuthn challenges/responses, logout, reports, and private financial data remain network-only. The external `app.js` registers the worker after page load, remaining compatible with the production Content Security Policy, while `index.html` includes Android/iOS home-screen metadata and requests the manifest with same-origin credentials without relying on an upstream authentication product. Plain HTTP is supported only for localhost development; production installation requires HTTPS.

### HTML Shell

`index.html` defines:

- the automatic Device login / Master PIN recovery view,
- the authenticated application shell,
- top month/year selectors,
- tab buttons,
- the Today safe-to-spend area and default-collapsed Month Plan summary,
- the Today screen,
- the unified Month and envelope-management screen,
- modal dialogs for expenses, envelopes, Account detail, inert statement import, explicit statement reconciliation, reports, month copy, envelope Plan editing, balance tools, and Device login management; the Month-settings and Accounts-list dialogs are gone.

### Styling

`style.css` provides the complete visual design. The UI is a dark responsive layout with a fixed, non-repeating background gradient, sticky header behavior, mobile-specific table layout with enlarged compact labels and financial values for legibility, modal styling, cards, warnings, status pills, envelope rows, and responsive controls.

### Import Script

`scripts/import_source_budget.py` imports a source budget workbook into the SQLite schema. It reads the first worksheet from the XLSX file directly as a ZIP/XML structure and imports month blocks into `months` and `entries`.

## Existing Pages and Screens

The application is a single-page web app served by `index.html`.

### Authentication View

A valid ordinary FIN session opens the application without invoking WebAuthn. Otherwise, FIN checks only this browser/PWA's non-secret `fin.deviceCredentialId`. When present and usable, FIN automatically requests exactly that credential with required user verification. It never invokes credential discovery. A browser with no local ID goes directly to Master PIN; success automatically registers a non-resident local platform credential, remembers its ID, and opens FIN without an enrollment button. Unknown/revoked IDs are removed locally and fall back to PIN. Cancellation shows Try again and Use Master PIN; genuine failures use one generic Device authentication failed state.

### Authenticated App Shell

After login, the app shows:

- month selector,
- year selector,
- a persistent left-aligned × button that logs out directly, with no hamburger menu,
- a persistent right-aligned reciprocal view button showing Month on Today and Home on Month,
- a Device login Security shield visible only on Month,
- safe-to-spend Today card.

### Non-negotiable UI information invariants

Current FIN UI implementation/review applies two independent hard rules: **NEVER ADD REDUNDANT INFORMATION** and **NEVER HIDE IMPORTANT INFORMATION**. User-visible additions MUST NOT repeat meaning already established by surrounding context, and MUST NOT hide, unnecessarily truncate, replace, displace, obscure, or de-emphasize more important information. Symmetry, a consistent Type column, implementation convenience, or an internal enum never overrides either rule. Every addition is rejected if it fails either the redundancy check or the important-information check.

### Today Screen

The Today screen currently shows:

- a large safe-to-spend number,
- Daily spend envelopes below that number,
- an always-visible Add expense button directly in the non-collapsible safe-to-spend card,
- optional percentage-only Savings goal progress, always visible beneath a simple untitled separator when at least one Savings envelope is configured for Today and Month,
- a title-click collapsible Recent expenses panel whose per-user state is remembered, showing only the 24 most recent date-effective Expense Transactions by default in Currency and derived Helper currency. Refunds are excluded by an explicit kind filter; Transfers, Funding, adjustments, and No FIN action are not Transaction members of this list. The Expense-only heading supplies the default semantic, so ordinary rows do not repeat `Expense`. The useful note and Envelope remain complete and may wrap on constrained layouts; they are not ellipsized to make room for type metadata. Show more reveals 16 additional rows and Show less returns to 24. Manual and effective Direct debit Expenses open the shared edit dialog, while only future scheduled DD rows remain read-only. Refund remains available in mixed Account/Envelope histories, reconciliation, PDF, and XLSX. A new Expense follows the selected Envelope's preferred currency and default Account convenience; editing preserves the Transaction's authoritative kind and Account unless explicitly changed.

The Today screen hides month/year selectors and uses the current month context.

The safe-to-spend number is based only on Daily spend envelopes, which are eligible for Today regardless of balance. Today has no Monthly summary. Reserved and Savings normally remain on Month; Show on Today surfaces their balance below a separator without a heading and without affecting safe-to-spend. To reduce clutter, envelope rows with an exactly zero balance are omitted from Today, while negative balances remain visible and continue to communicate overspending.

### Month Screen

The Month screen uses exactly five peer collapsible sections in this order: **Month**, **Accounts**, **Envelopes**, **Plan summary**, and **Funding**. Each section reuses the same existing details/summary component, is independently collapsible, and remembers its state in browser local storage. There is no standalone Month action row and no separate Reports or Import section.

**Month** retains the existing On track / Getting tight / Needs attention / Not started interpretation and contains the editable Month fields directly. Unsaved changed fields turn orange, successful save restores normal styling, and a compact save icon submits the form. Add Month, Copy Month, and Report/export are inside this section. The old Edit Month entry point and Month settings dialog are gone.

**Accounts** contains the current/archived Account list and add/edit/archive/restore, current signed Balance, history, Balance adjustment, statement import, and statement reconciliation workflows. Account-detail and statement subdialogs remain focused workflows, but there is no separate Accounts-list dialog or standalone Accounts/Import/Reconcile Month action.

**Envelopes** contains the global Envelope list, alphabetized by displayed name using Danish locale rules, Add Envelope, and access to all existing Envelope settings and Balance tools. Monthly Plan editing remains in Funding.

**Plan summary** has top totals for Plan, Funded, and Balance. Its per-Account table uses Account, Plan, Funded, and Envelopes. Every current non-archived actual Account appears automatically; archived Accounts disappear while retaining history. Plan and Funded are selected-Month sums of non-skipped Plan rows linked through each Envelope's `default_account_id`, with Funded limited to `status='paid'`. The Envelopes column is the sum of the persistent Envelope balances for Envelopes whose `default_account_id` matches the Account (latest absolute Envelope Balance observation on or before the cutoff plus movements strictly after that observation through the cutoff, or the ordinary movement sum from zero when no observation exists), and is independent of the selected Month. Envelope sums are always known numbers; the prior "Unknown" partial-total handling is gone. **The per-Account Envelopes column is the Envelope balance sum, NOT the real Account Balance**; the real Account Balance remains in the Accounts view. Account rows derive presentation from each Account's `CURRENCY | HELPER_CURRENCY` preference; no second amount is persisted. There is no Account participation configuration, reminder state, duplicate mapping, monthly Account Balance, or clickable Account-summary status.

**Funding** preserves the existing monthly Envelope semantics. It shows every Envelope, grouped by role and then actual default Account; within each role, displayed Account subgroup labels and the Envelope names inside each subgroup are alphabetized using Danish locale rules, and an unmapped Envelope appears under the alphabetically placed No Account label. Funding Review likewise alphabetizes Envelope links within the fixed role sequence, and any legacy uncategorized-plan warning alphabetizes its displayed Entry names. Every row shows selected-Month Plan, persistent Balance, and P/F/S. The Envelope row shows the Envelope name and a compact `DD` label directly after that name for a Direct debit Plan. The Envelope's default Account is shown once per group by the Account subgroup header, not again on every row. The Skipped state is carried by the P/F/S control and the row styling, not by a repeated text label under the name. Planned → Funded → Skipped behavior, Direct debit scheduling, date-effective movements, visibility masking, Savings goal progress, and Currency/Helper presentation are unchanged. Spent remains in Recent expenses and reports. Canonical user-facing terms are Plan, Funded, Spent, Balance, and Funding.

Month status still evaluates Daily spend as an aggregate pool, Reserved by Funding, and Savings by planned contributions Funded. Its 0.75 pace threshold remains an experimental V1 UI tolerance. Today reuses only its status color. The nested Funding role/Account groups and Plan-summary Account table remain independently collapsible.

### Modal Dialogs

Dialog save buttons are consistently right-aligned. The application currently defines modal dialogs for:

- adding/editing/deleting manual and effective Direct debit expenses, with only future scheduled DD rows kept read-only, and with a repeated identical entry confirmed by the owner before it is written,
- adding/editing/deleting an Envelope, including default Account, role, visibility, optional Savings goal, goal-progress visibility, and Balance tools,
- selecting report/export range and opening a responsive Funding Review,
- copying a Month,
- editing/resetting an Envelope Plan,
- Envelope Balance tools for starting Balances, reconciliation adjustments, linked Envelope Transfers, movement history, and audited undo,
- Account detail with signed current Balance or Unknown, optional Account-creation observation, manual absolute Balance recording, combined observation/movement history with observation deletion, and archive/restore.

## Existing Business Concepts

### Installation Security Principal

FIN has exactly one owner/security principal represented by one random opaque WebAuthn user handle. There is no username, user selection, or per-user financial ownership. Master PIN and WebAuthn authentication create the same ordinary FIN session and do not create separate financial modes.

### Month

A Month represents a monthly financial plan. It has:

- year,
- month number,
- `exchange_rate` (`Helper currency × exchange_rate = Currency`),
- salary as one authoritative Currency amount,
- notes.

### Envelope Plan / Entry

A non-Income entry is one monthly Plan for an Envelope. There is at most one Plan for an Envelope in a Month. Name and role come from the Envelope; actual Account association uses the Envelope's optional `default_account_id`. The underlying Entry has:

- Month,
- optional Envelope,
- type,
- name,
- category,
- one authoritative Currency `amount`,
- note,
- status,
- due date,
- sort order,
- recurring/template marker.

The linked Envelope's `CURRENCY` or `HELPER_CURRENCY` preference controls the one editing input; it is not persisted as a second amount on the entry.

The backend accepts historical Entry types `expense`, `income`, `bill`, and `savings`, but normalizes all non-Income rows to `expense` Plans. The product UI creates only Envelope Plans; regular Income is represented by Month salary metadata.

Supported statuses in the backend are:

- `planned`,
- `paid`,
- `skipped`.

There is also legacy handling for `handled`: the backend migration updates `handled` to `planned`, and the frontend normalizer also maps `handled` to `planned`.

### Envelope

Envelopes are global categories used to give money a purpose. An envelope has:

- name,
- optional real default payment-Account reference,
- note,
- role,
- preferred plan currency (`CURRENCY` or `HELPER_CURRENCY`),
- amount source (`history` or `fixed`),
- month-end behavior (`accumulate` or `reset`),
- optional savings goal amount,
- goal-progress visibility (`Hidden`, `Month only`, or `Today and Month`) for Savings,
- balance visibility (`Normal`, `Hide balance`, or `Show on Today`) for Reserved/Savings,
- legacy hidden compatibility flag,
- sort order.

Current envelope roles are:

- Daily spend,
- Reserved,
- Savings.

Daily spend envelopes are always eligible for Today and contribute to safe-to-spend, although exact zero-balance rows are omitted. Reserved and Savings never contribute. Their balance visibility controls only presentation: Normal keeps them in Month with Balance visible, Hide balance masks Balance in Month with the same font colour and weight as a visible Balance (only the mask characters differ, and the masked row is not dimmed), and Show on Today surfaces the balance without changing calculations. Savings goal-progress visibility is independent: Hidden omits it, Month only adds percentage progress to the Month row, and Today and Month additionally adds it beneath an untitled separator on Today even when the balance itself is hidden.

Envelope balances are persistent and derived from envelope movement history through one balance-as-of calculation: only movements dated on or before the requested cutoff are effective, and current views use today. Every positive Funded Plan, including Direct debit, creates a positive Funding movement. Two independent per-envelope attributes shape this: the amount source (`history` keeps FIN's history-based recommendation, `fixed` keeps the owner's own monthly amount and is skipped by the recommender) and the month-end behavior (`accumulate` keeps the balance across months, `reset` starts the new month with exactly that month's allocation). A `reset` envelope is reset by a dated `month_reset` movement written together with the Funding movement it belongs to: the balance the envelope carried into that funding is dropped, so the envelope holds exactly its allocation, and the previous month's own result stays visible in history. Direct debit additionally creates an automatic Transaction and matching date-effective negative Expense movement. Planned and Skipped Plans create no Funding/generated-payment records. Starting balances and reconciliations create signed movements rather than overwriting balance. Envelope transfers create linked negative/positive movements atomically, preserving global total money; cross-account transfers require confirmation that the bank transfer is complete.

### Transaction — Expense or Refund

Transactions represent authoritative actual Expenses and Refunds. `kind='expense'` subtracts from the Envelope and Account and increases net Spent; `kind='refund'` restores the Envelope and receiving Account and reduces net Spent. A transaction has:

- nullable, non-authoritative source-plan month;
- optional entry;
- envelope;
- optional payment Account;
- one authoritative Currency `amount`;
- note;
- authoritative actual `spent_date`;
A complete Expense creates its negative Envelope movement and, only when a payment Account is known, one linked negative Account expense event/movement. A Refund creates a positive restorative Envelope movement and, when its receiving Account is known, one linked positive Account Refund event/movement. Expense always decreases the selected signed Account Balance and Refund always increases it. Editing a Transaction without an explicit kind change preserves its existing Expense/Refund semantic and updates the stable linked effects in place. Existing Transactions retain Unknown Account where no source is known.

Creating a Transaction that would repeat an existing one exactly — the same `spent_date`, Envelope, Currency `amount`, and description, with the same Expense/Refund kind — is reported to the owner instead of being written silently. The owner confirms it, because identical same-day entries are sometimes legitimate; the duplicate is never blocked and nothing is discarded. The comparison ignores description case and extra whitespace, and it deliberately ignores the payment Account: a Direct debit payment without a known payment Account and the same real payment imported from a statement must still be recognised as one and the same payment. The rule covers every owner-created or imported Transaction — the Add expense dialog and statement reconciliation — and it does not cover the Plan-derived Direct debit Transaction, which is a Plan effect rather than an owner-authored record, nor the editing of an existing Transaction. There is no schema change, no setting, and no automatic removal of either record.

### Funding Recommendation

The funding recommendation is calculated on demand from authoritative actual Transactions only, grouped by calendar `spent_date`, with one observation per Envelope and Month and a Refund subtracting from the Envelope's spending. It is advisory: it never changes a Plan, a Balance, or any history. A `fixed` Envelope is skipped entirely. Savings has no spending-derived recommendation and stays goal-driven.

The shape of the monthly spending decides the recommendation:

- a stable monthly obligation keeps its observed amount without an invented buffer,
- a regular but variable month is estimated from a robust median and a recency-weighted average, with modest buffering and practical rounding,
- an irregular reserve (spending in fewer than half of the observed months) is funded by its average monthly cost,
- spending that is not a monthly recurrence — its median month is 0, or its average month is more than twice its median month — is funded by its **average monthly cost** instead of a typical month. The outlier limit is not applied to such a series, because it would only erase the months that carry the spending. A quarterly or annual obligation is therefore never recommended as a monthly requirement, and an Envelope with real but irregular spending is never recommended as 0.

A manual historical baseline is separate reference information: it never enters the algorithm, never raises confidence, and never replaces a FIN recommendation. It is stored as one authoritative Currency amount and is shown in the Plan editor in the Envelope's preferred currency, with the Currency amount kept as the secondary value, so it is directly comparable with the Plan amount entered in that currency. Funding Review compares Current Plan, Baseline, FIN recommendation, Assessment, and Confidence in Currency, because those columns are compared with each other.

### Currency

The database stores its immutable Currency in `database_settings`; configuration must match it on every startup. Helper currency is configurable and may change without rewriting financial history. Every authoritative financial table stores one Currency `amount`; Helper values are derived only for input and presentation. Account and Envelope preferences use `CURRENCY` or `HELPER_CURRENCY` and may change without rewriting authoritative values. Decimal display and input use Danish comma formatting; inputs omit grouping separators but accept pasted dot-decimal values.

Each Month owns one nullable `exchange_rate`, defined as `Helper currency × exchange_rate = Currency`. Conversions use the Month containing the authoritative event date: Plan Month for Plan/Funding and event-date Month for Transactions and Account events. A missing rate remains unavailable. A required missing rate fails atomically; FIN has no invented default or cross-Month fallback. Editing a rate changes future derivation/display only and never rewrites persisted authoritative amounts. Existing target Months keep their own rate when a plan is copied.

### Report

Reports are generated for a selected month range. The PDF and XLSX intentionally serve different presentation purposes.

The PDF is a compact, light, human-readable historical report with one section per month, embedded DejaVu Sans Unicode typography, repeated table headers, generation date, and page numbers. Its overview contains Planned Income, Plan, Plan difference, signed net Actual Spent selected by calendar `spent_date`, and the existing Month status. A month that has at least one Income account event additionally shows Recorded income - the sum of that month's Income events - and an Income difference (Recorded income minus Planned income). A month without an Income event shows neither figure, so unreconciled income never reads as zero; planned and recorded income stay separate numbers. Daily Spend, Reserved, and Savings each use role-appropriate Plan/Funding/Actual/Balance tables; the Daily Spend table shows Plan, Funded, Actual, Difference, and Balance, so Plan / Funded / Spent is visible for every role. Single-month/per-envelope charts are intentionally omitted because they duplicate the precise tables. A range of two or more months adds one compact range summary above the range chart, with the range totals for Plan, Funded, Actual spending, and Difference plus the same Plan / Funded / Actual split per role; the range totals are the sums of the per-month figures, and a single-month export has no range summary. Meaningful multi-month ranges with at least two started months add one grayscale-readable range-level Daily Spend totals chart near the beginning. Reserved, balances, and Savings are not charted. Small Savings sections avoid splitting when they fit intact on the next page. Completed-month balances use month-end; the current month uses today. Actual transactions are chronological and Currency-first; ordinary Expense rows are visually unmarked, while Refund is explicitly labelled inline and shown as restorative negative net spending without a mostly repetitive Type column. The reclaimed PDF width belongs to Description. User transfers and deliberate adjustments are separate and retain necessary event semantics, while `archive_rollup` is omitted from normal PDF content. XLSX also leaves ordinary Expense unmarked in Actual transactions and uses `Refund` only as useful exception metadata while preserving signed spending presentation.

The XLSX export remains one worksheet with selected months stacked vertically in its existing month-stacked layout. Each month block carries the same Actual spending total the PDF prints and a Plan-vs-Actual difference (Plan minus Actual spending) next to the Income / Plan / Plan difference rows, so both exports of one month agree on the actual figure. A month block whose month has at least one Income event also carries a Recorded income row and an Income difference next to the Income row, with the same values the PDF prints; a month without an Income event carries neither. Starting balances, adjustments, and envelope transfer movements remain in its separate Balance movements section and do not change report income or expenses.

### History (read-only API)

`GET api.php?action=history&from=YYYY-MM&to=YYYY-MM` returns one JSON month per selected month. It uses the same `from`/`to` parameters and validation as `action=report` and the same ordinary FIN session authentication; an unauthenticated request is rejected. Every month carries year, month, label, period (`past`/`current`/`future`), cutoff, a `coverage` marker, the Daily/Reserved/Savings rows and totals, `overview.planned_allocations`, `overview.actual_expenses`, and the month's actual Income. There is no second calculation: the response reuses the report model unchanged.

The current month additionally carries `safe_to_spend`: the figure the Today screen shows, with every input and intermediate result behind it, so an external client can reproduce it by hand. It carries `amount` (the figure, rounded down to a whole 10 and never negative), `label`, `as_of`, `daily_total`, `daily_envelopes` (the contributing Daily spend envelopes and their balances, in the order they were summed), `days_in_month`, `remaining_days` (the days that remain in the month, today included), `raw_per_day`, `rounded_amount`, `rounding`, and `zero_reason` (`null`, or `negative_daily_total`, `zero_daily_total`, or `below_rounding_step` naming the condition behind a 0). Only Daily spend envelopes contribute; a Reserved or Savings balance never does, and the Daily total is the persistent envelope balance as of `as_of`, not the month's plan. Past and future months never carry the object. The figure comes from the same shared rule as the Today screen and the PDF report (`report.php:report_safe_to_spend`, a port of `finance.js:Finance.safeToSpend`), so it is one rule and not a second calculation.

`coverage` is `full` for a retained Month and `actual_only` for a Month that exists only as actual spending. A Month outside the retained running data that the funding-recommendation engine already knows — a Month with a recorded Transaction but no retained Month row — is reported as `actual_only`: its Daily/Reserved/Savings rows carry only `envelope_id`, `envelope`, and `actual`, their totals carry only `actual`, and `overview` carries only `actual_expenses`, because Plan, Funded and Balance do not exist for it. Its actual spending is the same actual the funding recommendations use (the `transactions` table, selected by `spent_date`, with Refunds subtracted and a monthly total never below zero), so the read actions never disagree about one month. A range that contains neither a retained Month nor any recorded Transaction still returns the usual 404 "No months found in selected range".

The response also carries `savings_goals`: one entry per Savings Envelope that has a goal — `envelope_id`, `envelope`, the target `goal`, the Envelope's persistent `balance`, and the derived `progress_percent`. The progress uses the same rule as the Today and Month goal bars: Balance ÷ goal, never negative, and possibly above 100%. FIN stores no goal deadline, so none is reported.

Income is the `income` Account event type only, bucketed by the calendar month of `event_date` and reported as a month total plus a list of `{date, amount, note}`. Transfer, Refund, and Expense events are ordinary account events too and never count as Income. The same effective-to-date rule as the report applies, so a future-dated Income event is not yet actual. The report exports and this API bucket Income with the same shared helper (`report.php:report_income_events_by_month`), so there is one query and one rule.

Planned income (Month salary), exchange rates, the transaction list, transfers/adjustments, and every note that is not an Income note are deliberately excluded from the response. The endpoint is read-only and exists for trusted external read-only clients; it is not a general integration platform.

### Transactions (read-only API)

`GET api.php?action=transactions&from=YYYY-MM&to=YYYY-MM` returns the individual authoritative Expense and Refund Transactions whose `spent_date` falls in the inclusive calendar-month range. An optional `envelope_id` query parameter restricts the result to one exact Envelope. The response carries `from`, `to`, and `transactions`; each transaction carries `id`, `date`, `envelope_id`, `envelope`, `description`, `amount`, `kind`, and `account`.

The endpoint shares the PDF report's transaction-row selector. The transaction's source-plan Month is not authoritative; selection and ordering use `spent_date`. Rows dated after today are excluded. Expense and Refund are both included, with the PDF's signed actual-spending convention: Expense is positive and Refund is negative. `kind` is `expense` or `refund`. Account-to-Account Transfers, Envelope Transfers, Funding, adjustments, and other movements are not Transactions and never appear in this list. `account` is the display name of the transaction's authoritative optional payment Account, or null when that Account is unknown; an Envelope default is never inferred for a Transaction.

The inclusive range may contain at most three calendar months. A longer range is rejected with HTTP 400 and `Transaction range cannot exceed 3 months`. The endpoint is read-only and exists for trusted external clients; it is not a general integration platform.

### Transfers (external client API)

`GET api.php?action=transfers&from=YYYY-MM&to=YYYY-MM` returns the Transfers of the inclusive calendar-month range. The response carries `from`, `to`, and `transfers`; every transfer carries `kind`, which is `envelope` or `account`.

An `envelope` transfer is money moved from one Envelope to another. It carries `id`, `date`, `from_envelope`, `to_envelope`, `amount`, `note`, `undone`, and `payment_id`. The row is the monthly report's own transfer-out movement, so the date, amount, note, and Envelope names are exactly the ones the report shows as `Move money` for that move, and `undone` says whether the move has been undone.

An `account` transfer is money moved between two Accounts. It carries `id`, `date`, `from_account`, `to_account`, `amount`, `currency`, `note`, `linked_to_statement_row`, and `payment_id`. The amount is the authoritative Currency amount of the Account event, the same amount the Accounts screen shows for that transfer. `linked_to_statement_row` is `true` when the transfer is linked to an imported statement row through a reconciliation link.

Selection follows the monthly report: the calendar month of the transfer date is authoritative, and future-dated transfers are excluded. The inclusive range may contain at most three calendar months; a longer range is rejected with HTTP 400 and `Transfer range cannot exceed 3 months`. Expense and Refund Transactions, Income events, Funding movements, Balance observations, and Balance adjustments are not Transfers and never appear in this list. Account numbers are never reported.

One real payment can be recorded twice in FIN: as an Envelope Transfer between two Envelopes that have different default Accounts, and as an Account Transfer between those two Accounts. FIN stores no explicit link between those two records. The action therefore recognizes them the way FIN already recognizes the same payment for statement reconciliation — the same amount within the existing amount tolerance, the same two Accounts in either direction, and the same date within the existing date tolerance — and gives both rows the same `payment_id`. A client that groups by `payment_id` counts such a payment once instead of adding it up twice. Every row belongs to at most one pair, so an ambiguous case never links a row to more than one partner, and a move inside one Account is never the Account side of a real payment.

The endpoint is a GET action that changes no data; a POST to it needs the ordinary FIN session.

### Account balances (read-only API)

`GET api.php?action=accountBalances` returns the current balance of every Account. The response carries `as_of` (the date the balances are effective as of), `currency` (the database Currency), and `accounts`. Each Account carries `id`, `name`, `currency` (the currency the reported balance is expressed in), `balance`, `balance_known`, `status` (`active` or `archived`), and `last_observation_date` (the date of the latest Balance observation on or before `as_of`, or null when the Account has none).

There is no second balance calculation: the endpoint reuses `accounting.php:get_accounts()`, the exact source of the Accounts screen's account rows, so the reported balance is the balance that screen shows for the same Account. An Account whose Balance is unknown reports `balance_known: false` and `balance: null`; an Account in the Helper currency reports its balance in the Helper currency, and falls back to the Currency amount when that conversion is not available, exactly like the Accounts screen.

Archived Accounts are part of the answer and are never filtered out; they carry `status: archived`. Account numbers are never reported. The endpoint is read-only and exists for trusted external clients; it is not a general integration platform.

### API token

An external client may read `action=history`, `action=transactions`, `action=transfers`, `action=fundingRecommendations`, and `action=accountBalances` without a browser session by sending `Authorization: Bearer <token>` on a GET request. The same token may additionally perform the single write action `action=setPlanAmounts` on a POST request. The token is compared by SHA-256 with `hash_equals` against a hash stored in `auth.api_token_file`, outside SQLite and outside the FIN web root, with the same strict file checks as the Master PIN file (regular file, not a symbolic link, `0600` or `0640`). `php api.php create-api-token` generates a random token, writes only its hash to that file with the required ownership and permissions, and prints the token once; running it again replaces the stored hash and therefore revokes the previous token. There is no status, list, or delete command, and the stored hash is never printed or logged.

The header is ignored everywhere else: every other GET action and every other POST still requires the ordinary FIN session, and the token creates no session and needs no CSRF token. A missing or invalid token falls through to the existing 401 response, and no Master PIN throttle is involved because no PIN is checked.

### Plan amounts (write API)

`POST api.php` with `{"action":"setPlanAmounts","year":2026,"month":11,"amounts":[{"envelope_id":1,"amount":4000}]}` sets the planned amount of Plan rows for one Month. It is the only write the API token may perform; the ordinary FIN session may call it as well.

The Month must already exist; the action never creates a Month and answers HTTP 404 `Month not found` for an unknown one. Only the listed Envelopes change, and a request carries at least one and at most 500 rows with each Envelope listed at most once. Every amount is an absolute amount in the database Currency with no currency field, zero or more, with at most two decimals. An existing Plan row must be Planned: a Funded or Skipped row is rejected with HTTP 409 and the error names the row. A row that does not exist yet is created as a plain Planned row without Direct debit and without a payment date. The action never changes a row's status or any other field and never creates a Funding movement, a Transaction, or a Balance change. The request is all-or-nothing: an invalid row leaves the Month untouched and the error names that row. The response carries `ok`, `year`, `month`, `currency`, and one `rows` entry per requested Envelope with `envelope_id`, `envelope`, `previous` (null when the Plan row did not exist), `amount`, and `status`.

### Imported Source Budget Data

The importer reads the DKK/EUR-specific source budget workbook month blocks into a canonically migrated DB v2.23 database. It validates that FIN Currency is DKK and Helper currency is EUR, preserves a missing source rate as unavailable, creates or reuses Envelopes, links every non-income Entry to its Envelope, and maps spreadsheet markers such as `x` to funded Entry/movement state.

## Existing Database Entities

The current SQLite database contains these high-level entities:

### `schema_migrations`

Records each applied database migration version, name, and application timestamp. The v1.0 migration is the baseline for existing and new databases. Migration v1.1 adds the envelope plan-currency preference and normalizes allocation values using each row's own monthly exchange rate. Migration v1.2 replaces the old hidden-envelope presentation with explicit visibility modes and forces Daily spend to remain normally visible. Migration v1.3 enforces envelope-name uniqueness after trimming and without regard to capitalization. Migration v1.4 added persisted monthly account-transfer reminders. Migration v1.5 converts completed reminder rows into funded allocations and removes the independent reminder table so account transfer state is derived from allocations. Migration v1.6 adds Direct debit allocation markers and uniquely linked automatic expense transactions. Migration v1.7 adds linked envelope transfers and transfer references on envelope movements. Migration v1.8 adds independent Savings goal-progress visibility, defaulting existing Savings goals to Month only. Migration v1.9 restores independent monthly account-transfer reminders and seeds Transferred only for accounts whose existing positive, non-skipped allocations were all Funded. Migration v2.0 adds EUR amount and original-currency fields to transactions and backfills historical expenses from each expense month's exchange rate. Migration v2.1 makes movements date-effective, synchronizes every manual/automatic transaction with one expense movement, funds positive Funded DD allocations, repairs existing DD funding/expense records, and protects future-linked source months during retention. Migration v2.2 adds optional per-envelope manual historical baselines and envelope/month recommendation exclusions without changing financial ledger tables. Migration v2.3 adds empty typed account/event/movement and month-funding-context tables, a nullable payment-account field and a now-retired experimental posting-scope field, and makes transaction source month nullable/non-authoritative while preserving every existing transaction and movement. Migration v2.4 historically added empty onboarding session/account-opening/envelope-review tables; it created no financial records. Migration v2.5 adds nullable `envelopes.default_account_id` with `ON DELETE SET NULL`; it does not backfill from legacy labels or modify transactions. Migration v2.6 refuses silent deletion if any onboarding-only table is non-empty, verifies all transactions use the one normal model, reconstructs `transactions` without the unused posting-scope column while preserving every shared row field/ID/reference, adds nullable DD payment-source/finalization fields without backfill, and drops the three verified-empty obsolete tables. Migration v2.7 refuses to remove a populated legacy users table until a valid external Master PIN hash exists, creates the single installation principal and WebAuthn credential store, then drops users without touching financial tables. Migration v2.8 adds only disposable `statement_imports` and `statement_rows` evidence tables and indexes; it creates no evidence rows and changes no financial/domain table. Migrations v2.9–v2.12 add ordinary Expense/Transfer reconciliation and converge Account-to-Account events on Transfer. Migration v2.13 adds the No FIN action disposition and a database CHECK requiring exactly one Transaction, Transfer, or No FIN action disposition per reconciliation row. Migration v2.14 adds the explicit Expense/Refund Transaction kind, extends Account events with Refund, preserves Account-event AUTOINCREMENT history while rebuilding the CHECK, and enforces one generic Transaction-linked Expense/Refund Envelope movement. Migration v2.15 widens the existing `accounts.type` classification CHECK to Asset/Liability/Credit card through a preservation-safe Account-table rebuild. Migration v2.16 persists the database Currency, maps Account/Envelope preferences to `CURRENCY | HELPER_CURRENCY`, renames generic Currency values to `amount`, removes redundant Helper and input-metadata columns from actual FIN tables, makes the current Month rate nullable without changing stored rates, and preserves values/IDs/references/AUTOINCREMENT sequences. Migration v2.17 maps old normalized Envelope/Transfer Account names to unique real Accounts where deterministic, leaves unmatched references null, removes `accounts.type`, `envelopes.account`, `entries.account`, and `month_account_transfers`, and replaces Envelope-transfer Account text with nullable real Account foreign keys. Foreign-key and integrity verification run before commit. Migration v2.20 adds `account_balance_observations`, removes `accounts.ledger_start_date`, converts trustworthy legacy Account opening/reconciliation dates to migration observations, deletes only those obsolete events/movements, and restricts active Account event types to income, expense, refund, and transfer. Migration v2.21 mirrors that model for Envelopes: it adds `envelope_balance_observations` with `UNIQUE(envelope_id,effective_date)` and `ON DELETE CASCADE` from `envelopes`, reuses the latest-observation-plus-later-movements calculation as Accounts, and retains the ordinary movement-sum fallback when no observation exists. Migration v2.22 validates and deletes every obsolete Envelope `opening_balance` / `adjustment` / `reversal` movement without creating a compensating observation, while preserving all existing observations and ordinary Funding/Expense/Refund/Transfer movements byte-for-byte; affected Balances intentionally change because those legacy corrections are invalid under the observation model. Migration v2.23 adds the two independent Envelope attributes `amount_source` (`history` by default) and `month_end_behavior` (`accumulate` by default) as plain columns; it changes no financial record, no balance and no existing Envelope.

### `auth_principal`

Stores exactly one random 32-byte opaque WebAuthn user handle for the installation owner. It is identity-binding metadata only and has no financial foreign keys.

### `webauthn_credentials`

Stores multiple credentials for that one principal: binary credential ID, public key, signature counter, internal transport metadata, user label, created/last-used timestamps, and optional revocation timestamp. Private authenticator keys and biometric/device-PIN data never leave the platform authenticator. Revoked rows remain audited but are excluded from assertion and normal Security lists.

### External authentication configuration

The Master PIN hash is not a database entity. Tracked `config.php` defines production `auth.master_pin_file` as `/etc/fin/master-pin.hash` (`root:www-data`, `0640`; directory `root:www-data`, `0750`) and `auth.throttle_file` as `/var/lib/fin/master-pin-throttle.json` (`0660`, runtime-owned; directory `root:www-data`, `0770`). Ignored `config.local.php` may recursively replace both with explicit absolute local-development paths; it is the same source for CLI and web, and auth path environment overrides are intentionally unsupported. `master-pin-status` prints only the resolved PIN path and yes/no. Unsafe existing files fail with a configuration diagnostic rather than being treated as absent. Neither path/file is returned by APIs or included in SQLite backups/reports/exports.

Tracked `config.php` also defines production `auth.api_token_file` as `/etc/fin/readonly-token.hash` (`root:www-data`, `0640`; same directory as the Master PIN file). It stores the SHA-256 hash of the current API token and nothing else, so the token itself exists only in the operator's hands and in the client's configuration. An installation that does not configure the path simply has no API token. The path is never returned by APIs or included in SQLite backups/reports/exports.

Tracked `config.php` and the `auth.php` runtime fallbacks carry only neutral `webauthn_rp_id` / `webauthn_origin` placeholders (`example.com`). Each installation supplies its own relying-party id and public origin through the ignored `config.local.php`; a relying-party id only works on the host it names, so an installation that keeps the placeholder cannot enroll or assert a device credential on its real host. This is a deployment-configuration value, not application data.

### `months`

Stores monthly plans, including nullable `exchange_rate`, salary, and notes. A missing rate remains null/unavailable; no current schema, Month creation path, UI, or importer invents a default. The table retains an ignored legacy `target_savings_percent` column for compatibility, but it is no longer exposed, saved, copied, imported, or reported.

### `envelopes`

Stores global spending Envelopes with an optional nullable real default payment Account, note, role, optional Savings goal, independent goal-progress and Balance visibility settings, the amount source (`history`/`fixed`), the month-end behavior (`accumulate`/`reset`), and sort order. The default affects only future Add Expense preselection and is applied only while the referenced account is active.

### `entries`

Stores monthly Plans and historical Income entries. Every non-Income Entry belongs to exactly one Envelope, with one Plan per Envelope per Month. `is_bs` marks Direct debit Plans; `bs_payment_account_id` is an explicit nullable payment-Account instruction and `bs_expense_finalized_at` records when planning no longer owns an effective/deleted generated expense.

### `envelope_recommendation_settings`

Stores one optional user-confirmed Currency/month historical baseline and source note per Envelope. Baselines are external reference metadata, not transactions, plans, recommendations, or confidence evidence.

### `recommendation_exclusions`

Stores envelope/year/month observations excluded only from recommendation statistics. These rows do not alter Actual Expenses, reports, Balances, Month status, safe-to-spend, Plans, or movement history.

### `transactions`

Stores complete authoritative Expense and Refund transactions. `kind TEXT NOT NULL DEFAULT 'expense' CHECK(kind IN ('expense','refund'))` distinguishes the two semantics. Transactions retain a nullable, non-authoritative source `month_id` and may link to entries and envelopes, while actual spending is selected and bucketed only by calendar `spent_date`. `payment_account_id` is nullable and remains unknown for migrated history. Every Transaction creates its normal signed Envelope effect and optional Account effect regardless of date or provenance. Editing without a supplied kind preserves the stored kind; explicit internal transitions update the linked movement/event signs atomically. A generated Direct debit Expense defaults to the first day of its source Plan's Month when no payment date is entered and remains linked for provenance; before that date it is scheduled and plan-controlled. At/after `spent_date` it is finalized as an ordinary editable/deletable Actual Expense and plan changes cannot rewrite/delete it. `is_automatic` remains provenance only.

### `accounts`, `account_events`, and `account_movements`

DB v2.3 defines the Account ledger separate from Envelopes. DB v2.10 makes `accounts.account_number` mandatory: every Account carries a stable, user-configured identifier. Statement-import ownership is established only by the owner's explicit Account selection; the configured identifier remains available for informational display and exact counterpart-Account recognition during deterministic Transfer reconciliation. Account Name is presentation only and is never used as identity. DB v2.16 makes `accounts.currency` the checked presentation preference `CURRENCY | HELPER_CURRENCY`; changing it after history rewrites no financial record. DB v2.17 removes Account classification and old Account-label fields. DB v2.20 adds `account_balance_observations`, removes `accounts.ledger_start_date`, converts trustworthy legacy opening/reconciliation dates to migration observations, deletes only those obsolete events/movements, and restricts active Account event types to income, expense, refund, and transfer. Each Account/date has at most one signed absolute end-of-day observation with source `manual | account_creation | migration | statement`. Balance on date `D` is the latest observation on or before `D` plus movements dated strictly after that observation through `D`; same-day movements are already represented by the observation. A movement dated on the observation date is already inside the observed end-of-day balance and is never added again; record a new observation on a later date when that day's spending was booked after the observation was taken. Without an applicable observation, the API returns `balance_known=false` and `balance=null`, even if movements exist. Helper-currency observation input converts using its observation date before one authoritative Currency Balance is stored. Future observations are rejected. Transactions, Refunds, Income, Transfers, and statement reconciliation have ordinary semantics at every date and never require Balance knowledge. Archiving changes only active state and preserves all references. Renaming an Account or editing its Account number never alters financial history; the Account's identity (number) is decoupled from display. DB v2.21 mirrors the Account Balance observation model for Envelopes: `envelope_balance_observations` holds one signed authoritative Currency Balance per envelope/date, with source `manual` and the same date-effective calculation as Accounts. Recording an Envelope Balance observation creates no movement, transfer, transaction, or correction event; same-day movements are already represented by the observation. Deleting an observation restores the movement-derived balance from the next-latest observation (or the ordinary movement sum from zero), never inserts a reversal, and never touches Transfer, Expense, Refund, or Funding records. The obsolete Envelope Reconciliation / Apply adjustment / Adjustment undo controls and corresponding API actions are absent. DB v2.22 removes the obsolete movement rows those workflows left behind without compensation so a later-dated legacy correction cannot override a user-recorded observation.

### `month_funding_context`

Stores dormant historical Plan-Funding context metadata. It is not connected to current Account creation, Month status, or Plan summary.

### `statement_imports` and `statement_rows`

DB v2.8 stores disposable raw external statement evidence separately from every authoritative financial table. Imports record the selected Account, provider, filename/checksum, detected statement period, parser version, and row/problem counts. Rows retain nullable normalized dates/`amount`, evidence `currency`/description, normalized description, provider reference/type, running balance when available, stable fingerprint, repeated-row occurrence index, parse problem, and raw provider fields.

`statement_rows.import_id` cascades only from evidence import to evidence rows. Account references are restrictive. No Transaction, Account event/movement, Envelope movement, Plan, Envelope, or Account has a foreign key to statement evidence. Purging imports/rows therefore cannot delete or alter financial truth. Reconciliation uses separate disposable links to stable transaction/account-event IDs or the explicit No FIN action disposition; no mandatory target ID exists on raw rows.

Evidence persistence does not create Transactions or Account movements. A `running_balance` stays evidence: no import path promotes a daily-closing `running_balance` to an absolute Account Balance observation, and ambiguous, inconsistent, missing, conflicting, or future Balance evidence remains inert. An Account Balance observation is recorded only by the owner, through Account creation or the Record Balance action. After commit, the deterministic statement processor may create/link complete ordinary records through existing authoritative paths; exceptions remain unresolved. `statement_rows.amount` is the one normalized effective external Account movement in evidence `currency`; raw source amounts, fees, descriptions, currency, balance, and other fields remain unchanged in `raw_fields_json`. The signed account and signed card source formats are DKK evidence independently of FIN configuration and expose one signed `Beløb` with no separate fee field; the debit/credit format exposes mutually exclusive debit/credit columns and no separate fee field. The fee format requires `Beløb` and `Gebyr` and uses `Beløb - Gebyr`, because every non-zero-fee row across the inspected exports matches that exact running-`Saldo` delta (outgoing, incoming, and fee-only rows alike). A negative/invalid fee becomes problem evidence rather than receiving invented semantics. Text repair preserves already-valid UTF-8 sequences while decoding localized Excel escapes and only the actual mojibake fragments. When Reconcile encounters legacy malformed description bytes, it idempotently rewrites only `description_original` and `description_normalized` before assessment/serialization; all evidence identity, raw fields, dates, amounts, Account links, reconciliation state, and financial tables remain unchanged. Statement evidence contributes nothing to balances, safe-to-spend, Month Status, reports, recommendations, or history. A later confirmed event must be created as an ordinary FIN financial record with normal manual-equivalent accounting before provenance is linked.

### `statement_reconciliations`

DB v2.9 adds the ordinary-expense reconciliation-link table; DB v2.11 extends it to Transfer events; DB v2.13 adds the explicit No FIN action disposition. Each row points at one unique `statement_row_id`, and a schema CHECK enforces exactly one of `transaction_id`, `target_account_event_id`, or `disposition='no_fin_action'`. Cascades remain disposable provenance only:

- dropping statement evidence drops its reconciliation row but never an authoritative financial record;
- deleting a linked ordinary Expense or Transfer drops its provenance link while leaving the statement row unresolved;
- No FIN action has no financial target and removing it only returns the evidence row to unresolved;
- no authoritative financial table holds a foreign key into `statement_reconciliations`.

Deterministic processing first links exactly one compatible existing Transaction/Transfer/Income event. A Transaction paid from the statement Account is the strongest match; a Transaction that carries no payment Account at all is considered only when no Transaction paid from that Account matches, so a known payment Account is never traded away for an unknown one, and a Transaction paid from a different Account is never a candidate. A linked Transaction without a payment Account adopts the statement Account, because the statement row is the proof of which Account paid. When no Transaction matches the amount, one prior Envelope association for the exact normalized description together with exactly one unlinked Plan-derived Direct debit Transaction in that Envelope within four days links that planned payment to the statement row and gives the Transaction the real statement amount; when several real debits claim the same planned payment, the rows stay an exception and remain manual instead of splitting one planned amount over several payments. With no candidate, one exact configured counterpart Account identifier creates an internal Transfer; one prior Envelope association for the exact normalized description, Account, and direction/type creates an Expense/Refund; exact persisted profile-classified Income creates ordinary Income. Multiple candidates, multiple counterpart Accounts, missing/ambiguous Envelopes, unsupported conversion, and unclassified credits remain exceptions. The exception UI shows the current Account name and its full unresolved total, followed by one table for only the next batch of at most 32 rows with Date, Description, Amount, and Reconcile columns. The total comes from a separate count-only query using the same unresolved eligibility conditions; full-set assessment and history preselection are never performed. Each row has one grouped select whose neutral value is `—`; Envelope choices and other active Account choices remain alphabetized and structurally mutually exclusive, the statement Account is absent, and the final option is exactly `IGNORE`. On explicit load, ordinary Transaction notes with an existing Envelope, ordinary Transfer-event notes, and prior reconciled statement descriptions—including `no_fin_action` outcomes—are lowercased, trimmed, whitespace-collapsed, and stripped of punctuation only for comparison. Similarity is the maximum of the whole normalized-string Levenshtein score and the best same-length local-window Levenshtein score over compact normalized strings with whitespace removed; local windows are disabled when the shorter compact string has fewer than four characters. The highest valid score of at least 75 across every source wins, with the latest applicable source record breaking exact score ties. An Envelope or Transfer winner preselects the existing `envelope:<id>` or `account:<id>` value. A Transfer destination is preselected only when the statement evidence itself names a counterpart Account; a row whose evidence names none keeps the destination to the owner's choice, because the statement cannot say where the money went or came from. A `no_fin_action` winner emits no preselection and does not fall back to a lower-scoring categorized outcome, so the dropdown remains neutral and the owner must choose `IGNORE` explicitly. Envelope history may come from any Account. Transfer history requires the current statement Account to be the event source for outgoing evidence or destination for incoming evidence, and its counterpart Account must still be active; reconciled Transfer descriptions additionally retain the same-statement-Account and same-direction restrictions. Unavailable targets are ignored. A categorized preselection is inserted directly into the ordinary non-persisted pending classification map, has no separate suggestion state or UI, can be retained or changed, and is never saved automatically. An Envelope invokes `statement_reconciliation_create_expense` for outgoing evidence or `statement_reconciliation_create_refund` for incoming evidence; an Account invokes `statement_reconciliation_create_transfer_from_evidence`, which derives source/destination from evidence direction and calls `record_account_transfer`. One real movement can be reported by two statements: the debit on one Account's statement and the credit on the other Account's statement. When a Transfer for exactly that movement already exists (the same two Accounts in the same direction, the same amount within 0.01, the same date within the four-day tolerance) and its side for this row's Account is not already reconciled to a statement row, the row links to that Transfer instead of recording the same payment twice; when no Transfer or several Transfers match, a new Transfer is created, because a visible duplicate is preferable to a wrong link; `IGNORE` persists the existing `no_fin_action` reconciliation disposition, associated with the statement Account through its evidence row, and creates no financial record. The backend strictly rejects malformed, unknown, invalid, self-Account, archived-Account, duplicate-row, legacy dual-field, and cross-Account selections rather than guessing. A row whose Expense or Refund would repeat an existing Transaction exactly is not reconciled on the first Save: the row keeps its classification, the owner is told which existing Transaction it repeats, and one confirmation reconciles the affected rows. A deterministically processed row that would repeat an existing Transaction exactly is reported as an exception instead of being written a second time, so it stays in the unresolved queue for the owner to confirm. Partial Save submits selected rows, preserves independent per-row results, retains failed choices for retry with each backend error rendered on its row until the choice changes or the dialog reloads, and leaves unselected rows unchanged. A successfully ignored row leaves the unresolved queue, frees its place for normal batch refill, and has no un-ignore control. Save is disabled and shows its inline loading indicator from the click through completion of both the save and asynchronous refill; only after fresh content or an error is rendered does it return to the enabled/disabled state implied by the current selections. The browser reloads from the same Account so the count updates while assessment and history preselection refill only that bounded batch; once that Account is exhausted, it advances through later active Accounts in the existing alphabetical order without wrapping and displays the next Account's count. Initial open keeps the same first-nonempty alphabetical fallback, and any load/refill failure is rendered in the dialog instead of being swallowed into a false empty state for the first Account. Close writes nothing. The dialog's Save/Close area remains visible while its list scrolls. Post-save refill does not re-run account-wide automatic processing. Every path consumes `statement_rows.amount`, derives authoritative Currency server-side, and writes financial activity plus provenance atomically. Removing any link remains provenance-only.

### Removed onboarding tables

Migration v2.4 historically created `onboarding_sessions`, `onboarding_account_openings`, and `onboarding_envelope_reviews`. The product concept was rejected before any production rows were created. Migration v2.6 verifies these obsolete tables are empty and drops them. They have no replacement or generalized form.

### `envelope_movements`

Stores persistent envelope balance movements. Funding adds money, Expense subtracts money, Refund restores money, and linked transfer-out/transfer-in rows move money without changing the global total, with each row applying only when its `movement_date` is on or before the requested cutoff. DB v2.22 removes obsolete `opening_balance` / `adjustment` / `reversal` rows after validating they have no unsafe financial ownership or retained reversal references; current databases retain none of those legacy movement types. One generic partial unique index enforces at most one Transaction-linked Expense/Refund movement. History cleanup consolidates removed movements into one non-undoable `archive_rollup` brought-forward movement per affected Envelope, excluding any movement covered by an existing observation as of the archive cutoff.

### `envelope_balance_observations`

DB v2.21 persists one signed authoritative Currency Balance per envelope/date. Columns are `envelope_id`, `effective_date`, `balance`, `source`, `note`, `updated_at` with `UNIQUE(envelope_id,effective_date)` and `ON DELETE CASCADE` from `envelopes`. Recording or upserting an observation creates no movement, transfer, transaction, or correction event. Calculation: balance on date `D` is the latest observation on or before `D` plus movements strictly after that observation through `D`; same-day movements are already represented by the observation. Without an applicable observation the API falls back to the ordinary movement sum from zero, so existing envelopes without observations keep their historical movement-derived balance unchanged. Future observation dates are rejected.

### `envelope_transfers`

Stores each audited Transfer once with source/destination Envelope identity/name snapshots, nullable actual source/destination Account references, amount, date, and note. Two `envelope_movements` rows link to the transfer so it can be undone atomically.

### Indexes

Indexes exist for:

- entries by month and sort order,
- entries by envelope,
- transactions by month and date,
- transactions by envelope;
- recommendation exclusions by year/month/envelope;
- active WebAuthn credentials by revocation/creation.

### Current Runtime Data Observed

A fresh consistent read-only production v1.21.0 / DB v2.6 snapshot captured for the authentication migration contains:

- `schema_migrations`: 17 rows through v2.6;
- legacy `users`: 1 authentication-only row;
- `months`: 2 rows;
- `envelopes`: 30 rows;
- `entries`: 55 rows;
- `transactions`: 35 rows;
- `envelope_movements`: 56 rows;
- `envelope_transfers`: 0 rows;
- `accounts`, `account_events`, `account_movements`, and `month_funding_context`: 0 rows;
- non-null envelope defaults, transaction payment accounts, and DD payment accounts/finalization values: 0 rows.

Applying DB v2.7 first to a pre-release production copy removed the legacy user table, created one random principal, created zero WebAuthn credentials, and preserved every shared row/field across all financial/domain tables. Reports and recommendations were byte-equivalent, integrity was `ok`, and foreign-key violations were zero. No real credential was enrolled in the verification copy. Test and screenshots use only synthetic authentication data.

## Existing Workflows

### Version and Upgrade Workflow

The current source is FIN v1.37.1 with database v2.23. `VERSION` is the application source of truth, while `schema_migrations` records database upgrades. FIN v1.37.1 / DB v2.22 removes obsolete Envelope `opening_balance` / `adjustment` / `reversal` movements outright after strict reference validation; it creates no compensating observations, intentionally allows affected Balances to shift, and preserves every user-created observation plus ordinary Funding/Expense/Refund/Transfer movement byte-for-byte. FIN v1.37.0 / DB v2.21 extended the Account Balance observation model (FIN v1.36.4 / DB v2.20) to Envelopes: `envelope_balance_observations` holds one signed authoritative Currency Balance per envelope/date, the Balance tools dialog replaces the obsolete Envelope Reconciliation / Apply adjustment / Adjustment undo controls with a Record Balance dialog mirroring the Account Balance flow, the API removes the `reconcileEnvelope` / `undoEnvelopeMovement` actions, and balance/history calculation falls back to the full movement sum from zero only when no observation applies. Recording or deleting an observation never creates a movement, transfer, transaction, or correction event. FIN v1.36.4 / DB v2.20 introduced `account_balance_observations`, removed `accounts.ledger_start_date`, converted trustworthy legacy opening/reconciliation dates to migration observations, and restricted active Account event types to income, expense, refund, and transfer. Earlier source FIN v1.36.3 / DB v2.20 collapsed the Month view to five peer remembered collapsible sections (Month, Accounts, Envelopes, Plan summary, Funding) without a standalone Month action row, removed Asset/Liability/Credit-card classification and the legacy `envelopes.account` label completely from the active model, retired the monthly Account-transfer reminder state, retired the `month_account_transfers` table, switched the canonical funding vocabulary to Plan/Funded/Spent/Balance/Funding, made Plan summary list every current non-archived Account with selected-Month Plan, Funded, and the Account's general/current signed Balance, and removed the old Edit month and Accounts dialog entry points. The migration chain remains DB v2.8 (disposable statement evidence), DB v2.9 (ordinary-expense statement reconciliation links), DB v2.10 (mandatory `accounts.account_number` identity), DB v2.11 (ordinary Transfer reconciliation links), DB v2.12 (one canonical Transfer Account-event type), DB v2.13 (schema-enforced No FIN action disposition), DB v2.14 (explicit Refund), DB v2.15 (signed Balance/Accounts rebuild), DB v2.16 (canonical Currency/Helper-currency persistence), DB v2.17 (Account-model simplification), DB v2.20 (Account Balance observations), DB v2.21 (Envelope Balance observations), and DB v2.22 (obsolete Envelope Balance movement removal). Production updates run through `./scripts/update.sh`, which requires a readable valid Master PIN, rejects dirty tracked files, creates a consistent backup, pulls with `--ff-only`, installs locked runtime dependencies, migrates, and verifies versions.



Release finalization ends when the release commit and annotated version tag have been successfully pushed and their remote refs verified. Releasing does not include or authorize production access, a production update, the production updater, or production verification. A production update remains a separately requested operator operation under the established production procedure.

### Production → Development Refresh Workflow

`./scripts/refresh-dev-from-prod.sh` is run only from a development checkout with ignored localhost `config.local.php`, valid external dev Master PIN configuration, a stopped dev runtime, and required `FIN_PROD_SSH`. The production checkout/root/PHP/backup-directory settings are source-only. The remote `php api.php refresh-snapshot` command opens the production DB read-only through `create_sqlite_backup()`, creates one mode-`0600` uniquely named snapshot outside the web root, and reports only path/checksum/app/DB metadata. SCP is structurally remote-to-local; the script has no upload or reversible source/destination mode.

The local script downloads to an ignored same-filesystem stage, checks SHA-256, integrity, FKs, core schema, and that source DB is not newer than the candidate target. It obtains that target without a second registry by migrating an empty probe through `php api.php migrate`, then migrates/versions the stage through the same canonical commands. It verifies that `auth_principal`/`webauthn_credentials` have no financial/domain FK dependency, replaces copied production auth rows transactionally with exactly the current dev auth rows, and proves the non-auth domain fingerprint did not change during that operation.

Before installation it uses the existing online-backup helper to write a verified mode-`0600` old-dev backup under the mode-`0700` external backup directory. It rechecks active DB handles, removes journal/WAL sidecars only while dev is stopped, and atomically renames the stage over the fixed local `data/fin.sqlite`. Final canonical version, integrity, FK, auth fingerprint, domain fingerprint, permission, and open/read checks must all pass before `READY`. Final failure restores the old dev backup and securely retains the failed candidate. Dev external PIN/throttle/config/origin/session/server/dependencies/Git state and original statement export files are never copied or changed. The first real production refresh has not been run; implementation and tests use fake SSH/SCP plus synthetic temporary DBs only.

### History Retention and Backup Workflow

The running database normally retains the newest 12 month rows by policy; an older source month with a linked movement after the cutoff is retained temporarily so future value is not rolled up early. `php api.php archive-preview --keep-months=12` reports the cutoff and affected rows without changing data. `php api.php archive --keep-months=12` takes an immediate database write lock, integrity-checks the source, creates a complete SQLite backup plus SHA-256 JSON manifest, and only then performs cleanup in one transaction. Older movements are replaced by one brought-forward `archive_rollup` per affected envelope at the first retained month; old months, allocations, expenses, account reminders, eligible transfer records, and superseded movements are removed. Transaction-linked Account expense events are detached before old transaction detail is removed, retaining their signed movements. Cleanup aborts if any envelope balance or typed Account position changes, or if foreign-key verification fails, then integrity-checks and compacts the live database after commit.

History archives and upgrade backups share `$HOME/fin-backups` by default, controlled by `FIN_BACKUP_DIR`; archive commands may also use `--backup-dir=PATH`. Directories use mode `0700`, and archive database/manifest files use mode `0600`. Archive files are complete pre-cleanup restore points, while the running database exposes only retained months plus brought-forward balance context.

### Restore Workflow

NOTICE: stop the web server (and any other process that opens the SQLite database) before running `scripts/restore-backup.sh`. The script prints this notice at the start of its output and refuses to run while another process has the live database or its `-wal`/`-shm`/`-journal` sidecars open. The in-use check has a real limit on a running web install: a PHP worker only holds the database file open for the duration of one request, so `fuser` and `lsof` usually find nothing even though the application is very much in use. Stopping the application is therefore the safe default; the `fuser` / `lsof` check is a defense in depth for the rare case where a long-lived writer (cron job, second instance) has the file open.

`scripts/restore-backup.sh BACKUP_FILE` is the tested restore path for every SQLite snapshot FIN writes (`fin-upgrade-*.sqlite`, `fin-archive-before-*.sqlite`, `fin-prod-dev-refresh-*.sqlite`, `fin-dev-before-prod-refresh-*.sqlite`). The original backup file is never consumed: the script copies it into a stage file in the live database's directory, runs `PRAGMA integrity_check` and a schema-version check on the copy, and renames the copy over the live database in one atomic rename (within one directory). It refuses to run if `BACKUP_FILE` does not exist, is not a SQLite database, fails `PRAGMA integrity_check`, or carries a `schema_migrations` version newer than the running code's. Every refusal prints a single `Error: ` line naming the cause (including the SQLite message for an integrity failure) and exits with status 1; the script never fails silently.

The script captures the live database's owner, group and mode before any replace; after the rename it applies the captured attributes to the restored file. `chown` and `chgrp` are skipped when the staged file already has the target owner and group (the normal case when the operator runs the script as the account that owns the live database and the live database's directory is `setgid`). When the operator runs as that owner and the staged file already has the right owner and group, no `chown` / `chgrp` is attempted and the restore does not require any special privilege.

If a live database does not exist, the script falls back to a web-served default: mode `0660` (group-writable so the web server can write), group = group of the live database's directory (so a setgid directory inherits the right group onto new files), owner = current script user. The applied owner, group and mode are printed at the end.

If the script cannot apply the required owner or group (for example, the current user is not root and not the target owner, or is not in the target group), it stops with a clear error before replacing anything.

Run it as the account that owns the live database (typically the operator account that owns `data/fin.sqlite`); `sudo` is only needed when the live database's owner differs from the operator account. Development must use a temporary `FIN_DB_FILE` and a temporary `FIN_BACKUP_DIR`; the production database and production backup directory are never touched by the script or its test.

Before replacing the live database, the script writes a mode-`0600` safety copy to `$HOME/fin-backups` (or `$FIN_BACKUP_DIR`). When the live database is readable, the copy is a `VACUUM INTO` named `fin-restore-before-YYYYMMDD-HHMMSS[-N].sqlite`; when the live database is unreadable for `VACUUM INTO` (corrupt page header, locked WAL), the script falls back to raw copies of the database file and any `-wal`/`-shm`/`-journal` sidecars named `fin-restore-before-YYYYMMDD-HHMMSS-raw.{sqlite,-wal,-shm,-journal}`, mode `0600`. Both kinds of safety copy are kept, and the restore is itself reversible by passing the safety file to the same script. The script aborts without replacing anything only if both the `VACUUM INTO` and the raw copy fail.

After the safety copy, the script removes any stale `-wal`, `-shm`, or `-journal` sidecar files for the target database, renames the staged copy into place (atomic within the live database's directory), applies the captured owner/group/mode (skipping any operation the file already satisfies), and prints post-restore `php api.php version` and `php api.php master-pin-status` checks. On startup the running code applies any forward migrations recorded in the backup's `schema_migrations`, so an older backup brought forward is updated on open.

The script prints a clear list of what a restore does not bring back (Master PIN hash file, Master PIN throttle file, `config.local.php`, raw statement files under `import/`, application code, vendor/, node_modules/); saved `statement_import_profiles` travel with the database because they live in SQLite. The copy-not-move behavior, the owner/group/mode preservation (with the matching-skips-chown-and-chgrp default), the missing-live-database default (mode `0660`, group of the database directory), the raw safety-copy fallback, the in-use refusal, and the iteration-1 refusal cases (missing file, non-SQLite file, `PRAGMA integrity_check` failure, newer schema version) are all exercised by `tests/shell/restore-backup.test.sh`; each refusal case asserts the exact exit status, the `Error: ` line, and a log naming the cause. behavior on a real web-served installation (live database owned by a different user than the script operator) is documented but not tested in the writable FIN tree because the writable tree has no second user account.

### Test Workflow

After installing the Dompdf runtime dependency and development dependencies with `composer install` and `npm install`, `./scripts/test.sh` runs every automated layer. `unit`, `integration`, and `browser` arguments run individual layers. The browser layer uses one server, one worker-authenticated storage state, and one pristine migrated/seeded SQLite baseline per Playwright worker, then restores that baseline before each test instead of sharing mutations across tests or booting a server per test. Dedicated authentication tests opt into empty storage and exercise the real flow. Desktop is the default project; only the explicit `@responsive` subset also runs on mobile. Ordinary assertions use a ten-second timeout, reduced from fifteen after a five-second trial proved unstable on this host; longer whole-test timeouts remain only for measured workflows that need them, and retries remain zero. After browser tests, the runner prints the command for serving the Playwright HTML report on `0.0.0.0:9323`; that unauthenticated report server should be stopped after inspection. All database tests use in-memory or temporary SQLite files selected through `FIN_DB_FILE`; `data/fin.sqlite` is never used.

### PWA Installation Workflow

1. Serve FIN from an HTTPS origin; localhost HTTP remains valid only for development.
2. Open FIN in Android Chrome.
3. Use Chrome's Install app or Add to Home screen action.
4. Launch FIN from its home-screen icon in a standalone window.

The public shell can load from its cache, but authentication and all financial functionality require the backend network connection. Every API request—including challenges, PIN login, Device login verification, and logout—is network-only and returns no-store/private cache headers.

### Local Setup Workflow

The README describes this local workflow:

1. Copy `config.local.example.php` to ignored `config.local.php` and set explicit absolute external Master PIN/throttle paths plus localhost RP/origin values.
2. Confirm the resolved path with `php api.php master-pin-status`, then run `php api.php set-master-pin` and `php api.php migrate`.
3. Optionally import source budget data with `python3 scripts/import_source_budget.py source-budget.xlsx data/fin.sqlite --replace`.
4. Serve the app with `php -S 127.0.0.1:8080`.
5. Open `http://localhost:8080`; the first Master PIN login automatically enrolls this browser's local Device login.

### Authentication Workflow

1. The frontend calls `api.php?action=me`; a valid FIN session loads state without invoking WebAuthn.
2. Without a session, a local `fin.deviceCredentialId` causes an automatic assertion whose `allowCredentials` contains only that ID; no local ID goes directly to Master PIN.
3. The server consumes the short-lived session challenge once, verifies exact origin, RP hash, requested credential binding, active/revoked state, user verification, signature, and counter, then regenerates the ordinary session.
4. Unknown/revoked local IDs are removed and fall back to PIN. Cancellation shows retry/PIN actions; security failures remain generic.
5. Master PIN verification uses the external Argon2id hash and global server throttle, creates the ordinary session, and automatically starts non-discoverable local platform registration. The verified credential ID is then stored locally as a non-authoritative routing hint.
6. Security lists/renames/revokes credentials but does not enroll them manually. Logout destroys the session/cookie; the next startup uses only this browser's remembered credential.

### State Loading Workflow

1. The frontend requests `api.php?action=state&year=YYYY`.
2. The backend returns months, entries, transactions, envelopes, available years, and report months.
3. The frontend normalizes values and rerenders the UI.

### Month Planning Workflow

A user can:

- create a Month without a separate target-savings setting; savings intent comes from Savings-envelope Plans,
- edit year, month, exchange rate, salary, and notes inside the **Month** section, with orange highlighting on unsaved changes,
- add Envelope Plans,
- edit Envelope Plans,
- reset Envelope Plans,
- mark Plans by cycling P/F/S between Planned, Funded, and Skipped,
- copy one Month to another Month.

Copying a Month creates or updates the target Month, warns if the target already exists, deletes target Plans and their funding movements, copies source Plans, keeps Skipped rows Skipped, resets every other status to Planned, clears funding dates, and keeps existing target Transactions. An existing target keeps its own exchange rate, and copied values are converted with that target rate.

The P/F/S control on each Plan row changes only that Plan. Every positive Funded Plan creates or updates a Funding movement on its effective Funding date; changing it to Skipped or Planned removes that movement. Funded Direct debit Plans additionally create uniquely linked canonical Transactions with Envelope and explicit optional payment-Account effects on their payment dates; a blank payment date defaults to the first day of the Plan's own Month and remains editable through the date picker. Changing away from Funded removes Funding and only a future scheduled payment; effective Actual Expenses persist. There is no Account Planned/Transferred reminder state. Resetting a Plan deletes its saved row and returns it to the default zero-Currency planned state.

### Envelope Workflow

A user can:

- add an Envelope,
- edit Envelope name, default payment Account, and note,
- set an Envelope role,
- choose Normal, Hide balance, or Show on Today visibility for Reserved/Savings,
- choose `CURRENCY` or `HELPER_CURRENCY` as the Envelope's preferred Plan currency,
- set an optional Savings goal for Savings envelopes,
- choose whether percentage-only goal progress is Hidden, shown in Month only, or shown in Today and Month,
- independently hide or show an Envelope Balance,
- delete an Envelope.

Deleting an Envelope also deletes its monthly Plans so it disappears from every Month. Historical Expense/Refund Transactions remain for reporting, with their stored Envelope name preserved.

### Account Management Workflow

Accounts are reached from inside the **Accounts** section of Month but are global and independent of the selected Month. A user can:

1. See every current non-archived Account in **Plan summary** automatically with the per-Account Envelope balance sum (NOT the real Account Balance), plus selected-Month Plan and Funded.
2. Add an Account at any time with name, mandatory Account number, `CURRENCY | HELPER_CURRENCY` input/presentation preference, and optional Current Balance. Blank means Unknown; an entered signed amount, including zero, creates today's `account_creation` observation.
3. Edit Account name, Account number, and `CURRENCY | HELPER_CURRENCY` preference even after history. Editing does not change any event, movement, observation, reference, history, authoritative amount, or Balance.
4. Open Account history to inspect dated absolute observations alongside ordinary signed movements.
5. **Record Balance** by entering an actual signed absolute Balance and non-future date. This creates or updates the Account/date manual observation, creates no correction movement, and can be deleted from history. This is distinct from the statement-evidence **Reconcile** workflow.
6. Archive or restore the Account without deleting any Transaction, event, movement, observation, Envelope default, or historical reference.

Archived accounts remain visible under Archived and in history. They are excluded from Plan summary, normal new Paid-from choices, and Envelope defaults. An expense already linked to an archived account may still be edited without losing that authoritative historical value. Every date uses the same workflow.

### Add Expense Workflow

A user can:

1. Open the Add expense dialog.
2. Enter amount in the envelope's preferred currency.
3. Confirm or change the envelope. Visible and out-of-sight envelopes are shown in separate groups, each ordered by all-time recorded expense frequency; a new expense preselects the most-used visible envelope, falling back to the most-used out-of-sight envelope only when none are visible.
4. Confirm or override Paid from. The selected envelope's optional real default account is used only for this new-form preselection; Unknown remains valid.
5. Choose a date.
6. Add an optional note.
7. Save the transaction.

The transaction is associated with the active month and selected envelope. If a budget entry exists for that envelope, it is linked to the transaction. One transaction creates one envelope expense movement and, when Paid from is known, one linked account expense effect. Edit Expense shows the transaction's own payment account rather than reapplying the envelope default. Changing it synchronizes that one account effect idempotently. Older/import-resolved, current/manual, and effective automatic expenses use these same semantics. Editing derives financial context/rate from `spent_date` and preserves nullable/non-authoritative source Month; a required conversion fails when the event-date Month has no usable rate.

### Report Workflow

A user can:

1. Open the report/export dialog.
2. Select a from month and to month.
3. Download a human-readable PDF report or detailed XLSX export.

The backend validates the range and generates the file directly. PDF months use their own plan, `spent_date` actuals, funding, and date-effective closing cutoff; cross-month transactions therefore appear exactly once in the payment month. XLSX retains its detailed stacked layout while leaving the default Expense kind blank and identifying only Refund as exceptional Transaction metadata.

### Statement Evidence Import Workflow

The installation's saved Import Profiles describe the statement structures FIN can read. Every profile names its header location, dates, amount and currency, description/type/reference, running balance and Account-number source; the four structural shapes below are the ones the shipped generator and the private installation use.

| Source structure | Header location and discriminator | Dates | Amount and currency | Description/type/reference | Running balance | Account-number extraction |
|---|---|---|---|---|---|---|
| Signed account statement | Header is first row. Required shape includes `Dato`, `Tekst`, `Beløb`, `Saldo`, and `Kontonavn`. It is distinguished from the card structure by absence of the card marker in the first 20 data rows. | Booking date from `Dato`; no value date. | One signed `Beløb`; constant DKK. | Description from `Tekst`; row type from `Kategori`; no reference. | `Saldo`. | First non-empty `Kontonummer` data cell. |
| Signed card statement | Same header and parser shape as the signed account statement. A normalized `Kontonavn` data cell contains the stable card marker. | Same as the signed account statement. | Same as the signed account statement. | Same as the signed account statement. | `Saldo`. | First non-empty `Kontonummer` data cell. |
| Fee statement | Header is first row and includes `Type`, `Produkt`, `Startdato`, `Beskrivelse`, `Beløb`, `Gebyr`, `Valuta`, and `Saldo`; inspected variants may include completion date. | Prefer valid completion date for booking date; otherwise start date. Start date becomes value date only when different. | Effective amount is signed `Beløb - Gebyr`; fee must be non-negative. Currency comes from `Valuta`. | Description from `Beskrivelse`; row type from `Type`; no reference. | `Saldo`. | None in inspected exports; Account must be selected when no separate exact match exists. |
| Debit/credit statement (OOXML in a `.xls` file) | Pre-header precedes a header found within the first ten rows. Header has separate debit/credit columns and a transaction reference. | Booking and value dates are separate columns. | Exactly one of debit or credit must be present; debit becomes negative and credit positive. Currency comes from `Valuta`. | Base description plus payee for outflow or payer for inflow when not already present; row type joins direction and status; reference from transaction number. | Not available. | A digit-only value following the stable pre-header key `Račun:`. |

The signed-account/signed-card distinction is not a different parsing algorithm; it is only a secondary structural discriminator between two otherwise identical profiles. The remaining differences reduce to a few closed parser modes.

From Month → Accounts → Import statement, the owner chooses an XLSX/OOXML export, chooses one current Account, previews, and imports evidence. The Account selector always starts at neutral `—`; no Account is detected or preselected from file contents. Preview and Import both reject a missing selection with `Select an Account.`. Once selected, that Account is authoritative even when the statement's IBAN/Account number differs, is partial/domestic, or is absent. A parsed statement identifier remains informational display evidence only: it never selects, overrides, rejects, or blocks the chosen Account. FIN matches the file against the installation's saved Import Profiles by header structure; a file that matches no enabled profile is rejected with `No matching Import Profile`, and the profile identity is never asked of the user. The matched profile's evidence namespace remains internal: it is stored for provenance, duplicate detection, and debugging/tests but never exposed under a user-facing selector.

Preview shows the explicitly selected Account, the informational Account identifier parsed from the file (or `—` when none), the statement period, rows found, new rows, already-known rows, and parse problems. The normal saved-Import-Profile path accepts exactly 1,000 parsed statement rows but rejects 1,001 or more before any database write, reporting both the row count and the 1,000-row limit; no partial batch is imported. Import evidence writes only `statement_imports`/`statement_rows` under the selected Account. Parser v2 changes only the fee-format normalization: raw `Beløb` and positive charge `Gebyr` remain in `raw_fields_json`, while effective statement evidence `amount = Beløb - Gebyr`, including fee-only rows. A fee-only row `Beløb=0`, `Gebyr=9.99`, `Saldo 130.00→120.01` therefore normalizes to `-9.99 DKK`, not zero.

Exact-file identity is `(account, evidence namespace, SHA-256 file checksum)`; the evidence namespace is the matched profile's immutable `evidence_namespace`, stored in the `provider` column. Row identity uses stable namespace/account/date/amount/currency/description/reference/type details plus an occurrence index, so overlapping exports reuse known rows while repeated identical real rows remain separate. For the fee format, parser v2 keeps fingerprint identity deliberately on raw `Beløb`—the parser-v1 amount input—rather than the newly derived effective amount. Corrected normalization therefore does not make an overlapping external row appear new. New imports record `parser_version='2'`. Read-only pre-implementation checks found zero imported statement rows/links in both the development and production databases, so no existing row required rewriting and no DB migration/reimport was introduced. Invalid rows remain raw problem evidence. No classification, matching, suggestion, reconciliation decision, Transaction, Transfer, Account movement, Envelope movement, or Funding is created through import.

Statement evidence is disposable: deleting imports, rows, or future provenance links leaves all ordinary FIN financial records valid and unchanged. The dependency may point from statement evidence to ordinary records later; authoritative FIN data must never depend on statement evidence.

#### File-drop Import

FIN ships one inert statement-ingestion path used by both the UI upload button and the operator CLI `php api.php import-statements ACCOUNT_ID` command. The CLI scans `<fin-root>/import/` for new statement files under the explicitly supplied current Account; it never resolves ownership from file identifiers. Each file is matched against the installation's saved Import Profiles by header structure, and a file that matches no enabled profile is rejected with `No matching Import Profile`. Every matched file reuses the generic parser and the `import_statement_evidence` insert path. Source files remain disposable statement evidence. Persistence itself has zero financial effect; after the evidence commit, deterministic recognition runs automatically and only exception rows require a separate explicit reconciliation step.

Two directories exist under the FIN root:

- `import/` — pending source files the operator (or the UI) has dropped there;
- `import/imported/` — successfully imported source files.

Only one ingestion rule applies: explicit current Account validation → statement-structure matching against the installation's saved Import Profiles → existing inert evidence path → source file moved from `import/` to `import/imported/` on success. Byte-identical files at the destination are treated as already imported and the redundant source is unlinked; same filename with different content receives a deterministic timestamped suffix. UI uploads and file-drop imports converge on the same final source-file location.

The underlying drop scanner leaves every file in `import/` with outcome `NEEDS ACCOUNT` and reason `Select an Account.` when no Account is supplied. The normal UI always requires an explicit Account before Preview and Import.

The CLI is operator-invoked and produces no separate logfile; one concise outcome line per file is enough.

### Source Budget Import Workflow

The Python importer reads the supported DKK/EUR-specific source budget workbook, detects month blocks, invokes FIN's canonical migration for a missing/outdated target database, and validates DKK Currency plus EUR Helper currency before any import write. It imports explicit Month rates without a fallback, fails atomically when a missing rate is required for EUR-to-DKK conversion, writes one authoritative DKK amount, creates/reuses canonical Envelopes, links every non-income Entry, and creates the corresponding funding movement for funded rows. It optionally replaces existing Entries for matching Months. Legacy spreadsheet helper values for days/weeks remaining are neither imported nor stored; derived values must be calculated when needed.

## Existing Strengths

- The application is simple to install and update: it is made of static frontend files plus one PHP backend file and SQLite.
- Backend persistence already exists through SQLite.
- Authentication is single-principal, WebAuthn-first, server-throttled, and fail-closed; Master PIN recovery remains outside SQLite/web management.
- The app has a clear Today / Month separation, with envelope management integrated into Month.
- Month copying is implemented.
- Currency amounts are authoritative; Helper-currency amounts are derived when needed.
- Global envelopes exist independently from monthly allocations.
- Permanent Accounts exist independently from Months and expose audited signed opening/current Balances, history, adjustment, archive state, and a presentational `CURRENCY | HELPER_CURRENCY` preference.
- Envelopes hold an optional real default payment Account reference, role, persistent Balance, and optional Savings goal fields; there is no legacy Account label.
- Every non-Income monthly row is one Envelope Plan.
- Envelope balances are now based on movement history rather than resetting monthly plan totals.
- The add-expense workflow is short.
- PDF and XLSX export are implemented.
- Historical spreadsheet import support exists.
- The UI is responsive and includes mobile-specific layout behavior.
- The data directory is protected by `.htaccess` for Apache-style hosting.

## Existing Weaknesses

- Backend logic is concentrated in one large `api.php` file.
- Frontend logic is concentrated in one large `app.js` file.
- Automated coverage is intentionally focused on critical financial, migration, production-update, and browser smoke behavior; reports/exports and all validation branches do not yet have complete coverage.
- Backend logic is still difficult to unit-test in isolation because most domain behavior remains concentrated in `api.php`.
- The current database contains legacy `handled` entry statuses, while current backend/frontend code maps or migrates `handled` to `planned`.
- A general manual Account-to-Account Transfer action is not yet exposed outside statement reconciliation.
- Historical envelope roles are not snapshotted, so changing an envelope's global role can reinterpret old role tables and historical Month Status in both UI and PDF.

## Questions and Uncertainties

- What is the intended long-term meaning of entry status compared with actual transactions, especially for envelope spending?
- Should legacy `handled` statuses be preserved with a specific meaning, or is converting them to `planned` the intended final behavior?
- Is the importer’s fixed target year of 2026 intentional for all imports?

## Canonical Currency Model — Implemented in DB v2.16

FIN has exactly one authoritative accounting Currency, persisted immutably in `database_settings`. Every actual FIN financial amount is stored once in that Currency under the generic name `amount`. Account and Envelope `CURRENCY | HELPER_CURRENCY` presentation preferences never alter signs, accounting semantics, or historical values. Account models have no classification, mapping, or reminder state.

Helper currency is configurable and is used only for input, derived display, reports, and understanding external statement evidence. `accounts.currency` and `envelopes.currency` contain only `CURRENCY` or `HELPER_CURRENCY`; both preferences remain editable after history because they do not denominate persisted financial records.

`exchange_rate` means `Helper currency × exchange_rate = Currency`. A Helper input or evidence value becomes financial truth only after conversion with the Month containing the authoritative event date. Missing required event-date rates fail atomically. Rate or preference edits never rewrite entries, transactions, Account events/movements, Envelope movements/transfers, recommendation baselines, funding context, or other authoritative history.

Statement `amount` and `currency` remain inert external evidence. Reconciliation derives and validates the Currency amount server-side on confirmation. Expense, Refund, and Transfer creation plus provenance linking are atomic; Transfer amount is readonly in the UI and cannot be supplied as authoritative client input.

DB v2.16 removes the old parallel Helper values and currency-input metadata from actual FIN records, performs value-preserving generic renames, maps old Account/Envelope code preferences to canonical enums, preserves IDs/references/AUTOINCREMENT sequences, and rejects migration/configuration inconsistencies rather than guessing.
