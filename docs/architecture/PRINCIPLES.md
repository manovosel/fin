# Principles

## Core Principles

0. **One domain concept = one canonical user-facing term.** Every FIN user-facing label describes a single financial or workflow concept the user needs to understand. Do not introduce prefixes, suffixes, qualifiers, synonyms, implementation terminology, decorative labels, or technical distinctions unless they represent a real product or domain distinction the user must know about. Internal implementation names may differ in code, but they must not leak into user-facing terminology. One concept, one term, used everywhere.

0a. **One authoritative accounting Currency. Every authoritative financial amount in FIN is stored in the database's immutable configured Currency. There are no exceptions.** A configurable Helper currency may exist for input, display, and external-evidence understanding, but it is never a second authoritative accounting currency. The canonical domain term is `amount`; persisted actual FIN amounts never carry currency-qualified aliases or a parallel Helper value. Account and Envelope `currency` fields store only the presentation preference `CURRENCY` or `HELPER_CURRENCY`; preference never reinterprets or rewrites financial history. `exchange_rate` means `Helper currency × exchange_rate = Currency`. Converting Helper input or external evidence into an authoritative record uses the rate for the Month containing the event date and persists only the resulting Currency amount. Missing rates must fail atomically rather than use invented or cross-Month fallbacks.

0b. **NEVER ADD REDUNDANT INFORMATION.** FIN MUST NOT repeat information already established by the surrounding section, heading, filter, workflow, or other context. Redundant labels, badges, prefixes, suffixes, headings, row metadata, duplicate-meaning icons, helper/status text, type names, classifications, and implementation/domain enums are FORBIDDEN. Symmetry, column consistency, implementation convenience, or an internal value's existence never justifies rendering it. Before adding repeated user-visible information, ask: **Does this tell the user something they do not already know?** If no, it MUST NOT be rendered. In a list with one normal/default case and occasional exceptions, the normal case remains silent; mark only an exception, with the smallest useful signal, when that exception adds information.

0c. **NEVER HIDE IMPORTANT INFORMATION.** Important user information MUST NOT be hidden, unnecessarily truncated, replaced, displaced, pushed out of available space, obscured, made harder to scan, or visually subordinated by less-important information. This is a hard UI failure. If added metadata makes more important information less visible, the metadata MUST be removed or redesigned; important information MUST NOT be shortened to accommodate it. Context determines importance, but a financial Transaction's useful description/note, amount, Envelope, and date are examples of information that must retain priority over an already-known type or internal classification.

**Mandatory UI acceptance check:** every user-visible addition MUST independently pass both checks: **A. Is it redundant with surrounding context?** If yes, remove it. **B. Does it hide, truncate, displace, obscure, or de-emphasize more important information?** If yes, remove or redesign it. A UI change that fails either check MUST NOT be accepted. These invariants are independent; passing one never excuses failing the other.

1. The application exists to reduce financial stress, not to increase financial awareness.

2. The most important question is:
   "Can I safely spend this money today?"

3. Every important piece of information must fit on the main dashboard without scrolling.

4. Daily use should take less than one minute.

5. Recording an expense should normally take less than 10 seconds.

6. Planning is more important than accounting.

7. The application should predict the future, not only record the past.

8. Manual entry is preferred over complex automation unless automation clearly saves time.

9. Simplicity always wins over flexibility.

10. Every new feature must make the application easier to use, not more powerful.

11. Visualizations are encouraged only when they directly support a financial decision or reinforce motivation toward a financial goal.

12. Protected money must never be presented as available money.

13. The daily safe-to-spend number must be conservative, rounded down, and based only on envelopes intentionally marked as Daily spend.

14. Everything that holds money for a purpose must be modeled as an envelope.

15. Envelope balances must be persistent and must not reset automatically at month boundaries. An owner may explicitly mark one envelope to start each month from that month's allocation instead; that choice belongs to that envelope alone and is never applied implicitly.

16. The running database should retain only the newest 12 month rows; older detail must be archived before removal, and cleanup must never change a current envelope balance.

17. Monthly status must interpret existing plans, transactions, funding states, and balances without introducing a parallel accounting model. Daily spend is judged as one pool; Reserved spending is not overspending; Savings is judged by whether planned contributions were funded.

18. Status color should reinforce severity without changing calculations: green for On track, orange for Getting tight, and red for Needs attention.

19. Financial events are date-effective: funding applies on its movement date, expenses become actual on `spent_date`, and balances include only movements effective by the requested as-of date.

20. Historical reports must keep planning, funding, spending, reallocation, and balances distinct; human-readable exports should explain financial reality rather than expose internal bookkeeping records.

21. Funding guidance must remain deterministic and advisory. FIN-learned recommendations use actual transactions only; user-confirmed external baselines remain visibly separate reference information and never become synthetic history or confidence evidence.

22. Envelopes and Accounts are separate dimensions: an Envelope answers what money or spending is for, while an Account identifies where a signed financial position is held.

23. **ALL ACCOUNTS ARE ACCOUNTS.** Every Account has one signed Balance: positive is a positive position, negative is a negative position, and zero is a zero position. FIN has no Asset/Liability/Credit-card classification, amount-owed/owned distinction, or separate Credit-card ledger.

24. An Account-to-Account movement has one accounting semantic: `Transfer`. Account currency or Balance sign does not change it. `borrowing` and `debt-principal repayment` are not separate Account-to-Account accounting semantics. Interest and fees remain ordinary Expenses.

25. A complete financial record has one accounting meaning regardless of date or provenance. Every expense creates the same envelope effect and optional payment-account effect; imported versus manual is provenance only. Raw or incomplete statement evidence remains inert until it can be resolved into a complete normal FIN financial record.

26. Account Balance knowledge comes only from absolute dated observations. For date `D`, FIN uses the latest observation on or before `D` plus ordinary Account movements whose dates are strictly after that observation and on or before `D`; same-day movements are already included in the end-of-day observation. Without an applicable observation, Balance is explicitly Unknown/null even when movements exist—unknown is never coerced to zero. Account creation accepts an optional current Balance: blank stays Unknown, while any entered signed amount, including zero, creates today's `account_creation` observation. Manual Balance recording creates or updates the Account/date observation and never creates an opening/reconciliation event or correction movement. Transactions, Transfers, and statement reconciliation are valid at any date independently of Balance knowledge. Future observations are prohibited.

27. An envelope's real default account is convenience metadata only. The transaction's optional payment account is authoritative for how an expense was paid; changing a default must never rewrite history or restrict an override.

28. Accounts with financial history are archived rather than deleted. Archived accounts remain authoritative in history but are excluded from normal new-payment and envelope-default choices.

29. Direct debit controls scheduling only until `spent_date`. Once effective, its Transaction is an ordinary Actual Expense that planning changes cannot erase; an explicit nullable DD payment Account is authoritative and is never inferred from an Envelope default.

30. Transaction conversion follows the Month containing `spent_date`. If that Month lacks a usable rate, a required Helper-currency conversion fails atomically; source Month remains non-authoritative metadata and is never a rate fallback.

31. Retention may remove Transaction detail only after preserving and verifying both Envelope Balances and Account Balance states, including known amount versus Unknown. Transaction-linked Account events and Account Balance observations are financial truth and must survive cleanup.

32. One FIN installation has one owner/security principal. Daily authentication uses only this browser's remembered non-discoverable platform credential; a new or cleared browser uses the server-managed Master PIN once and is enrolled automatically. The PIN remains outside financial data, browser storage, and the web UI, while the locally stored credential ID is only a routing hint.

33. Statement imports are disposable external evidence, never financial truth. After evidence is persisted, deterministic conservative recognition may create or link ordinary authoritative FIN records when exactly one safe outcome is known; all other rows remain in an exception-only reconciliation queue. Manual exceptions use one row-level workflow: the owner confirms either an Envelope for the direction-appropriate Expense/Refund path, another Account for the canonical Transfer path, or the final explicit `IGNORE` choice for durable resolution without a financial record. Reconcile reports the current Account's full unresolved count through a count-only query, loads and preselects at most 32 of those rows, refills after Save, and advances through later active Accounts in the existing alphabetical order when the current Account is exhausted. A dropdown may start with an ordinary non-persisted selection derived from sufficiently similar existing FIN Transaction, Transfer, or reconciliation history, but the owner may retain or change it and must still explicitly Save; closing writes nothing, and no fuzzy text inference supplements exact automatic processing. A Transfer destination is preselected only when the statement evidence itself names a counterpart Account; when it names none, the destination is the owner's choice alone. One real movement reported by two statements is one Transfer: the second side links to the Transfer the first side recorded instead of recording the same payment twice. Only rows with a selected classification are submitted. Those transactions, account events/movements, envelope movements, balances, reports, recommendations, and history must never depend on statement evidence for existence or meaning. Purging imports, raw rows, or reconciliation links must leave every authoritative financial record and result intact.

## Design Philosophy

- Show only information that helps the user make a decision.
- Progress bars and simple visual indicators for savings goals are encouraged.
- Savings should be shown as protected progress, not as money available to spend.
- Savings must be represented as envelopes, not as a separate product model.
- Forecast visualizations are encouraged if they help the user understand future financial impact.
- Historical charts should only be included if they directly support better future decisions.
- Avoid financial jargon whenever possible.
- Prefer one obvious workflow over many configurable workflows.
- Use configuration only when it protects the core mental model, such as choosing whether an envelope is Daily spend, Reserved, or Savings.
- Remove or redesign product concepts that compete with envelopes as the source of money purpose.
- Every screen should answer a question.
- If a feature requires reading documentation, it should be redesigned.

## Decision Filter

Every proposed feature should answer "Yes" to all of these questions:

- Does it reduce financial stress?
- Does it help make a better financial decision?
- Can a new user understand it immediately?
- Is it worth the additional complexity?
- Would we still build it if this application only had one user?
