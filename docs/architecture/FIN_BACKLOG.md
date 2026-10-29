# FIN Backlog

_Last synchronized: 2026-10-27_

## Rules

- Priorities are **HIGH**, **NORMAL**, and **LOW** only.
- Within each priority, work is **FIFO — oldest item first**.
- New ideas go to the backlog instead of interrupting current work.
- Default priority for a new item is **NORMAL** unless explicitly agreed otherwise.
- No extra states/categories such as “Parked”.
- Completed work is removed from this backlog.
- Do not silently add speculative ideas. An item belongs here only if it was explicitly agreed/deferred.

---

## HIGH — FIFO

_No items._

---

## NORMAL — FIFO

### 1. Mobile UI simplification audit

Carefully review FIN on mobile for redundant text, labels, headings, helper copy, decorative elements, and other UI that consumes scarce screen space without adding useful information.

Concrete examples that motivated this audit:

- remove labels such as **PRIVATE** when they convey nothing useful;
- remove implementation/admin-oriented explanatory text from ordinary user screens when it is not needed.

Be conservative: remove only clearly unnecessary elements and preserve anything needed for usability, security, context, or accessibility.

Goal: simplify the UI rather than add more concepts/screens.

If the balance is hidden dont change the font color, keep it the same as for other envelopes.

'Skipped' under skipped envelope name is redundant.

Just add BS after envelope name for envelopes setup for Betalingsservice.

Under envelope name put account for that envelope.

### 2. Open-source readiness + generic product-neutral naming

Treat FIN as source code that is already intended for public release.

Audit the repository so generic product logic is cleanly separated from user-specific, installation-specific, and deployment-specific data, names, paths, examples, URLs, and assumptions.

Concrete requirements:

- remove the production hostname and other private / personal deployment references from public README / docs / examples unless they are clearly generic placeholders;
- remove references to one user's private source spreadsheet where they describe that private source file rather than a generic import workflow;
- remove or generalize hard-coded personal / install-specific values in code, configuration defaults, scripts, fixtures, documentation, and examples;
- ensure sample / configuration documentation uses neutral placeholders and safe example values rather than the current private installation;
- the Currency and the optional Helper currency must be configurable per installation;
- FIN is **not** becoming a general arbitrary-multicurrency application;
- review Account / Envelope / statement-import examples and keep public examples generic; real institution names belong only in private installation-owned Import Profile data or other private configuration;
- remove the now-dormant legacy hardcoded statement provider detector/parser machinery and provider-specific compatibility test scaffolding once its remaining historical-compatibility requirements have been audited; normal new-file import must remain Import-Profile-only. Do not replace the old provider architecture with cosmetic generic aliases.
- keep private runtime data, credentials, statement exports, hostnames, paths, and similar installation details out of the published source tree.

##### Remaining scope

What remains open in this item is the real bank and provider names hard-coded in `statement_import.php` and named across the documentation and the synthetic statement generator (`tests/php/Fixtures/SyntheticStatementGenerator.php`). Moving those into installation-owned Import Profile data is a design change, not a rename.

##### Generic / product-neutral naming rule

GENERIC / PRODUCT-NEUTRAL naming is the default throughout:

- application code;
- domain code;
- tests;
- fixtures;
- variables;
- helper names;
- synthetic Accounts;
- examples;
- documentation;
- screenshots / sample data;
- generic UI copy.

Real provider / institution names are installation-owned data, not application architecture. They may exist in private Import Profile data and other genuinely private installation configuration. Public application code, tests, fixtures, examples and documentation must not depend on provider identity for statement parsing, format detection or import routing.

Legacy provider-specific detector/parser code and compatibility tests may remain temporarily only where required to prove historical compatibility during the removal audit. Do not preserve obsolete provider-specific machinery merely because it already exists.

Decision test:

If replacing the provider / institution name with `Source Account`, `Destination Account`, `Statement Account`, `Payment Account`, `Account A`, `Account B` (or another generic semantic name) would NOT change what the code / test / example / documentation proves or explains, then the real provider name should NOT be there.

Audit-first when implementing. Remove obsolete provider-specific parser fixtures/tests only after confirming that any required historical-compatibility coverage remains protected by provider-neutral tests.

Do this as a careful audit first. Do not blindly replace every concrete string: distinguish generic product behavior, supported-provider terminology, test fixtures, and genuinely private / personal deployment data.

Keep the solution simple. Do not overengineer or introduce a generalized configuration framework unless a concrete current requirement needs it.

Account / Envelope currency behavior and terminology are owned by the domain model, not by this item. This NORMAL item is the broader open-source/publication cleanup plus the canonical generic-naming rule.

### 3. Envelope soft-delete / archive + reactivation

Do not physically delete an Envelope that has history.

Desired behavior:

- archive/soft-delete it;
- preserve its identity and all historical references;
- hide it from normal future/current planning;
- if the user later tries to create the same Envelope again, detect the archived Envelope and ask whether to reactivate it;
- reactivation should preserve historical continuity rather than create a duplicate.

First concrete case: **an Envelope for a payee whose final payment has been made (October 2026)**.

### 4. Periodic simplicity / removal review

FIN should periodically be reviewed specifically with a **removal-first** mindset. The purpose is to make FIN increasingly simpler and easier to understand as it evolves. This is **not** primarily a feature-development exercise. The default question is:

> "Why does this UI element need to exist?"

rather than:

> "How can we improve this UI element?"

#### Review scope

Periodically review FIN's user-facing interface for things that can be:

- removed;
- combined;
- inferred automatically;
- hidden until relevant;
- moved into an existing appropriate context;
- replaced by a simpler interaction;
- expressed with less text;
- represented without another button, field, label, dialog, heading or setting.

Review elements such as:

- buttons;
- fields;
- labels;
- headings;
- helper / explanatory text;
- cards / sections;
- dialogs;
- status indicators;
- settings;
- navigation elements;
- duplicated actions;
- information shown in multiple places.

#### Removal-first classification

When performing such a review, think about each element approximately as:

- **NECESSARY** — required to understand FIN or perform an important action.
- **USEFUL BUT REDUCIBLE** — the capability matters, but the visible UI can potentially be combined, inferred, contextualized, collapsed or otherwise simplified.
- **REDUNDANT** — duplicates another element, action or piece of information.
- **IMPLEMENTATION LEAKAGE** — exposed primarily because FIN's internal implementation needs the concept, rather than because the user needs to understand or manipulate it.
- **REMOVAL CANDIDATE** — removing it would likely make FIN easier to understand without meaningful loss of value.

This classification is **guidance for the future review**, not a requirement to implement a classification system in FIN. Do **not** create metadata, database fields or tooling for it.

#### Feature removal

The primary target is unnecessary UI complexity, not removal of useful features. However, feature removal is explicitly allowed to be **proposed** when there is a strong concrete argument that:

- the feature provides little real user value;
- it introduces significant conceptual or UI complexity;
- removing it would make FIN materially easier to understand or operate.

Do not remove features casually. Feature-removal proposals require explicit reasoning and user approval before implementation.

#### Product principle

Capture the intended philosophy:

> Complexity must justify its existence.

Prefer removing or simplifying something over adding another explanation, setting, control or navigation mechanism when the same user goal can be achieved without it.

Do **not** interpret this as "minimal UI at any cost". Important information, functionality, security and accessibility must remain.

#### Relationship to the existing Mobile UI simplification audit

Do **not** merge this with the existing NORMAL item **Mobile UI simplification audit**. That existing item contains concrete tactical cleanup work and remains unchanged in its current FIFO position.

The new item is broader and periodic:

- not mobile-only;
- not limited to currently known cleanup examples;
- evaluates the overall conceptual / UI surface;
- uses a removal-first product-design perspective;
- may occasionally identify whole features as removal candidates.

Both items are preserved.

#### Periodic nature

This is intentionally a recurring product-design discipline rather than a one-time cleanup. When eventually performed, the review may be repeated after meaningful groups of FIN changes / releases when the interface has accumulated new functionality.

Do **not** add:

- cron schedules;
- release automation;
- mandatory review cadence;
- workflow machinery;
- new backlog states.

"Periodic" is a product practice, not an automated mechanism.

#### Comparison with other apps

When useful during future reviews, comparable personal-finance applications may be examined as references. The purpose is **not** simply to copy features. Use comparison in both directions:

- identify interactions FIN can simplify;
- identify concepts / controls other applications expose that FIN can deliberately avoid.

Do not add competitor-specific implementation requirements to this backlog item.

### 5. FIN Android Companion foundation

Establish the minimal native Android companion application required for Android-specific FIN capabilities.

- This is **not** a native rewrite of FIN.
- FIN remains the main application and retains budgeting, Accounts, Envelopes, Transactions, reconciliation, planning, reporting, and other domain functionality.
- The companion should provide only capabilities that genuinely require or significantly benefit from native Android integration.
- Establish only the minimal foundation needed for later capabilities such as payment capture and mobile authentication.
- Avoid adding speculative features, duplicated FIN UI, unnecessary frameworks, abstractions, or architecture.

### 6. FIN web API

Establish the minimal web API required for trusted external FIN clients such as **FIN Android Companion** to communicate with FIN.

- The API is part of FIN, not a separate product or generic integration platform.
- Provide the architectural foundation required for the companion to send data to FIN and later participate in authentication workflows.
- Security and authentication must be appropriate for a self-hosted FIN installation.
- Do not design a broad public API, plugin framework, developer platform, or speculative endpoint set.
- Add only the API capabilities justified by actual FIN use cases.
- Preserve FIN's existing domain model and canonical terminology.

Existing: FIN already serves an external client through a bearer token that is honoured on GET actions (`history`, `transactions`, `transfers`, `fundingRecommendations`, `accountBalances`; manual sections 9.10-9.15) and on exactly one POST write action (`setPlanAmounts`, manual section 9.13). Whether this item reuses that mechanism or adds a separate one is an open decision.

### 7. FIN Android Companion: payment capture

Depends on:

- **FIN Android Companion foundation**
- **FIN web API**

- Use Android notification access to capture payment notifications from sources such as wallet apps and banking apps.
- Keep payment capture source-agnostic rather than tied to one provider.
- Extract useful transaction information such as amount, currency, merchant, timestamp, and source when available.
- Send captured payments to FIN through the FIN web API.
- Captured payments enter FIN as **Pending transactions**, not authoritative accounting records.
- Statement import / reconciliation remains the accounting source of truth and should later match and confirm these Pending transactions.
- Allow duplicate notifications from multiple sources to be detected / deduplicated.
- Keep all budgeting and accounting logic in FIN, not in the Android companion.

### 8. Envelope funding date follows funding status

The funding date must reflect when an Envelope funding item actually becomes Funded. It represents the date the item entered the Funded state, not the row creation date or last-edit date.

Required behavior:

- `Planned -> Funded`: set the funding date to the current date.
- `Skipped -> Funded`: set the funding date to the current date.
- `Funded -> Planned`: clear the funding date.
- `Funded -> Skipped`: clear the funding date.
- `Planned <-> Skipped`: keep the funding date empty.
- If an item is already Funded and an unrelated field is edited while its status remains Funded, preserve the existing funding date; do not replace it with the current date.

Keep the implementation simple. Do not introduce additional history/state tables or abstractions unless the current architecture proves they are necessary.

---

## LOW — FIFO

### 9. FIN sanity/complexity audit

**Status: open.** Remaining work: the owner decision on `removeReconciliationLink` (retire it together with its one browser test, or keep it), and auditing further findings.

Audit accumulated FIN architecture, tooling, workflows, abstractions, supporting machinery, and documented assumptions for unnecessary complexity, unjustified mechanisms, duplication, or solutions disproportionate to actual FIN requirements.

**Audit only first. Do not implement cleanup during the audit.**

The audit should produce findings/recommendations for later discussion and explicit decisions.

**Known inputs**

`removeReconciliationLink` has no frontend caller (it is called directly by one browser test). The audit should assess whether to retire or keep it. Do not remove it now.

### 10. FIN Android Companion: mobile authentication

Depends on:

- **FIN Android Companion foundation**
- **FIN web API**

- Allow the Android companion to act as a trusted authentication device for FIN.
- A FIN browser session on another device should be able to request approval from the companion.
- Approval should use Android-supported device authentication such as fingerprint / biometrics or device PIN.
- This must fit FIN's existing single-principal authentication architecture.
- Do not introduce usernames / passwords or multi-user concepts.
- Master PIN remains the bootstrap / recovery mechanism.
- Do not depend on the browser / laptop itself having correctly working passkey support.
- Keep detailed authentication protocol / design decisions for later architecture work.

### 11. Review / clean redundant architecture and investigation reports

Review accumulated FIN architecture/investigation documents and remove or consolidate obsolete/redundant material so the documentation remains understandable.

### 12. File-drop statement import (automatic trigger)

Implement automatic file-drop statement import once its design is settled.

**Current state:** manual file-drop import already exists (manual sections 8.2 and 8.3). What remains open is the automatic trigger (watcher or cron).

**If proceeding, required behavior:**

- Files dropped into a server-side location (not a web upload endpoint — filesystem-based, consistent with FIN's single-principal auth model) are automatically picked up and imported through the existing inert Statement Import path.
- Determine and document how a dropped file resolves to the correct Account/Import Profile (e.g. filename convention or content-based Profile matching) — this is likely the core design decision.
- After processing, the file must not be reprocessed (moved/archived/deleted per whatever is simplest).
- Malformed/unrecognized files must not fail silently — visible error/log path required.
- Reconciliation is unaffected — this only automates the import step (file → inert statement evidence). All reconciliation remains exactly as it is today (manual, batched, with IGNORE).

**Guardrails:**

- Keep it simple — no new UI, no configuration screen, unless the research shows something already expects one.
- Do not change Statement Import, Reconcile, or any accounting logic beyond the automatic trigger for import itself.

### 13. Home Assistant integration: Savings goal progress via MQTT

Publish Savings Envelope goal progress to an MQTT broker so it can be displayed on a Home Assistant dashboard (e.g. as a motivational widget).

**Scope:**

- One-way publish only (FIN → MQTT → Home Assistant). No control/write path from Home Assistant back into FIN.
- Publish only: goal name, percentage progress, and deadline (if one is set for that goal). No other Envelope, Account, or financial data.
- Installation-owned MQTT broker connection details (host, port, credentials, topic prefix) — configurable per installation, not hardcoded, consistent with FIN's existing configuration conventions.
- Research first: confirm what's actually feasible/simplest for a self-hosted PHP app to publish MQTT messages (available library or minimal implementation), and how existing Envelope goal-progress data is currently computed/exposed, before designing the publish mechanism itself.
- Determine trigger mechanism (e.g. publish on goal-relevant state change, or a simple periodic publish) — keep it simple; no over-engineered event system.

**Guardrails:**

- Keep it simple — no bidirectional sync, no general-purpose integration platform, no new UI beyond whatever minimal configuration the MQTT connection needs.
- Only Savings goal envelopes with progress tracking are in scope — not all Envelopes.
- Do not change Envelope, Account, or reporting logic — this is a read-only publish of existing data.