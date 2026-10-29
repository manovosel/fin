# Project

## Current Product Decisions

The product center is the Today screen. Its primary output is a single large number:

```text
X dkk
safe to spend today
```

If the calculated value rounds to zero, the screen shows only:

```text
0 dkk
```

If the underlying value is negative, the screen shows:

```text
0 dkk
over plan
```

## Safe-to-Spend Rule

Safe to spend today is calculated from envelopes marked as Daily spend only.

The calculation is:

```text
remaining Daily spend envelope money / remaining calendar days in the month including today
```

The displayed amount is rounded down to the nearest 10 Currency units. Negative values are never shown as negative in the hero number.

## Envelope Model

Everything that holds money for a purpose is an envelope.

An Envelope is a persistent bucket of money for a specific purpose. It may choose an optional real default payment Account for new-expense convenience. Each Envelope also chooses `CURRENCY` or `HELPER_CURRENCY` as the preference for editing its monthly Plan amount. Envelope balances do not reset at the end of the Month unless the Envelope explicitly asks for it: a `reset` Envelope starts the new Month with exactly that month's allocation and inherits neither a leftover nor a negative, while a `fixed` Envelope keeps the monthly amount its owner set and never receives a FIN recommendation.

Savings outside the envelope model should be removed from the product. Fixed obligations, savings, investments, future purchases, subscriptions, and daily spending should all be represented as envelopes.

Every Envelope Balance is calculated as of a requested date from the latest absolute end-of-day Balance observation on or before that cutoff plus movements strictly after the observation through the cutoff; without an applicable observation it falls back to the ordinary movement sum from zero. Current Balance uses today. Balance tools record/delete absolute observations, create Envelope-to-Envelope Transfers as linked zero-sum movements, and show the resulting history. DB v2.22 deletes obsolete pre-observation `opening_balance` / `adjustment` / `reversal` movements outright because allowing one dated after an observation would incorrectly override the user's later absolute Balance; it creates no compensating observation. Funding, Expense, Refund, and Transfer movements plus every user-created observation remain untouched. Transfers between Envelopes with the same default Account require no bank confirmation; cross-account transfers require confirmation that the real bank transfer has already been completed. A transfer that would make the source negative requires explicit confirmation.

See `ENVELOPE_MODEL.md` for the full envelope model.

## Envelope Roles

Every visible envelope has a role.

### Daily spend

Daily spend envelopes are normal everyday spending money. They are always eligible for Today and always contribute to the safe-to-spend number, but exact zero-balance rows are omitted to reduce clutter. Daily spend cannot be combined with a balance-hiding visibility mode.

### Reserved

Reserved envelopes are money set aside for a known purpose. They do not contribute to the safe-to-spend number. They are managed in Month and appear on Today only when explicitly set to Show on Today.

### Savings

Savings envelopes are protected money. Savings is not one special envelope named "Savings"; it is any envelope whose role is Savings.

Savings envelopes never contribute to available or safe-to-spend money. Only Savings envelopes may have an optional goal. Goal progress is independently configured as Hidden, Month only, or Today and Month. When shown, it contains only the percentage token itself (for example, `50%`) and a progress bar—not “saved”, a completion icon, or current or target amounts. The bar caps visually at 100%, while the percentage may exceed 100% after reaching the goal. A Savings envelope shown on Today without visible goal progress continues to show its protected balance.

Expenses may be recorded against Savings envelopes, but this represents using protected money, not ordinary daily spending.

## Today Screen Hierarchy

The Today screen should show:

1. Safe-to-spend hero number.
2. Daily spend envelopes.
3. Optional Savings goal progress when at least one goal is configured for Today and Month, separated from Daily spend by a simple line without a title or collapse control.
4. Add expense action directly below the safe-to-spend card content, always visible and never collapsible.
5. The 24 most recent expenses inside a title-click collapsible panel whose open/closed state is remembered per user, with Show more revealing 16 additional rows per click and Show less returning to 24.

The Add expense envelope dropdown should show visible envelopes first and out-of-sight envelopes in a separate group. The Save expense button, like all dialog save buttons, is right-aligned. Within each group, envelopes are ordered by recorded expense frequency, with the most-used first. A new expense defaults to the first option—the most-used visible envelope, or the most-used out-of-sight envelope when no visible envelope exists.

A manual expense can be edited from Recent expenses using the same form. The one amount field follows the selected Envelope's `CURRENCY` or `HELPER_CURRENCY` preference; transactions persist only the converted authoritative Currency amount. Recent expenses displays every date-effective expense in Currency and derived Helper currency; its action cell shows only the edit icon, never a clipped-text ellipsis or trailing dot. A scheduled automatic transaction remains out of Actual/Recent expenses until its `spent_date`. Changing its amount, envelope, note, or date updates the original transaction and balance movement rather than creating a replacement. Delete is available inside the edit dialog. A future Direct debit Expense remains derived/read-only and links to its monthly Plan. Once `spent_date` is effective it becomes an ordinary editable/deletable Actual Expense; later Plan status, DD toggle, Plan reset, or Copy Month cannot erase it. Existing manual and effective DD expense dates are edited in their actual `spent_date` month even when source month is null or different.

The Today screen should not show a separate date/title panel or month/year selectors because the main purpose is the immediate current safe-to-spend answer.

Today has no Monthly summary. Reserved and Savings envelopes normally remain in Month only. Their visibility may instead be set to Show on Today, which displays their balance below a simple separator after Daily spend without adding a heading; it remains excluded from safe-to-spend. Hide balance keeps them off Today and masks their persistent Balance in Month while leaving monthly Plan values visible; the masked values are sufficient, so the Funding row does not repeat “Balance hidden” beneath the envelope name. Any Today envelope whose balance is exactly zero is omitted to reduce clutter; negative balances remain visible.

## Authentication

A FIN installation has exactly one owner/security principal. Financial data remains installation-global and there is no username, user selection, user switching, or per-user financial ownership.

Daily access uses the ordinary FIN PHP session when valid. Otherwise FIN reads only the non-secret `fin.deviceCredentialId` stored by this browser/PWA. If present, FIN automatically requests a WebAuthn assertion with exactly that ID in `allowCredentials`; it never asks the browser/OS to discover FIN credentials. Registration uses the local platform authenticator, discourages resident credentials, and requires user verification. The browser/OS—not FIN—collects Windows Hello, fingerprint, face, device PIN, or Android verification.

A browser without a locally remembered credential goes directly to the numeric Master PIN form. Successful PIN authentication automatically registers this browser's platform credential, stores its credential ID locally only after server verification, and opens FIN without an Enable Device Login step. A missing, revoked, unusable, fake, or cleared local ID falls back to the same PIN-and-register flow. Explicit cancellation offers only Try again and Use Master PIN; all genuine verification failures remain generic. The local ID is a routing hint, never authentication evidence.

The Master PIN is bootstrap/recovery and new-device authorization, not the preferred daily method. Its Argon2id hash is external server configuration, never SQLite or web state. One nested `auth` section loaded from tracked `config.php` plus optional ignored `config.local.php` resolves both the PIN and throttle files identically for CLI, migration, updater checks, and web requests. Production defaults are `/etc/fin/master-pin.hash` and `/var/lib/fin/master-pin-throttle.json`; local development uses explicit absolute paths in `config.local.php`. Authentication paths are not selected from environment variables, Unix usernames, home directories, or groups. The PIN is set/changed only through `php api.php set-master-pin`; the web UI cannot manage it. Global progressive online-attempt throttling remains independent of browser state. A missing PIN plus no enrolled credential fails closed.

The WebAuthn relying-party id and origin are installation configuration, resolved from the same tracked `config.php` plus ignored `config.local.php` merge. The tracked values are neutral placeholders; every installation supplies its own relying-party id and public origin, because a relying-party id only works on the host it names.

An authenticated Security dialog lists labels and supports rename and individual revocation; it has no manual enrollment action. Automatic post-PIN enrollment and security changes require the ordinary authenticated session plus a session CSRF token. PIN and WebAuthn success create the same session; logout invalidates it and the next access automatically uses only this browser's remembered credential.

## Navigation

The authenticated screen should avoid permanent top-level clutter. A left-aligned × button logs out directly. The right header button switches reciprocally between Month and Today: it shows a Month icon on Today and a Home icon on Month. There is no hamburger menu. Envelope management is integrated into Month rather than exposed as a duplicate top-level screen.

The HTML document title may remain `FIN Planner`, but the authenticated page does not need a visible `FIN` or `FIN Planner` title. A compact shield button opens Device login management from Month only; it stays hidden on Today and does not create another application page.

## Non-negotiable UI Information Invariants

### NEVER ADD REDUNDANT INFORMATION

FIN MUST NOT render information the user already knows from the surrounding section, heading, filter, workflow, or context. Redundant labels, badges, prefixes, suffixes, headings, row metadata, duplicate-meaning icons, helper/status text, type names, classifications, and implementation/domain enums are FORBIDDEN. Their presence is not justified by symmetry, column consistency, exposing every possible type, implementation convenience, or the mere existence of an internal value.

Before any repeated user-visible information is added, the implementation/review MUST ask: **Does this tell the user something they do not already know?** If no, it MUST NOT be rendered. A normal/default item MUST NOT become noisy merely to look symmetrical with an occasional exception. Mark only the exception, with the smallest useful signal, when that signal adds information.

### NEVER HIDE IMPORTANT INFORMATION

Important user information MUST NOT be hidden, unnecessarily truncated, replaced, displaced, pushed out of available space, obscured, made harder to scan, or visually subordinated by less-important information. This is a hard failure, not a styling preference. If metadata causes that outcome, the metadata MUST be removed or redesigned; the important information MUST NOT be shortened to accommodate it. For a Transaction row, its useful description/note, amount, Envelope, and date have priority over an already-known type or internal classification.

These are independent absolute invariants. Every user-visible addition MUST pass both checks separately: **A. Is it redundant with surrounding context?** If yes, remove it. **B. Does it hide, truncate, displace, obscure, or de-emphasize more important information?** If yes, remove or redesign it. Failing either check forbids acceptance.

### Applied type-label rule

Today's **Recent expenses** is semantically Expense-only: it includes only effective `transactions.kind='expense'` rows and never Refund, Transfer, Funding, adjustment, or No FIN action. Its heading already supplies the Expense meaning, so a per-row `Expense` label is forbidden. Its full useful note/description remains primary and may wrap rather than being ellipsized to make room for metadata. Mixed Account/Envelope history and statement reconciliation leave ordinary Expense visually unmarked while identifying Refund, Transfer, Funding, adjustment, opening/income, or other genuine exceptions only where needed. The human-readable PDF and detailed XLSX Actual transactions lists likewise leave ordinary Expense unmarked and mark Refund as the exception; the PDF dedicates its reclaimed width to the Description rather than a redundant Type column.

## Installation

FIN is installable as a Progressive Web App from the browser's native installation action. When launched from an Android home screen, it opens in a standalone window and returns to the Today-centered application shell. The product does not add a permanent install button or other daily UI clutter.

Installation requires HTTPS outside local development. The manifest request includes same-origin credentials and does not assume an upstream authentication proxy; FIN protects its own API/session directly behind the normal HTTPS reverse proxy. The FIN application session cookie is scoped to the public origin root so login remains valid when that root is reverse-proxied to an internal `/fin` path. The service worker may cache only public application-shell assets. Authentication status, PIN requests, WebAuthn registration/assertion challenges and responses, logout, API responses, reports, and private financial data remain network-only and must never be added to the service-worker cache. The cached shell may open without connectivity, but financial use requires a connection to the backend.

## Envelope Visibility

Balance visibility never changes financial calculations. Role alone controls safe-to-spend. Savings goal-progress visibility is a separate presentation setting and also never changes calculations.

- Daily spend is always eligible for Today and always included in safe-to-spend; it has no visibility control, but an exact zero-balance row is omitted.
- Reserved and Savings may be Normal, Hide balance, or Show on Today.
- Normal keeps Reserved/Savings off Today and displays their Balance normally in Month.
- Hide balance keeps the envelope off Today and masks only its persistent Balance in Month. It remains active, planned, and selectable for expenses.
- Show on Today displays the Reserved/Savings balance on Today without including it in safe-to-spend.
- Visibility is not an archive lifecycle, and no quick Use now action is currently needed.

## Month Plan Relationship

A Month Plan describes intended monthly Envelope Funding and does not reset persistent Envelope Balances. Every Month shows every Envelope; an unsaved Plan starts at zero. Only Funded creates a positive date-effective Funding movement. The P/F/S control cycles Planned → Funded → Skipped without moving status into the Plan editor. Direct debit preserves its scheduling, payment-Account, and effective-Expense semantics. A blank payment date defaults to the first day of the Plan's own Month and remains editable.

The Month view consists of exactly five peer remembered collapsible sections in this order:

1. **Month** — status interpretation, inline Month fields, compact save icon, Add Month, Copy Month, and Report/export. Changed unsaved fields are orange.
2. **Accounts** — current and archived Accounts, add/edit/archive/restore, signed Balance/history/reconciliation, statement import, and statement reconciliation.
3. **Envelopes** — global Envelope creation and settings, listed alphabetically by displayed name using Danish locale rules. Monthly Plan editing does not move here.
4. **Plan summary** — every current non-archived Account, automatically, in a per-Account table with selected-Month Plan, selected-Month Funded, and an **Envelopes** column containing the per-Account Envelope balance sum (NOT the real Account Balance; the real Account Balance remains in the Accounts view). The top totals remain Plan, Funded, and Balance. There is no separate participation, reminder, or monthly Account-Balance state.
5. **Funding** — Envelope Plan and Funding controls grouped first by role and then by the Envelope's actual default Account; within each role, displayed Account subgroup labels and Envelope names within each subgroup are alphabetized using Danish locale rules, and unmapped Envelopes are shown under the alphabetically placed No Account label.

There is no standalone Month action row and no Month-settings or Accounts-list dialog as a normal entry point. Report/export and Copy live inside Month; statement import and reconciliation live inside Accounts. All five sections reuse the existing accordion behavior and remember state. Month alone uses the derived status accent; the other peer sections are neutral.

Canonical user-facing Envelope terms are **Plan**, **Funded**, **Spent**, **Balance**, and **Funding**. Internal historical identifiers may retain old names where changing them adds compatibility risk. Plan and Balance continue to persist one authoritative Currency amount; Helper currency remains selected-Month derived presentation. Actual Spent remains in Recent expenses and reports rather than being combined with Funding.

Month status continues to interpret current/past Daily spend as one aggregate pool, Reserved as Funding rather than overspending, and Savings by whether planned contributions were Funded. The 0.75 current-Daily pace threshold remains an experimental V1 UI tolerance. Today's safe-to-spend color reuses the status without changing calculations.

Funding role and actual-Account subgroup headers remain independently collapsible. Funding Review keeps the fixed Daily spend → Reserved → Savings role sequence and alphabetizes Envelope links within each role. Every Envelope row shows Plan, persistent Balance, and the P/F/S control. Hide balance masks only Balance; Savings goal progress retains its percentage-only behavior. Copy Month keeps Skipped rows Skipped, resets others to Planned, clears Funding dates, and preserves an existing target Month's own exchange rate.

## Month Creation

If the current calendar Month does not exist, the application creates an empty Month with no Transactions. Its Funding section still displays every Envelope with a zero Plan.

Copying into an existing Month warns that target Plan rows may be replaced while existing Actual Expenses remain.

## Account Foundations

An Account is a permanent first-class signed financial position. It contains identity, name/necessary metadata, `CURRENCY | HELPER_CURRENCY` input/presentation preference, active/archive state, ordinary signed movements, and absolute end-of-day Balance observations. Positive is a positive position, negative is a negative position, and zero is zero. FIN has no active Asset/Liability/Credit-card classification and no replacement classification.

Narrow Account events own signed Account movements only for Income, Expenses, Refunds, and Transfers. Every complete Expense creates its ordinary Envelope effect and optional actual payment-Account effect. Every Account-to-Account movement uses one `Transfer` semantic. Account name edits, currency-preference edits, and archive/restore do not rewrite financial history.

Account Balance is known only when an observation exists on or before the requested date. FIN starts from the latest applicable absolute observation and adds only movements dated strictly after it through the requested date; the observation already includes every movement on its own date. With no applicable observation the Balance is explicit Unknown/null, never an inferred zero. New Accounts may leave Current Balance blank or record any signed Balance, including zero, as an `account_creation` observation dated today. **Record Balance** writes one absolute manual observation and never a balancing movement. Transactions and statement reconciliation remain valid at any historical date without an observation. Trustworthy statement day-close running balances may become direct `statement` observations; FIN never derives a pre-row opening. Future observations are rejected. Archived Accounts retain all references/history but are excluded from new payment/default choices and from the current Plan summary.

Envelopes hold only the nullable real `default_account_id`; Transactions and Direct debit Plan rows hold their explicit nullable actual Account references. DB v2.17 deterministically maps an old normalized Envelope Account name to an actual unique Account where possible, otherwise leaves the Envelope unmapped, and removes the old text fields and monthly Account-transfer reminder state.

## Statement Evidence

Statement data is disposable external evidence. The import/persistence stage creates `statement_imports` and `statement_rows`; a `running_balance` stays evidence and never establishes an Account Balance observation, which only the owner records. After evidence commit, deterministic conservative recognition may link one exact existing record or create one complete ordinary FIN record through the existing authoritative write paths. Ambiguous/incomplete rows remain exceptions. Raw evidence itself never changes Plans, safe-to-spend, Month status, reports, or recommendations.

The dependency direction is one-way:

```text
statement evidence
    -> may later reference/link to ordinary FIN financial records

FIN financial records
    -> never depend on statement evidence
```

A future confirmed reconciliation may create an ordinary transaction or account event and then add separate provenance links, or it may link evidence to an existing record. Imported/manual remains provenance only, and the resulting financial record uses exactly the same accounting semantics as manual entry. Deleting statement imports, rows, or future reconciliation links must never delete, alter, or invalidate authoritative transactions, account events/movements, envelope movements, balances, reports, recommendations, or history.

The normal saved-Import-Profile workflow accepts at most 1,000 parsed statement rows per submitted file. A file with more rows is rejected as one whole import before any evidence row, import record, observation, reconciliation, or authoritative financial record is written; the error reports both the detected row count and the 1,000-row limit. Exactly 1,000 rows remains valid. FIN does not split, paginate, or partially import an oversized statement. Statement text normalization decodes Excel escapes and repairs mojibake segments without converting already-valid UTF-8 portions of the same value. Reconciliation load also performs one idempotent evidence-only repair when a legacy persisted description contains malformed bytes, changing only that description and its normalized comparison value; dates, amounts, Account ownership, reconciliation status, raw evidence, and financial records remain untouched.

Step 1 (v1.23.0 / DB v2.8) supports inert evidence ingestion for the real statement exports of the installation (a signed account statement XLSX, a signed card statement XLSX, a fee statement XLSX, and a debit/credit OOXML `.xls` export). The normal flow is Choose file → choose Account → Preview → Import evidence. The Account selector always starts at `—`; Preview and Import require an explicit current Account selection, and that selection is authoritative. The statement structure is never asked of the user: FIN matches the file against the installation's saved Import Profiles by header structure, and an unmatched file is rejected. A file Account identifier may be shown as informational evidence but never selects, overrides, rejects, or blocks the chosen Account. Preview shows the chosen Account, informational file identifier, statement period, rows found, new rows, already-known rows, and parse problems; the user then imports evidence only. File checksums prevent exact-file reimport; stable row fingerprints plus occurrence indexes protect overlapping exports while retaining genuinely repeated identical rows. Raw source fields and parse problems remain evidence. The one normalized `statement_rows.amount` means the complete signed external Account movement in evidence `currency`; it never replaces the raw source amount/fee evidence. No matching, reconciliation decision, suggestion, transfer classification, or financial posting exists in this step.

Step 2 (v1.24.0 / DB v2.9) introduced the ordinary-Expense reconciliation slice: for one unresolved outgoing statement row, FIN offers plausible existing Expense matches or creation of the missing ordinary Expense. Candidate matching requires the same Account, authoritative Currency amount within 0.01 after event-date evidence conversion, matching direction/type, and date within ±4 calendar days; the same rule covers Refunds and existing Transfers/Income events where their semantics fit. Deterministic auto-processing now links exactly one candidate, creates an exact-identifier internal Transfer, or reuses exactly one prior Envelope association for the same normalized description/Account/direction. Income evidence may create ordinary Income when the matched Import Profile classifies the row as `income`. Multiple/unknown outcomes remain exceptions. All unresolved rows use one compact manual list with mutually exclusive Envelope and Transfer Account choices followed by an exact final `IGNORE` choice. Envelope choice follows evidence direction through the existing Expense/Refund transaction path; Transfer Account choice follows evidence direction through the existing Account Transfer path; `IGNORE` persists the existing No FIN action disposition for that Account's evidence row without creating a financial record. On explicit Reconcile load, ordinary Transaction notes with an existing Envelope, ordinary Transfer-event notes, and prior reconciled statement descriptions—including `no_fin_action` outcomes—compete using the maximum of whole normalized-string and compact same-length local-window Levenshtein similarity at the unchanged threshold of 75; local windows are disabled for compact strings shorter than four characters. Envelope history is cross-Account, while Transfer history requires the current statement Account to be the event side matching the statement direction. The highest score across every source wins and an exact tie uses the latest applicable source record. An Envelope or Transfer winner becomes the ordinary transient dropdown value. A `no_fin_action` winner emits no preselection and never falls back to a lower-scoring categorized outcome, leaving the dropdown neutral so `IGNORE` always remains an explicit owner choice. Retaining or changing a categorized preselection uses the same explicit Save path, and Close writes nothing. Successful rows, including `IGNORE`, disappear permanently from normal unresolved queues, failed choices remain highlighted and retryable with the backend's exact error visible on each failed row, and unselected rows remain unresolved. No un-ignore control is introduced. Each Account load obtains the full unresolved total through a count-only query, displays that Account-specific count beside the Account name, and bounds assessment and history preselection to the next 32 unresolved rows. After Save, the dialog asynchronously reloads from the same Account to update its count and refill that bounded batch, then advances through later active Accounts in the existing alphabetical order with the next Account's count when the current Account is exhausted; load failures remain visible rather than being treated as an empty queue. Every confirmation reuses authoritative Transaction/Account-event functions and writes provenance atomically. Removing a link is provenance-only; statement evidence and links remain disposable.

Step 3 (v1.26.0, no DB migration) adds the file-drop path: `<fin-root>/import/` holds pending source files and `import/imported/` holds successfully ingested source files. `php api.php import-statements ACCOUNT_ID` scans `import/` under one explicitly supplied current Account, and the UI upload path also stages its bytes into `import/` first, so both code paths converge on the same `import/imported/` final location. The selected Account is authoritative regardless of file Account identifiers. Calling the underlying scanner without an Account leaves files in `import/` with outcome `NEEDS ACCOUNT` and reason `Select an Account.`. Imports are financially inert; reconciliation is not triggered automatically. Same-filename collisions keep both source files using a small deterministic timestamped suffix when the bytes differ; byte-identical sources at the destination unlink the redundant original. No watcher, daemon, queue, or scheduler is introduced — how often `import-statements` runs is an operator scheduling decision.

Step 4 (v1.31.0, no DB migration) corrects the effective amount normalization. The signed account, signed card and debit/credit structures already expose complete signed Account effects and remain unchanged. The inspected fee-format exports expose a non-negative `Gebyr` charge separate from `Beløb`; within each product ledger, running `Saldo` proves `effective amount = Beløb - Gebyr` for every non-zero-fee outgoing, incoming, and fee-only row. Parser v2 stores that result in statement evidence `amount` while preserving both raw fields. The fee format's fingerprint identity remains based on raw `Beløb` for parser-v1 overlap stability. No Fee financial semantic exists: Expense, Refund, Transfer, and No FIN action consume the one effective evidence amount through their existing rules.

Every JSON API response uses checked serialization. Encoding failure returns a valid HTTP 500 JSON error rather than an empty body, and buffered JSON requests replace runtime-fatal partial output with a valid JSON error response. Binary report downloads remain outside that JSON-only buffering guard.

## Production → Development Data Refresh

Production is the authoritative financial/domain state. FIN has one explicit operator-triggered refresh direction only:

```text
consistent read-only production SQLite snapshot
    → encrypted download to development staging
    → candidate migration and verification
    → atomic replacement of the disposable development DB
```

The development-side script has a fixed local destination and no upload, reverse, merge, replication, synchronization, promotion, or generic source/destination mode. It remotely invokes FIN's existing SQLite online-backup helper through the narrow `refresh-snapshot` CLI, transfers only the resulting snapshot over SSH/SCP, verifies its checksum/integrity/foreign keys/core schema and source-version compatibility, then runs the development checkout's canonical `php api.php migrate` and `php api.php version` path against a same-filesystem staging file. Candidate target DB version is derived by migrating a temporary empty probe DB through that same engine, never from a duplicate version registry.

Production supplies all SQLite financial/domain/runtime data, including in-DB statement evidence and reconciliation provenance. Original statement export files are not copied. Development preserves exactly its existing environment-specific `auth_principal` and `webauthn_credentials` after verifying those tables remain financially independent; copied production auth rows never become active dev state. Development `config.local.php`, external Master PIN/throttle, RP/origin, sessions, paths, TLS/server configuration, dependencies, and Git checkout remain untouched.

Refresh requires dev writers to be stopped. The old dev DB is securely backed up before one atomic replacement; staged and installed DBs use mode `0600`, backup directories use `0700`, and final failure restores the prior dev DB while retaining the failed candidate securely. `READY` is emitted only after final app/DB version, integrity, FK, preserved-auth, domain-fingerprint, and read/open checks pass. Real production execution remains an operator action separate from implementation, testing, and release finalization.

## Funding Recommendations

FIN may recommend monthly envelope funding without changing a plan automatically. A `fixed` Envelope is skipped: its monthly amount is the owner's own, so FIN derives no amount for it and suggests none. A `reset` Envelope is recommended exactly like any other history-based Envelope. Recommendations are calculated on demand from actual Transactions grouped by calendar `spent_date`; each Envelope/month observation is `max(Expense spending − Refund spending, 0)`. A Refund never increases recommendation spending, and a net-refund month contributes zero rather than a negative observation. Funding, transfers, reconciliation, carryover, and archive rollups never count as spending. Only completed tracked months are eligible, and envelope/month exclusions affect recommendation statistics only.

Daily Spend uses a robust median and recency-weighted average with modest buffering and practical rounding. Reserved behavior is inferred as stable monthly, variable monthly, irregular monthly, or irregular reserve; stable obligations retain their observed amount without an invented buffer. Spending that is not a monthly recurrence — its median month is 0, or its average month is more than twice its median month — is funded by its average monthly cost instead of a typical month, so a quarterly or annual obligation is never recommended as a monthly requirement and an irregular cost is never recommended as 0. Savings has no spending-derived recommendation.

An envelope may store one optional user-confirmed historical baseline amount and source note. This baseline represents external/manual analysis, never enters the algorithm, never increases confidence, and never replaces a FIN recommendation. It is stored as one authoritative Currency amount and is shown in the Plan editor in the Envelope's preferred currency, with the Currency amount kept as the secondary value, so the baseline is directly comparable with the Plan amount that is entered in that currency. The Plan editor distinguishes baseline, early FIN estimate, and Moderate/Good recommendation. Any Use action only fills the amount field; Save remains explicit. Funding Review is reached through the Report/export workflow and keeps Current, Baseline, FIN Recommended, Assessment, and Confidence semantically distinct, using stacked rows on mobile.

Plan assessment uses a Moderate/Good FIN recommendation when available. Otherwise a manual baseline may provide only a clearly labeled baseline comparison. The historical PDF and XLSX remain unchanged.

## Historical Report and Export

The selected From → To range produces one clearly separated section per month. The human-readable PDF is a light, printable historical report rather than a dump of internal records or a copy of the dark web UI. It uses embedded Unicode-capable typography, proper tables with repeated headers, restrained Month Status color, generation date, and page numbers.

Each Month overview labels the financial facts explicitly as Planned Income, Plan, Plan difference (`Income - Plan`), signed Actual Spent, and the existing Month status. A Month that has at least one Income account event additionally shows Recorded income (the sum of that month's Income events) and an Income difference (Recorded income minus Planned Income). A Month without an Income event shows neither figure, so unreconciled income never reads as zero. Planned income and recorded income stay separate numbers: nothing is netted, inferred, or derived from one another. Actual Spent is Expense minus Refund selected and grouped by calendar `spent_date`, effective no later than the report date; Funding and Envelope Transfers are not Spent. Current-month values are marked as of the report date. Completed-month closing balances use the final calendar day of that month, while current-month balances use today.

Role tables preserve the Envelope mental model. Daily Spend shows Plan, date-effective Funded, signed Actual, the `Plan - Actual` Difference, and the closing Balance; it retains individual negative variances and emphasizes the aggregate total; only the current month includes the existing safe-to-spend/day result. The precise per-Envelope table is sufficient for a single month and is not duplicated by a chart. Reserved shows Planned, date-effective Funded, signed Actual protected-money use, and closing Balance without treating legitimate obligations as ordinary overspending. Savings shows Planned contribution, date-effective Contributed funding, signed Used activity, and closing Balance without calling contributions expenses. Small Savings sections stay together across page boundaries when practical.

A range of two or more months adds one compact range summary near the report beginning: the range totals for Plan, Funded, Actual spending, and Difference, followed by the same Plan / Funded / Actual split per role (Daily Spend, Reserved, Savings). The range totals are the sums of the per-month figures the report already prints, and a single-month export has no range summary.

A multi-month range may include one compact Daily Spend Plan/Actual totals chart near the report beginning only when at least two started months contain meaningful Daily Spend plan or actual data. Future-only comparisons are not sufficient to trigger it. Reserved, absolute balances, per-month Daily Spend, and Savings are intentionally not charted.

The chronological Actual transactions table contains each effective Expense or Refund exactly once in its `spent_date` month. Ordinary Expense is visually silent under that heading; Refund is identified inline as the exceptional kind and presented as restorative signed net spending, with authoritative Currency first and an event-date derived Helper display where available. Envelope Transfers remain separate from Actual transactions, while obsolete starting/reconciliation/reversal movements are removed by DB v2.22 and internal `archive_rollup` movements are omitted from the normal PDF. XLSX remains the detailed single-sheet month-stacked export, including its existing Balance movements data, and its month block carries the same Actual spending total the PDF prints plus a Plan-vs-Actual difference (Plan minus Actual spending) next to the Income / Plan / Plan difference rows. A month block whose month has at least one Income event also carries a Recorded income row and an Income difference, with the same values the PDF prints; a month without an Income event carries neither.

## Monthly Currency Conversion

The database Currency is immutable and denominates every authoritative Balance, Transaction, Plan, movement, event, and reporting total. Helper currency is configurable and non-authoritative. Displayed and editable decimal values use Danish formatting with a comma decimal separator and no grouping inside input fields; pasted dot-decimal values remain accepted and are normalized when reopened. Each Month owns one `exchange_rate`, where `Helper currency × exchange_rate = Currency`. Plan/Funding conversion uses the Plan Month; transaction and Account-event conversion uses the Month containing the event date. Missing required rates fail atomically, with no invented or cross-Month fallback. Editing a rate changes derived presentation and future conversions only; it never rewrites authoritative history.

## History Retention and Backups

The running SQLite database should retain only the newest 12 month rows. Cleanup is manual and previewable rather than scheduled automatically. A complete, integrity-checked SQLite archive and SHA-256 manifest must be created before deletion. Cleanup must preserve each movement-derived envelope balance exactly by replacing expired movement history with a brought-forward archive-rollup movement at the first retained month. It must also preserve each Account position exactly: transaction-linked Account events are detached from removed transaction detail and retained with their signed movements. An otherwise old source month is temporarily protected from cleanup when one of its linked movements is effective after the retention cutoff, preventing a scheduled payment from being rolled forward or made effective early. If balance or foreign-key verification fails, the cleanup transaction must roll back.

History archives and production-update backups share `$HOME/fin-backups` by default, or the directory selected through `FIN_BACKUP_DIR`. Distinct filenames identify archive cleanup and application-upgrade restore points. Backup files stay outside the web root and use restrictive permissions.

## Canonical currency model (architecture invariant)

FIN has exactly one authoritative accounting **Currency**, persisted immutably per database. Every authoritative financial amount is stored once in Currency under the canonical name `amount`. Account/Envelope `currency` preferences contain only `CURRENCY` or `HELPER_CURRENCY` and affect input/presentation only; there is no Account classification.

Helper currency is configurable and exists only for input, derived display, reports, and external-evidence understanding. `exchange_rate` means `Helper currency × exchange_rate = Currency`. When Helper input or statement evidence becomes an authoritative record, conversion uses the Month containing the event date and only the resulting Currency amount is persisted. Missing required rates fail atomically.

A derived Helper display may differ slightly from an external native-currency balance due to rounding. This accepted presentation approximation is not a second financial truth.

**ALL ACCOUNTS ARE ACCOUNTS.** This rule has no exceptions. Every Account has one signed Balance; positive, negative, and zero positions fully describe it. Account-to-Account Transfers have one accounting semantic (`Transfer`) regardless of either side's currency or Balance sign. `borrowing` and `repayment` are NOT separate Account-to-Account accounting semantics. DB v2.16/v2.17 use generic authoritative amount names, store no parallel Helper amount in actual FIN records, and keep statement currency only on inert external evidence.

## Versioning

FIN Planner v1.0 is the baseline application and database release. The application version is stored in `VERSION`. Database upgrades use ordered migrations recorded in `schema_migrations`. Release finalization is complete after the release commit and annotated version tag are successfully pushed and their remote refs verified. A production update and production verification are separate, explicitly requested operator operations and are never automatic release-finalization steps. Production updates should run `./scripts/update.sh`, which safely backs up SQLite, performs a fast-forward-only pull, applies pending migrations, and reports installed versions.

## Test Strategy

Financial correctness and safe database upgrades have priority over broad visual coverage. `./scripts/test.sh` runs deterministic financial unit tests, isolated PHP/SQLite integration and migration tests, production-update-script tests, and a small set of critical desktop/mobile Chromium workflows. Synthetic fixtures and temporary databases must always be used; automated tests must never open the private runtime database.
