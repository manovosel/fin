# Statement Import and Reconciliation Architecture Addendum

Status: **current canonical architecture** for statement evidence and future reconciliation. Inert evidence ingestion is implemented in the v1.23.0 / DB v2.8 candidate. Matching, reconciliation decisions/links, suggestions, and financial creation remain unimplemented. Split transaction posting scope and all fixed September clean-start/onboarding recommendations are rejected.

Parent investigation: `CARD_ACCOUNT_DEBT_AND_PLAN_FUNDING_INVESTIGATION.md`

Application baseline: FIN Planner v1.17.0 / database v2.2

## Executive conclusion

The proposed separation of:

- typed `accounts`;
- narrow `account_events`;
- signed `account_movements`;
- envelope-classified `transactions` with a separate payment account;

is compatible with future ordinary, wallet, card and foreign-currency statement reconciliation.

Three small architectural refinements should be reserved before Stage 1 so later imports do not force a redesign:

1. raw statement provenance must remain separate from confirmed FIN financial records and support many-to-many reconciliation links;
2. every complete transaction must use normal envelope/account effects regardless of date, while incomplete imports remain raw inert evidence;
3. imported actual expenses must not require a fake monthly plan relationship—actual month remains authoritative from `spent_date`.

Statement ingestion must be inert. A raw row never changes a transaction, envelope balance, account position, allocation, Month Status, report, recommendation, history, or safe-to-spend.

## Core dependency and disposability rule

Statement data is disposable evidence. Reconciliation may later create or link ordinary authoritative FIN financial records, but those records must never depend on statement imports, rows, or provenance links for their existence, accounting meaning, balances, reporting, recommendations, or history.

```text
statement evidence
    -> may reference/link to ordinary FIN financial records

FIN financial records
    -> must never depend on statement evidence
```

Purging statement imports, statement rows, and future reconciliation/provenance links must leave normal transactions, account events/movements, envelope movements, reconciliation-created records, balances, reports, recommendations, and history valid and unchanged. There may be no cascade path from evidence into authoritative financial data.

---

## 1. Compatibility with the proposed account model

### Natural mapping

The account model supports each relevant statement row without changing the envelope model:

| Statement activity | Confirmed FIN representation |
|---|---|
| Wallet merchant purchase | One envelope transaction + one Wallet Account asset outflow |
| Direct Card purchase | One envelope transaction + one Card Account liability increase |
| Card Account → Wallet Account | One borrowing account event with two account movements |
| Ordinary Account → Card Account | One repayment account event with two account movements |
| Ordinary Account → Wallet Account | One asset-transfer account event with two account movements |
| Card Account interest/fee | One expense transaction + one Card Account liability increase |
| Unknown/unconfirmed row | Raw statement row only; no financial effect |

This preserves the approved rule:

```text
Envelope = what for?
Payment account = paid from where?
```

### Why imports should not post directly to account movements

Provider formats and descriptions are evidence, not financial intent. A statement adapter should normalize raw rows and propose matches/classifications. Only a confirmed reconciliation action may create or link:

- a FIN transaction;
- an account event;
- account movements.

The account event remains the authoritative human-readable financial event. Statement rows provide provenance for it.

### Required relationship shape

A single nullable `statement_rows.linked_transaction_id` is too restrictive:

- one transfer may be evidenced by an outgoing row and an incoming row;
- one merchant row may later be split across two envelopes;
- one direct-card purchase links both to a transaction and its account event;
- duplicate/fee rows may need separate reconciliation decisions.

Use an independent reconciliation/link concept capable of many-to-many relationships. The exact table names can be finalized during implementation, but the domain should support:

```text
statement row(s)
    -> confirmed reconciliation decision
        -> FIN transaction(s) and/or one account event
```

This is the only notable structural adjustment needed to the earlier account proposal.

---

## 2. Raw statement provenance requirements

Raw imports are retained external evidence until explicitly purged. DB v2.8 implements:

```text
statement_imports
- id, account_id, provider
- original_filename, file_checksum, imported_at
- statement_from / statement_to nullable
- parser_version
- row_count, valid_row_count, problem_count

statement_rows
- id, import_id, account_id, provider, source_row_number
- booking_date / value_date nullable
- signed amount_original nullable, currency_original
- description_original / description_normalized
- provider_reference nullable, provider_row_type
- running_balance nullable
- row_fingerprint, occurrence_index
- parse_problem, raw_fields_json, created_at
```

The implemented schema preserves these principles:

- raw provider amount/sign and normalized FIN interpretation are both retained;
- raw descriptions are never overwritten by later merchant normalization;
- provider IDs are retained exactly;
- file checksum and parser version explain how the row entered FIN;
- statement rows remain separate from reconciliation and may later be purged without touching authoritative records;
- sensitive raw files/metadata receive the same private storage and backup protections as financial data;
- raw statement data is never service-worker cached or placed in public shell assets.

### Reconciliation state

Reconciliation state should be represented by explicit decisions/links rather than mutating the raw row into a financial record. Useful states include:

```text
unreviewed
suggested_match
confirmed_match
confirmed_new_expense
confirmed_account_event
ignored/non-financial
possible_duplicate
needs_review
```

A confirmed decision records who/when confirmed it, the target record(s), classification, and enough rule evidence to explain the match.

Deleting or archiving an import must never cascade-delete confirmed transactions, account events, or movements.

---

## 3. Matching and reconciliation architecture

### Candidate generation

Matching should be deterministic and explainable. Candidate scoring may use:

1. exact provider transaction ID;
2. exact payment account;
3. exact original amount/currency or explained DKK conversion;
4. same booking/spent date;
5. a small date window for booking/value/spent-date differences;
6. normalized merchant/description similarity;
7. existing envelope and confirmed merchant history.

Payment account is an important discriminator. An 800 DKK Envelope A expense marked paid from the Card Account should not be matched automatically to an 800 DKK Wallet Account row.

### Suggested outcomes

#### Matched

Strong evidence identifies an existing FIN transaction. The importer proposes a link and shows the evidence. Confirmation links provenance; it does not create a duplicate expense.

#### Probable match

Amount/account match but date or description differs. The user confirms or rejects it.

#### Missing from FIN

No plausible transaction exists. FIN may suggest an envelope. Only explicit confirmation creates the transaction and its permitted ledger effect.

#### Not an expense

Paired/single rows may be proposed as:

- asset transfer;
- borrowing;
- debt repayment;
- interest/fee expense;
- duplicate/reversal;
- unknown.

The user confirms before FIN creates or links an account event.

### Transfer pairing

Pair candidates should use opposite/economically corresponding account effects, compatible dates, and amounts:

```text
Card Account debt increase + Wallet Account inflow    -> borrowing
Ordinary Account outflow + Card Account debt decrease    -> repayment
Ordinary Account outflow + Wallet Account inflow         -> asset transfer
```

A missing opposite-side statement must not force an expense classification. FIN may confirm a one-sided event only with explicit user review and clear incomplete-evidence labeling.

One movement is one Transfer. When a Transfer for exactly that movement already exists — the same two Accounts in the same direction, the same amount within 0.01, the same date within the four-day tolerance — the other statement row links to that Transfer instead of recording the same payment twice. A Transfer whose side for that Account is already reconciled to a statement row is not reused, so two genuine movements of the same amount stay two Transfers, and no match or several matches create a new Transfer rather than a wrong link.

### No silent mutation

High-confidence matching may preselect a decision, but it must not:

- create an expense;
- change an envelope;
- add a payment account;
- create borrowing/repayment;
- alter balances;
- delete an existing record;

until confirmed.

---

## 4. Deterministic envelope suggestion

Envelope suggestion should use confirmed classifications only.

### Merchant normalization

Create a deterministic normalized merchant key from provider/account and description by rules such as:

- case/whitespace normalization;
- known provider prefixes/suffixes;
- removal of card-number fragments and volatile reference numbers;
- cautious location/date-token removal;
- explicit aliases confirmed by the user.

Always retain the original description.

### Evidence

For a merchant key, count previously confirmed expense envelopes:

```text
GROCERY SHOP
Envelope A 8 of 8 confirmed
-> suggest Envelope A; confidence High

COFFEE SHOP
Envelope L 7 of 8 confirmed
-> suggest Envelope L; confidence High or Moderate under the approved threshold
```

The UI should explain:

```text
Suggested Envelope A
Based on 8 of 8 confirmed GROCERY SHOP purchases
```

Thresholds should be fixed and tested, not learned opaquely. A suitable first design can require a minimum sample count, a dominant-envelope percentage, and a margin over the second choice. Exact thresholds remain an implementation decision.

Unknown or mixed merchants receive no suggestion. The user chooses an envelope.

Rejected suggestions do not become positive training evidence. Only confirmed reconciliations update merchant history.

---

## 5. Historical Actual Expense backfill

### Purpose

June–August imports may reconstruct genuine consumption evidence:

```text
June Envelope A      3,200
July Envelope A      3,900
August Envelope A    3,450
```

A user-confirmed imported expense should become a normal `transactions` record for:

- Actual Expenses;
- monthly envelope actuals;
- recommendation observations;
- merchant/envelope history.

It must not invent:

- historical allocations;
- historical funding movements;
- opening envelope balances;
- opening free cash;
- unsupported account or card history.

A resolved expense still creates its normal envelope movement and, when a payment account is specified, its normal account movement.

### Actual month versus source plan month

Current `transactions.month_id` is required even though actual placement is already governed by `spent_date`. Imported June/July actuals may have no retained source plan row/month.

Before statement backfill, treat `month_id` conceptually as a nullable **source plan month**, not the authoritative actual month. One of these small schema corrections will be needed:

- make the existing source `month_id` nullable; or
- add a nullable `source_month_id` and migrate the old meaning.

Reports and recommendations must continue to bucket actuals by `spent_date`. An actual-only month should say plan data is unavailable rather than inventing zero income/allocations.

Do not create fake allocations merely to satisfy the current transaction foreign key.

---

## 6. One transaction accounting model

A complete FIN financial record has the same accounting effects regardless of age or provenance:

- every expense appears in Actual Expenses by `spent_date`;
- every expense creates one envelope expense movement;
- when a payment account is known, every expense creates one matching account effect;
- direct card purchases always increase the Liability as well as recording the envelope expense;
- transfers, borrowing, and repayment always use their normal account-event semantics.

Imported versus manual is provenance only. A raw statement row that cannot yet supply every mandatory field for a valid financial record remains separate inert evidence. It must not be inserted into `transactions` as a partial or balance-neutral substitute.

If older evidence overlaps an existing Balance observation or an Envelope balance reconciliation, FIN must not solve that conflict by changing transaction semantics. The user must instead resolve the evidence and the Balance or Envelope reconciliation facts explicitly before creating a complete record.

---

## 7. Recommendation-engine interaction

Only **confirmed FIN transactions** count. Raw or suggested statement rows do not.

The v1.17 recommendation engine reads complete transaction actuals by envelope and `spent_date`. Raw or unresolved statement rows contribute nothing until they are resolved into normal FIN transactions.

Existing `recommendation_exclusions` continue unchanged:

```text
July Envelope L actual remains historically true
July Envelope L can be excluded from recommendation statistics
Reports/balances/history are not rewritten
```

### Coverage caution

Imported positive expenses are valid evidence, but a zero month is trustworthy only when statement coverage is complete enough to conclude that no expense occurred.

Import architecture should retain statement period and a user-confirmed coverage state. Recommendation history should distinguish:

- confirmed positive actuals;
- a genuinely covered zero month;
- an unknown/uncovered month.

Do not let importing one account's statement make every zero envelope/month appear fully tracked. A later implementation may use explicit expense-tracking coverage metadata across relevant payment accounts. Until then, ambiguous zeros remain excluded/unknown rather than synthetic zero observations.

---

## 8. Import idempotency and deletion safety

### File-level idempotency

Use a unique identity such as:

```text
(account_id, provider, file_checksum)
```

Reimporting the same file returns the existing import/result rather than adding rows.

### Row-level identity

Preferred identity:

```text
(account_id, provider, provider_transaction_id)
```

Fallback fingerprint may include:

- account/provider;
- booking and value dates;
- signed original amount/currency;
- normalized raw description/reference;
- provider row/reference;
- occurrence index when a file legitimately contains identical rows.

Fallback matching across overlapping exports must be conservative. Two genuinely separate identical purchases must not be merged merely because date/amount/merchant match.

### Financial idempotency

Constraints/link checks must ensure:

- one confirmed statement row is not posted twice;
- one existing FIN transaction is not duplicated when a match is confirmed;
- one account event owns one movement set;
- one transaction owns at most one account expense effect;
- pairing two statement rows creates one transfer/borrowing/repayment event.

### Deletion/reimport

Statement evidence is disposable. When purge is implemented:

- confirmed FIN records survive unchanged;
- provenance/evidence and reconciliation links may disappear;
- no cascade removes or alters transactions/events/movements;
- reimport may recreate evidence but can never repost financial effects automatically.

---

## 9. Historical statement strategy

Historical statement evidence remains raw and inert until it can be resolved into a complete ordinary FIN record. Resolution requires the true date, amount, financial type, envelope when it is an expense, payment account when known/required by the decision, and provenance link.

Creating an older expense applies the same envelope and optional Account effects as creating a current expense. Transfers likewise use their ordinary Account-event semantic at every date. Transaction creation and reconciliation never depend on whether an Account Balance observation exists.

The existing card Reserved envelope remains separate from the Card Account liability and is never converted into debt.

---

## 10. Small changes to the previously proposed account schema

Reserve these capabilities before implementation; do not build provider-specific import tables in Stage 1.

### Accounts and Balance observations

Current model:

```text
accounts.currency = CURRENCY | HELPER_CURRENCY
accounts has no classification or ledger_start_date
account_balance_observations = one signed absolute end-of-day Balance per Account/date
Balance = latest applicable observation + strictly later movements through the requested date
no applicable observation = Unknown/null
```

### Transactions

Retain:

```text
payment_account_id nullable
source plan month nullable/explicitly non-authoritative
```

Transactions have one accounting model. Do not put provider IDs or raw descriptions directly on transactions. Provenance belongs in separate statement tables/links.

### Account events/movements

Keep stable IDs and strict one-event/movement ownership so future statement reconciliation can link to an event without recreating it. No provider-specific columns are needed.

Unresolved statement classifications create no account event or transaction. They remain attached only to raw evidence until a complete normal financial record can be created.

### Statement reconciliation link

Plan for a separate many-to-many reconciliation/provenance link rather than one nullable link on `statement_rows`.

### Month/actual history

Do not require imported actuals to claim a historical plan. Actual reporting derives month from `spent_date`; plan data may be unavailable.

### Currency

Raw rows must preserve original amount/currency. Stage 1 may remain DKK-only for account posting, but the account movement design must not discard source currency evidence. Multi-currency account posting rules can be deferred until a real provider sample requires them.

---

## 11. Revised staged implementation order

No stage begins without explicit approval.

### Stage 0 — approve final boundaries

Approve:

- raw-import evidence versus confirmed financial records;
- many-to-many reconciliation links;
- one transaction accounting model independent of date/provenance;
- account initial-position dates and conflict handling;
- nullable/non-authoritative transaction source month;
- Plan Funding unknown behavior;
- statement coverage treatment for recommendation zeros.

### Stage 1 — pure domains and invariants

Implement pure tested logic only for:

- signed asset/liability event effects;
- borrowing/repayment/direct purchase/interest behavior;
- Plan Funding calculation;
- uniform transaction/account-event behavior;
- statement identity/fingerprint contracts;
- reconciliation target/link invariants.

No provider parser or statement UI.

### Stage 2 — additive core schema

Add:

- accounts;
- account events/movements;
- nullable payment account;
- account tracking/initial-position date;
- source-month correction;
- month funding context.

Migrate identifiers/labels only. Do not infer balances, debt, payment accounts, or historical events.

### Stage 3 — permanent account management

Provide ordinary month-independent Account creation, explicit dated initial positions, history, reconciliation, and archive/restore. Do not create special-month onboarding or infer historical values.

### Stage 4 — live expense payment account

Add payment account to new/edit expense flow and atomically create the correct account effect for every transaction. Unknown remains valid where the payment source is genuinely not recorded.

### Stage 5 — explicit account activity

Add narrow actions for:

- asset transfer;
- Card Account borrowing to Wallet Account;
- debt repayment;
- account reconciliation;
- interest/fee via expense flow.

### Stage 6 — Plan Funding Health

Add the fourth Month Status dimension, unknown/setup state, worst-state integration, and clarified Plan Summary. Borrowing remains separate factual credit activity and never closes the gap.

### Stage 7 — inert statement ingestion — implemented in v1.23.0 / DB v2.8 candidate

Format-specific parsing and raw evidence storage support the real statement exports of the installation (a signed account statement, a signed card statement, a fee statement and a debit/credit statement). File/row idempotency and repeated-row occurrence identity are enforced. Ingestion creates no financial records.

### Stage 8 — matching and reconciliation

Add deterministic existing-expense matching, transfer/borrowing/repayment suggestions, explicit confirmation, and retained links/provenance.

### Stage 9 — deterministic envelope suggestion

Use confirmed merchant history only, with transparent counts/confidence and no silent creation.

### Stage 10 — historical actual resolution

Allow statement evidence to become a financial record only after it has every mandatory classification. Validate:

- older and current expenses create identical envelope/payment-account effects;
- reports and recommendations gain the same complete transaction actuals;
- recommendation exclusions still apply;
- unresolved rows remain inert and uncovered zeros are not treated as complete history.

### Stage 11 — reporting/export

Add payment account and compact account/debt activity without duplicating consumption or principal repayment. Preserve raw provenance in detailed export only where privacy and readability permit.

### Stage 12 — observe before expansion

Review real reconciliation results before adding:

- more providers;
- transaction splitting;
- automated aliases;
- historical account-event reconstruction;
- multi-currency account posting;
- any bulk-confirm workflow.

---

## Final addendum decision

The account/liability proposal has no architectural dead end for statement reconciliation. The key is to keep three layers distinct:

```text
Raw statement evidence
Confirmed reconciliation decision
Authoritative FIN transaction/account event
```

Historical confirmed expenses become ordinary complete FIN transactions with the same envelope and optional payment-account effects as current expenses. Raw rows remain inert until that complete record can be created; provenance never changes accounting semantics. Statement evidence and future links are disposable, while every authoritative FIN record created or linked during reconciliation remains durable and valid without them.
