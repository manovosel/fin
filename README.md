# FIN Planner

Current source: **v1.37.1** (database v2.23).

Self-hosted PHP + SQLite installable web app (PWA) for a single-Currency monthly financial planner.

A task-oriented operator manual for the whole installation lives in `docs/manual/manual.md`; the generated PDF is `docs/manual/fin_manual.pdf` and is rebuilt with `php docs/manual/build_manual.php`.

## Current concept

- **Authentication**: one installation owner uses automatic platform device login (Windows Hello, Android device verification, or equivalent). A new browser proves ownership once with the server-managed Master PIN, then FIN automatically enrolls and remembers that browser's own non-discoverable credential. Later visits automatically request only that credential; FIN has no username, password, passkey discovery, or authentication chooser.
- **Today screen**: day-to-day envelopes only, e.g. food, fuel, fun/travel, subscriptions/wallets, plus “safe to spend today”. Its main card follows current Month status from green On track through orange Getting tight to red Needs attention without changing the calculation.
- **Add and edit expenses**: record amount, envelope, note, and date; the most-used visible envelope is preselected for a new expense. The one amount field follows the selected Envelope's `CURRENCY` or `HELPER_CURRENCY` preference, while only one authoritative Currency amount is persisted. Recent expenses derives both configured currency displays, is collapsible with remembered state, and supports correcting or deleting manual expenses. Every complete expense uses the same Envelope and optional payment-account semantics regardless of date or provenance; incomplete future imports remain inert evidence rather than partial transactions. A new expense or imported statement row that would repeat an existing entry exactly - the same date, Envelope, amount and description - is reported and written only after the owner confirms it, because identical same-day entries are sometimes legitimate.
- **Direct debit**: monthly Plans can be marked Direct debit and may explicitly choose a nullable Paid from Account. Funded creates date-effective Funding plus a canonical scheduled Expense on the payment date. Before that date planning controls it; once effective it persists and is corrected/deleted like any manual Actual Expense.
- **Balance tools**: each Envelope supports absolute dated Balance observations, observation deletion, Envelope-to-Envelope Transfers, and movement history. Obsolete pre-observation opening/adjustment/reversal rows are removed during migration and are never created by the current application.
- **Historical PDF report**: selected months export as a printable, Unicode-capable report separating plans, funding, actual spending by `spent_date`, role-appropriate envelope activity, date-effective closing balances, and user-meaningful transfers/adjustments. XLSX retains its detailed month-stacked layout.
- **Funding guidance**: Plan editing and Funding Review compare the current Plan with deterministic recommendations learned only from completed native Transaction history. Optional user-confirmed historical baselines remain separate external references, and envelope/month exclusions affect recommendation statistics only.
- **Accounts**: every Account is an ordinary permanent Account with one absolute end-of-day Balance observation per Account/date plus account-side financial movements; the authoritative Balance is the most recent observation on or before the requested as-of date plus movements strictly after that observation through the as-of date (same-day movements are absorbed by the observation). Positive, negative, and zero Balances fully express an Account's known financial position; an Account may also have Unknown Balance when no observation has been recorded. Account creation Balance is optional (blank leaves Balance Unknown; any signed amount records a same-day `account_creation` observation). Users can Record Balance for any non-future date, replace a Balance observation by re-recording it for the same date (one observation per Account/date), or delete an observation to fall back to a prior observation or to Unknown. Statement import is financially inert and never derives a Balance observation from `running_balance − amount`. Accounts also support history, editing, and non-destructive archive/restore. Envelopes may choose an optional active default payment Account. Add/Edit Expense keeps overrideable Paid from behavior, while archived and Unknown historical references remain valid.
- **Statement evidence**: Month → Accounts → Import statement previews and imports raw statement evidence from a matching saved Import Profile. FIN matches the file against the installation's saved Import Profiles by header structure; a file that matches no enabled profile is rejected. Import is financially inert and duplicate-safe. Deterministic processing handles only proven outcomes; every remaining exception uses one compact manual list where a changed row chooses either an Envelope for the direction-appropriate Expense/Refund or another Account for a Transfer. Partial Save processes only changed rows, keeps failed choices retryable, and displays each backend error on its row. Statement data is disposable provenance; authoritative FIN transactions, movements, balances, reports, recommendations, and history never depend on it.
- **Savings goals**: configurable progress bars show only a concise percentage token such as `50%` and can appear in Month or in both Today and Month without exposing current or target amounts.
- **Month screen**: exactly five peer remembered collapsible sections—Month, Accounts, Envelopes, Plan summary, and Funding—contain every Month action and workflow without a standalone action row. Month includes status, inline settings, Add/Copy, and Report/export. Accounts includes management, statement import, and reconciliation. Envelopes contains global settings. Plan summary automatically lists every current Account with selected-Month Plan and Funded plus the sum of the persistent Envelope balances of the Envelopes assigned to that Account, as of today; Helper-currency rows convert that sum at the current rate. The Account's own signed Balance stays in the Accounts view. Funding shows every Envelope's monthly Plan and persistent Balance with its compact P/F/S control; Spent stays in Recent expenses and reports.
- Each Envelope chooses `CURRENCY` or `HELPER_CURRENCY` as its editable-Plan preference. Plan/Funding uses the selected Month's `exchange_rate`; Transactions use the Month containing `spent_date`. The database `Currency` is the only authoritative accounting currency. The Helper currency is an informative helper, not a binding accounting currency; it must not determine, override, or constrain the actual transaction/account currency, and a missing Helper-currency conversion must not prevent an otherwise valid transaction. When a Helper-currency conversion is required and the selected Month has no configured rate, FIN may use the corresponding ECB historical reference rate (EUR-anchored, weekly resolution) for the transaction's ISO week, with the most recent preceding publication used for weekends/holidays. The Helper-currency to Currency rate is derived from the two EUR-anchored ECB rates (`rate = eur_to_Currency / eur_to_Helper`) so any Helper currency ECB publishes works; rates are cached locally so historical imports do not depend on ECB being reachable. Funding totals align Helper currency and Currency in separate columns, with Currency farthest right. Decimal display and input use Danish comma formatting consistently.
- Daily spend is always visible and drives safe-to-spend. Reserved/Savings normally remain in Month, can hide their Balance, or can be shown on Today without entering the calculation.
- Funding role groups and aligned, visually secondary actual-Account subgroups are collapsible, and all Month collapse states—including the summary Account table—are remembered locally for the installation owner.
- The left × button logs out; the right header button switches directly between Today and Month.
- Envelope management is integrated into Month, and envelope names must be unique regardless of capitalization.
- New months start with planned funding of zero; copying a month explicitly reuses its plan.

## License

FIN Planner is licensed under the **GNU Affero General Public License, version 3 or later (AGPL-3.0-or-later)**.

Copyright (C) 2026 Marijo Novosel.

The full license text is in [`LICENSE`](LICENSE).

## Contributing

Bug reports and suggestions are welcome as issues.

Pull requests are not accepted at the moment: the project is maintained by one person, who keeps sole copyright so that the licensing stays simple.

## Local run

Create the ignored local configuration once. Use absolute paths so CLI and web requests always resolve the same files:

```bash
composer install
mkdir -p "$HOME/.config/fin"
cat > config.local.php <<PHP
<?php
return [
    'auth' => [
        'master_pin_file' => '$HOME/.config/fin/master-pin.hash',
        'throttle_file' => '$HOME/.config/fin/master-pin-throttle.json',
        'api_token_file' => '$HOME/.config/fin/readonly-token.hash',
        'file_group' => '',
    ],
    'webauthn_rp_id' => 'localhost',
    'webauthn_origin' => 'http://localhost:8080',
];
PHP
php api.php master-pin-status
php api.php set-master-pin
php api.php migrate
python3 scripts/import_source_budget.py source-budget.xlsx data/fin.sqlite --replace
php -S 127.0.0.1:8080
```

`config.php` provides the tracked defaults; ignored `config.local.php` recursively overrides them. `config.local.example.php` is a template. Authentication file paths are not selected from the current Unix user or auth-specific environment variables.

The tracked `webauthn_rp_id` / `webauthn_origin` defaults are neutral placeholders (`example.com`). Every installation must set its own values in `config.local.php`: a WebAuthn relying-party id only works on the host it names, and the origin must match the site's real origin, so passkey enrollment and Device login fail on any other host.

Open <http://localhost:8080>. A new browser uses the Master PIN once, then automatically registers that browser's local Device login. The Security shield on Month only lists, renames, or removes enrolled credentials.

Statement samples may be kept under ignored `import/`. In Month, open Accounts → Import statement, select the actual FIN Account and file, review the inert-evidence preview, then choose Import evidence. This does not reconcile or post any financial event.

## Install on Android

FIN is a Progressive Web App (PWA), so Android Chrome can install it with its own icon and open it in a standalone app window:

1. Open the installed FIN site (for example <https://example.com>) in Chrome and complete Master PIN recovery login or automatic Device login.
2. Open Chrome's menu.
3. Choose **Install app** or **Add to Home screen**.
4. Confirm **Install**.

The production app must be served over **HTTPS** for Android to offer full PWA installation. `localhost` is allowed for development, but a plain HTTP LAN address is not. FIN caches only its public app shell; authentication and private financial API data still require a network connection and are never stored in the service-worker cache.

## Automated tests

Install the Unicode PDF runtime dependency and development test dependencies once:

```bash
composer install
npm install
```

PHPUnit requires the PHP XML and Mbstring extensions. Browser tests use an installed `/usr/bin/chromium` when available; otherwise install Playwright Chromium with `npx playwright install chromium`.

Run the complete suite:

```bash
./scripts/test.sh
```

Or run one layer:

```bash
./scripts/test.sh unit        # JavaScript financial calculations
./scripts/test.sh integration # PHPUnit database/domain tests plus importer, update, archive, restore and refresh shell tests
./scripts/test.sh browser     # desktop and mobile Chromium workflows
```

Every test uses an in-memory or temporary SQLite database. The test suite never opens `data/fin.sqlite`.

After browser tests, `scripts/test.sh` prints how to expose the generated HTML report:

```bash
npx playwright show-report playwright-report --host 0.0.0.0 --port 9323
```

Open `http://SERVER_IP:9323`. Stop the report server when finished because it has no authentication and may contain screenshots, traces, and environment details.

## Data

Runtime SQLite data is stored in `data/fin.sqlite` and ignored by git. The running database is intended to retain the newest 12 month rows. Preview history cleanup before applying it:

```bash
php api.php archive-preview --keep-months=12
php api.php archive --keep-months=12
```

`archive` first creates and verifies a complete SQLite backup and SHA-256 manifest, then replaces older movements with per-envelope brought-forward movements, removes older month history, verifies that every envelope balance is unchanged, checks foreign keys and integrity, and compacts the database. It does nothing when no months need removal.

History cleanup and `./scripts/update.sh` use the same backup directory, `$HOME/fin-backups`, with restrictive permissions and distinct `fin-archive-before-*` and `fin-upgrade-*` filenames. Override that shared location with `FIN_BACKUP_DIR` or pass `--backup-dir=PATH` to `archive`.

Restore a backup over the live database with `scripts/restore-backup.sh <backup-file>` after stopping the web server. The script validates the backup, writes a safety copy of the current database first, never consumes the original backup file, and preserves the live file's owner, group and mode. It restores only the SQLite database — not the Master PIN hash or the local configuration. See manual section 5.3 for the full procedure.

### Refresh development from production

After stopping every development process that may hold `data/fin.sqlite`, run the one-way refresh from the development checkout:

```bash
FIN_PROD_SSH=user@production-host ./scripts/refresh-dev-from-prod.sh
```

The production checkout defaults to `/var/www/html/fin`; override it only when needed with `FIN_PROD_ROOT`. `FIN_PROD_PHP` and `FIN_PROD_BACKUP_DIR` are similarly narrow optional overrides. The script prompts before replacing the disposable development DB; automation must explicitly set `FIN_REFRESH_CONFIRM=REPLACE_DEV`.

The production checkout creates a consistent read-only SQLite snapshot through `php api.php refresh-snapshot`; SSH/SCP carries that snapshot only from production to local staging. The development checkout verifies its checksum/integrity/FKs, migrates it through `php api.php migrate`, preserves only the existing development `auth_principal` and `webauthn_credentials`, backs up the old dev DB, and atomically installs the verified candidate. Development Master PIN/config/origin remain external and untouched. The command has no upload, merge, reverse, or source-file-copy mode; statement files under `import/` are not included.

`READY` appears only after final version/integrity/FK/auth/domain verification. The old dev DB backup is stored under `$HOME/fin-backups` or `FIN_BACKUP_DIR`. Reload or reopen the development app afterward. The first real refresh should be performed only as a separately reviewed operator action.

## Versioning

- The application version is stored in `VERSION`.
- Applied database migrations are recorded in the `schema_migrations` table.
- `php api.php migrate` applies only migrations that are not yet recorded.
- `php api.php version` prints both application and database versions.
- `api.php?action=version` exposes the same information as JSON.
- Never edit an already released migration. Add the next ordered migration to the registry in `api.php`.

Release finalization ends after the release commit and annotated version tag have been successfully pushed and their remote refs verified. A production update and production verification are separate operator operations; releasing does not authorize production access, running the updater, updating the production installation, or verifying production.

## Production update

A production update is performed only as an explicitly requested, separate operation under the established production procedure. It is never an automatic or immediate release-finalization step.

Ensure `data/` is writable by the webserver.

The one-time v1.21 → v1.22 authentication upgrade must configure the Master PIN **before the live worktree is pulled**. Fetch the candidate into a temporary worktree while v1.21 remains active, run its server-only CLI, then remove the temporary worktree and use the normal updater:

```bash
git fetch origin
git worktree add --detach /tmp/fin-auth-bootstrap origin/master
sudo php /tmp/fin-auth-bootstrap/api.php set-master-pin
sudo php /tmp/fin-auth-bootstrap/api.php master-pin-status --require
git worktree remove /tmp/fin-auth-bootstrap
./scripts/update.sh
```

The tracked production configuration resolves `/etc/fin/master-pin.hash` and `/var/lib/fin/master-pin-throttle.json`. When run with `sudo`, the CLI creates `/etc/fin/master-pin.hash` as `root:www-data` `0640` and `/etc/fin` as `root:www-data` `0750`; `/var/lib/fin` is `root:www-data` `0770`, and the web runtime owns/writes only its `0660` throttle file. The PIN hash is never stored in SQLite. `php api.php master-pin-status` prints only the resolved Master PIN path and configured yes/no, never the hash.

`update.sh` refuses to proceed without a readable valid Master PIN, refuses tracked local changes, creates a consistent SQLite backup, performs a fast-forward-only pull, installs locked runtime dependencies, migrates, and prints installed versions. DB v2.7 independently refuses to remove a populated legacy `users` table unless the Master PIN is already configured.

Optional environment overrides:

```bash
FIN_BACKUP_DIR=/secure/backups PHP_BIN=/usr/bin/php ./scripts/update.sh
```

Changing/recovering the Master PIN requires shell access: `sudo php api.php set-master-pin`. Restoring FIN to another server requires separately restoring the protected hash/configuration or setting a new Master PIN; financial SQLite backups do not include it. WebAuthn public credential records remain in SQLite, but credentials are origin/RP-bound and may need re-enrollment after a host change.
