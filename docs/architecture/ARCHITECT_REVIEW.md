# Architect Review

## What is already excellent?

The strongest product decision is the separation between daily spending and monthly planning. The application already treats the daily workflow as something different from the full financial plan, which supports the mission of reducing stress rather than turning the user into an accountant.

The envelope model is also worth preserving. Envelopes give money a clear purpose, and the connection between monthly planning and day-to-day available balances is directionally correct. This is a strong foundation for answering the most important user question: "Can I safely spend this money today?"

The application also has a good bias toward manual clarity. Adding an expense is intentionally small: amount, envelope, date, and note. This respects the user’s limited daily attention and avoids making the app feel like bookkeeping software.

Month copying is another excellent decision. Most personal finances are repetitive, and copying a previous month supports planning with minimal maintenance. This preserves continuity without forcing the user to rebuild the plan every month.

The product already supports historical continuity through import and export. This matters because the application is not starting from a blank slate; it is replacing an existing spreadsheet habit. Preserving that history helps the user trust the app.

Finally, the interface already has a clear hierarchy: Today, Envelopes, and Month. That structure should be preserved because it matches three distinct user intents: decide, adjust, and plan.

## What appears unfinished?

The idea of "safe to spend today" has now become the primary Today-screen answer. The remaining unfinished work is to ensure that all supporting flows preserve trust in that number, especially month copying, envelope setup, and savings usage.

The product now distinguishes Daily spend, Reserved, and Savings envelopes. This is a strong product concept, but the surrounding planning workflow still needs to make those roles feel obvious and easy to maintain.

The expense workflow is fast for creation, but it appears incomplete as a full daily workflow. Expenses can be added and deleted, but correcting a mistake is not presented as an obvious first-class action.

Authentication setup is intentionally server-administered rather than a product onboarding flow: one external Master PIN bootstraps the installation, after which automatic platform Device login is the daily path. FIN deliberately has no web first-user/password setup.

The monthly plan is useful, but it still feels partly like a spreadsheet translated into an app. It contains the right information, but the product has not fully converted that information into confident decisions.

Reporting/export now has a clear secondary role as a human-readable historical archive: it separates plan, funding, actual spending, role-specific envelope activity, and closing balances without competing with Today. It remains intentionally outside the daily stress-reduction workflow.

## What is accidental complexity?

The user is exposed to several concepts that may overlap in meaning: expenses, bills, savings, plan rows, envelopes, transactions, status, accounts, notes, categories, and recurring markers. Each concept may be useful, but together they increase the amount the user must understand before feeling confident.

The monthly plan has multiple ways to classify money. A row can have a type, category, envelope, account, status, and note. From a product perspective, this creates more interpretation than a stress-reducing tool should require.

The distinction between planned payments and actual expenses may not always be obvious. A user may need to think about whether something should be marked paid, entered as an expense, linked to an envelope, or left as planned.

The presence of both DKK and EUR is necessary for this user’s real financial life, but it also adds cognitive load. The app must be careful that EUR remains supportive context rather than another decision layer.

The Month screen carries much of the spreadsheet’s density. It provides control, but it risks making the app feel more powerful than simple. This is product complexity, not necessarily technical complexity.

The app currently gives the user many administrative actions: add month, copy month, report/export, add row, edit month, edit row, edit envelope, hide envelope, delete items. These actions are useful, but they compete with the desired feeling of effortless daily use.

## What is missing from the user's daily workflow?

If the user opens the app every morning, they can immediately see the selected month, the total available amount across visible envelopes, individual envelope balances, and recent expenses. This is already helpful and gives a quick overview of day-to-day spending capacity.

However, the user still has to do some mental work. They must interpret whether the available amount is healthy for this point in the month. They must decide whether spending from one envelope today will create pressure later. They must infer whether the rest of the month is safe, tight, or already at risk.

The app now directly answers: "How much can I spend today without creating stress later?" It does this with a large safe-to-spend number based only on Daily spend envelopes.

The user may still need to mentally connect monthly plan quality with daily confidence. If envelopes are missing roles, copied incorrectly, or not funded, the safe-to-spend number may be technically correct but emotionally surprising.

The emotional state after opening the app now depends less on interpretation. The primary output is clearer, while deeper planning details remain on the secondary Month screen.

## What should Version 1.0 feel like?

Version 1.0 should feel calm, obvious, and reassuring. The user should open the app and immediately see one dominant number: safe to spend today.

The first impression should be: "I know where I stand." The app should make the current financial situation feel understandable in seconds. It should not feel like a ledger, spreadsheet, or finance dashboard designed for analysis.

Daily use should revolve around one simple rhythm: open, check whether spending is safe, record anything spent, close. The user should not feel invited to optimize, categorize excessively, or investigate unless something truly needs attention.

The Today screen should feel like the product’s center of gravity. Daily spend envelopes should support the main number directly, while Reserved and Savings planning remains on Month unless an envelope is explicitly shown on Today.

The monthly planning experience should feel like setting intentions, not doing accounting. Copying a month, adjusting changed values, and confirming the plan should feel lightweight and forgiving.

The product should make good financial behavior feel normal rather than punitive. It should communicate progress, pressure, and risk in plain language. When things are fine, the app should say so clearly. When things are tight, it should explain that without guilt.

Version 1.0 should leave the user feeling that money has been organized into purposes, today is understandable, and no hidden calculation is waiting to surprise them.
