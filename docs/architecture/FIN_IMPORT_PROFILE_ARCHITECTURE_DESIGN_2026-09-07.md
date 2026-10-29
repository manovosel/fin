# FIN Import Profile Architecture Design — 2026-09-07

Date: 2026-09-07  
Task: **3A — architecture design only**

## Decision summary

FIN should replace commercial-provider dispatch with one small, declarative, installation-owned Import Profile model and one generic OOXML statement parser.

```text
import file
    -> structural inspection
    -> exactly one saved Import Profile
    -> generic parser primitives configured by that profile
    -> normalized inert statement rows
```

An Import Profile describes a file shape. It does not identify an Account and does not contain executable behavior. It may map one exact external category value to the canonical inert evidence classification `income`, but it never decides or directs an authoritative FIN action. Commercial names may exist only as private profile display data. Public application source selects no parser by institution, product, geography, Account name, or filename.

The financial boundary is explicit:

```text
Import Profile
    -> parse external structure
    -> normalized inert evidence
    -> generic reconciliation logic
    -> authoritative FIN action
```

The smallest safe design is:

- one SQLite table for profiles;
- one versioned JSON definition per profile;
- one deterministic base structural-signature hash for candidate lookup;
- a fixed, closed set of parser modes implemented in PHP;
- no expression language, plugins, scripts, remote registry, or runtime AI;
- a CLI-assisted first-profile workflow rather than a large profile-builder UI;
- existing evidence provider strings retained unchanged as opaque compatibility namespaces.

No implementation is performed by this report.

## 1. Current parser structural inventory

### Common current behavior

All four current formats are ZIP/OOXML workbooks, including the source delivered with an `.xls` suffix. Current code:

1. limits the file to 5 MB and 10,000 rows;
2. reads shared or inline strings from `xl/worksheets/sheet1.xml`;
3. repairs known text-encoding artifacts;
4. normalizes header labels and maps labels to column indexes;
5. converts Excel serials and a small fixed set of textual dates;
6. normalizes decimal text with the current comma/dot rule;
7. emits the same normalized evidence shape;
8. retains all mapped raw fields and row-level parse problems;
9. calculates statement period from valid booking dates;
10. extracts an Account number where structurally available;
11. assigns duplicate-resistant row fingerprints and occurrence indexes;
12. persists only inert `statement_imports` and `statement_rows` evidence before separate deterministic reconciliation runs.

The normalized row contract is common: source row number, booking/value date, signed evidence amount, evidence currency, original/normalized description, external reference, running balance, row type, parse problem, raw fields, occurrence index, and fingerprint.

### Genuine differences among the current four formats

| Source structure | Header location and discriminator | Dates | Amount and currency | Description/type/reference | Running balance | Account-number extraction |
|---|---|---|---|---|---|---|
| Signed account statement | Header is first row. Required shape includes `Dato`, `Tekst`, `Beløb`, `Saldo`, and `Kontonavn`. It is distinguished from the card structure by absence of the card marker in the first 20 data rows. | Booking date from `Dato`; no value date. | One signed `Beløb`; constant DKK. | Description from `Tekst`; row type from `Kategori`; no reference. | `Saldo`. | First non-empty `Kontonummer` data cell. |
| Signed card statement | Same header and parser shape as the signed account statement. A normalized `Kontonavn` data cell contains the stable card marker. | Same as the signed account statement. | Same as the signed account statement. | Same as the signed account statement. | `Saldo`. | First non-empty `Kontonummer` data cell. |
| Fee statement | Header is first row and includes `Type`, `Produkt`, `Startdato`, `Beskrivelse`, `Beløb`, `Gebyr`, `Valuta`, and `Saldo`; inspected variants may include completion date. | Prefer valid completion date for booking date; otherwise start date. Start date becomes value date only when different. | Effective amount is signed `Beløb - Gebyr`; fee must be non-negative. Currency comes from `Valuta`. | Description from `Beskrivelse`; row type from `Type`; no reference. | `Saldo`. | None in inspected exports; Account must be selected when no separate exact match exists. |
| Debit/credit statement (OOXML in a `.xls` file) | Pre-header precedes a header found within the first ten rows. Header has separate debit/credit columns and a transaction reference. | Booking and value dates are separate columns. | Exactly one of debit or credit must be present; debit becomes negative and credit positive. Currency comes from `Valuta`. | Base description plus payee for outflow or payer for inflow when not already present; row type joins direction and status; reference from transaction number. | Not available. | A digit-only value following the stable pre-header key `Račun:`. |

The signed-account/signed-card distinction is not a different parsing algorithm. It is only a secondary structural discriminator between two otherwise identical profiles. The remaining differences reduce to a few closed parser modes.

### Historical hardcoded coupling (removed by the 2026-10 cutover)

`statement_import.php` used to contain the provider registry, a provider-returning detector, three provider-named parser paths, provider dispatch, provider labels, provider-specific Account-number branches, and one provider-specific fingerprint exception. `statement_reconciliation.php` also gated one exact-income rule on the ordinary-account provider string. `accounting.php` includes the evidence namespace in file/row identity. The public tests and the synthetic generator now use neutral structural names.

These were the migration inputs, not target architecture.

## 2. Exact proposed Import Profile schema

### SQLite record

A later migration should introduce one table. This is a design, not a migration in this task.

```sql
CREATE TABLE statement_import_profiles (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    name               TEXT NOT NULL,
    enabled            INTEGER NOT NULL DEFAULT 1 CHECK(enabled IN (0,1)),
    evidence_namespace TEXT NOT NULL UNIQUE,
    schema_version     INTEGER NOT NULL,
    signature_hash     TEXT NOT NULL,
    definition_json    TEXT NOT NULL,
    created_at         TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_statement_import_profiles_signature
    ON statement_import_profiles(enabled, signature_hash);
```

The eventual implementation migration may also add:

```text
statement_imports.import_profile_id NULL
    REFERENCES statement_import_profiles(id)
    ON DELETE RESTRICT

statement_rows.classification NULL
    CHECK(classification IS NULL OR classification = 'income')
```

Application validation should enforce a unique trimmed, case-insensitive `name`. A referenced profile is disabled, not deleted. An unused profile may be deleted only when no import references it. `evidence_namespace` is immutable after first use.

### Record fields

| Field | Type | Required | Meaning | Neutral example |
|---|---|---:|---|---|
| `id` | positive integer | server-generated | Installation-local profile identity. It is not an Account ID. | `12` |
| `name` | non-empty string | yes | Private local display name used only in profile administration/diagnostics. | `Test Bank A` |
| `enabled` | boolean | yes | Whether the profile may match new files. Disabled profiles remain available to interpret historical provenance. | `true` |
| `evidence_namespace` | immutable non-empty string | yes | Opaque duplicate/fingerprint namespace written through existing historical `provider` fields. New profiles receive a generated token; it is not displayed or used for matching. | `profile:01JTEST8M4K2` |
| `schema_version` | integer enum | yes | Version of the closed profile JSON schema understood by FIN. | `1` |
| `signature_hash` | 64-character lowercase SHA-256 | derived and persisted | Indexed hash of the canonical base structural signature. | `a4…` |
| `definition_json` | JSON object | yes | Validated declarative signature, structural parser mapping, and optional exact mapping to canonical inert evidence classification. | See the example shape below. |
| `created_at`, `updated_at` | timestamps | server-generated | Administrative lifecycle metadata only. | `2026-09-07 12:00:00` |

### `definition_json`, schema version 1

```json
{
  "signature": {
    "container": "ooxml",
    "sheet": 0,
    "header": [
      {"column": 0, "key": "transactiondate"},
      {"column": 1, "key": "description"},
      {"column": 2, "key": "amount"}
    ],
    "markers": []
  },
  "parser": {
    "booking_date": {"sources": ["transactiondate"]},
    "value_date": null,
    "amount": {"mode": "signed", "column": "amount"},
    "currency": {"mode": "constant", "value": "DKK"},
    "description": {"column": "description", "counterparty_by_sign": null},
    "running_balance": null,
    "reference": null,
    "row_type": null,
    "account_number": {"mode": "none"},
    "classification": {
      "source": "maincategory",
      "mappings": [
        {"normalized_equals": "income-marker", "classification": "income"}
      ]
    }
  }
}
```

Every key is validated against an allowlist; unknown keys or values fail profile saving and parsing.

#### Signature fields

| Field | Type | Required | Meaning | Neutral example |
|---|---|---:|---|---|
| `signature.container` | enum, only `ooxml` in v1 | yes | Byte-level workbook family. File extension is not identity. | `ooxml` |
| `signature.sheet` | integer, only `0` in v1 | yes | First worksheet. V1 deliberately supports one sheet only. | `0` |
| `signature.header` | non-empty ordered array of `{column:int,key:string}` | yes | Exact normalized non-empty header cells, retaining positions and order. Keys use FIN's existing deterministic header normalization. Duplicate keys are rejected. | columns 0–2 above |
| `signature.markers` | array, default empty | yes | Secondary disambiguators used only where identical headers describe different structures. | See below. |
| `markers[].column` | normalized header key | conditional | Data column examined after the matched header. | `accountlabel` |
| `markers[].operator` | enum `contains` or `not_contains` | conditional | Normalized substring condition. No regex. | `contains` |
| `markers[].value` | non-empty normalized string | conditional | Private installation marker. | `testcardmarker` |

Marker evaluation scans at most the first 20 non-empty data rows, a fixed engine limit rather than profile-programmable behavior. Markers must never contain an Account number or transaction value. Positive and negative marker profiles may distinguish otherwise identical shapes. If zero or multiple profiles survive, FIN refuses to choose.

#### Parser fields

| Field | Type | Required | Meaning | Neutral example |
|---|---|---:|---|---|
| `parser.booking_date.sources` | ordered array of 1–2 header keys | yes | First source that parses to a valid date becomes booking date. | `["completeddate","startdate"]` |
| `parser.value_date` | `null` or object `{source:string, omit_if_same_as_booking:boolean}` | yes | Optional separate value date. | `{"source":"startdate","omit_if_same_as_booking":true}` |
| `parser.amount.mode` | enum | yes | One of the three exact amount primitives below. | `signed_less_nonnegative_fee` |
| `parser.amount.column` | header key | for `signed` | Signed amount source. | `amount` |
| `parser.amount.amount_column` | header key | for fee mode | Primary signed amount source. | `amount` |
| `parser.amount.fee_column` | header key | for fee mode | Non-negative charge subtracted from primary amount. | `fee` |
| `parser.amount.debit_column` | header key | for debit/credit mode | Positive magnitude converted to a negative result. | `debit` |
| `parser.amount.credit_column` | header key | for debit/credit mode | Positive magnitude converted to a positive result. | `credit` |
| `parser.currency.mode` | enum `constant` or `column` | yes | Whether evidence currency is profile-fixed or row-provided. | `column` |
| `parser.currency.value` | three-letter uppercase code | for `constant` | Constant evidence currency. | `DKK` |
| `parser.currency.column` | header key | for `column` | Row currency source. | `currency` |
| `parser.description.column` | header key | yes | Base description source. | `description` |
| `parser.description.counterparty_by_sign` | `null` or `{negative:string,positive:string}` | yes | Optional payee/payer headers appended with FIN's fixed separator only when not already present. | `{"negative":"payee","positive":"payer"}` |
| `parser.running_balance` | `null` or header key | yes | Optional running-balance source. | `balance` |
| `parser.reference` | `null` or header key | yes | Optional stable external transaction reference. | `reference` |
| `parser.row_type` | `null` or object `{columns:[string,…]}` with 1–2 keys | yes | Exact source columns joined with FIN's fixed separator. | `{"columns":["direction","status"]}` |
| `parser.account_number.mode` | enum `none`, `column_first_nonempty`, or `preheader_prefixed_digits` | yes | Structural Account-number extraction method. It does not select a profile. | `column_first_nonempty` |
| `parser.account_number.column` | header key | for column mode | Column searched below the header. | `accountnumber` |
| `parser.account_number.prefix` | non-empty literal | for pre-header mode | Exact normalized key before a digit-only Account number. No regex. | `account:` |
| `parser.account_number.minimum_digits` | integer 4–32 | for pre-header mode | Narrow validation bound. | `6` |
| `parser.classification` | `null` or object `{source:string,mappings:array}` | yes | Optional exact external-value mapping to canonical inert evidence metadata. It performs no reconciliation or posting. | See the neutral example above. |
| `parser.classification.source` | normalized header key | when classification is configured | Raw external field read for classification. | `maincategory` |
| `parser.classification.mappings` | array of exactly `{normalized_equals:string,classification:"income"}` entries | when classification is configured | Closed exact normalized mapping. V1 permits only canonical `income`. | `[{"normalized_equals":"income-marker","classification":"income"}]` |

The amount modes are exactly:

1. `signed`: parse one signed amount;
2. `signed_less_nonnegative_fee`: parse signed primary amount and non-negative fee, then subtract fee;
3. `debit_credit`: require exactly one numeric debit/credit value and apply the sign.

The classification mapping is parser-owned structural interpretation only. At import time it produces either `NULL` or canonical `income`, persisted in nullable `statement_rows.classification`. It does not create Income, call reconciliation, create a Transaction or Transfer, choose an Envelope, or change Account state. Persisting it with the normalized evidence ensures later profile edits cannot reinterpret an imported row.

Generic reconciliation—not the profile—owns the only resulting action rule: a positive row with canonical evidence classification `income`, after all existing deterministic safety checks pass, is eligible for Income auto-processing. That rule contains no provider ID, profile ID, profile name, or private marker branch.

### Fields deliberately rejected

V1 has no provider/institution/class field, Account ID, Account name, Account number value, filename pattern, geographic/wallet category, arbitrary regex, formula, expression, script, callback, plugin class, locale program, per-profile row loop, arbitrary filter, posting policy, reconciliation action, or general rule engine. Classification cannot map to Expense, Refund, Transfer, Envelope, merchant, or any value other than the explicitly reviewed inert `income` evidence classification.

These are also unnecessary profile fields in v1:

- **File extension:** inspect OOXML bytes; `.xls`/`.xlsx` is only a transport check.
- **Sheet selector beyond zero:** every current format uses one first worksheet.
- **Header scan limit:** the engine uses one fixed first-20-row bound.
- **Required-header list:** the exact positioned header signature already supplies it.
- **Date-format list:** the engine retains one fixed safe whitelist used by current formats.
- **Decimal rules:** the engine retains the current bounded numeric normalizer.
- **Skip-blank/filter program:** blank rows are always skipped; non-blank malformed rows remain problem evidence.
- **Raw-field mapping:** all header-addressable source fields are retained automatically.
- **Description separator:** FIN owns one fixed separator and duplicate-suppression behavior.
- **Fingerprint recipe:** the engine owns it; profiles cannot redefine evidence identity.
- **Parser version:** this remains an engine/evidence version, not profile-authored data.

## 3. Storage choice

### Selected: SQLite

Profiles should live in the installation database because they are installation-owned operational data.

Benefits:

- included automatically in FIN's existing consistent SQLite backup, restore, production-to-development snapshot, and integrity checks;
- transactional create/edit/disable lifecycle;
- one source of truth for CLI, web, and file-drop imports;
- private with the rest of installation data rather than published in Git;
- straightforward migration and eventual PostgreSQL mapping;
- foreign-key support for new import provenance;
- no permissions, path resolution, synchronization, or partial-write problem from another file.

### Rejected alternatives

A local PHP/JSON configuration file is simple to read but is not included in SQLite backup/restore, is harder to edit atomically from FIN, has separate permission/lifecycle rules, and can drift between CLI and web. `config.local.php` is security/deployment configuration and must not become executable profile storage. A second external service or profile registry is unjustified.

Transient JSON may be accepted by a future CLI save command, but runtime profiles are validated data in SQLite; FIN never executes or continuously loads profile files.

## 4. Structural-signature algorithm

### Persisted signature

FIN persists the `signature` object and an indexed `signature_hash`.

The base hash is:

```text
SHA-256(canonical JSON of {
  container: "ooxml",
  sheet: 0,
  header: ordered [{column, normalized-key}, ...]
})
```

Canonical JSON means UTF-8, fixed key order, integer column indexes, no insignificant whitespace, and FIN's existing text/header normalization. Markers are stored in the definition but excluded from the base hash so structurally identical candidate profiles can be loaded together and disambiguated safely.

### Incoming-file matching

1. Validate size and OOXML structure without trusting the suffix.
2. Read the first worksheet through workbook relationships.
3. For each non-empty row among the first 20 rows, produce the ordered positioned normalized header vector and its base hash.
4. Query enabled profiles by each base hash.
5. Reconfirm the full signature object to defend against hash/storage corruption.
6. Evaluate each candidate's optional marker predicates against at most 20 non-empty data rows following that header.
7. Continue only when exactly one profile matches.

Zero matches means unknown format. Multiple matches means ambiguous format. Both stop before normalized parsing or evidence persistence.

### Stability and privacy

The signature contains workbook structure, headers, positions, and tightly bounded secondary markers. It excludes Account numbers, Account display names, filenames, dates, periods, balances, transaction values, descriptions, references, and personal counterparties. Ordinary statement-period changes therefore do not alter it.

A source-format header change intentionally stops automatic matching. The owner inspects it and creates or updates a profile rather than allowing a near match to parse silently.

### Collision handling

There is no score, priority, or “best guess.” A base collision loads all candidates; markers must leave exactly one. If they do not, FIN reports an administrative ambiguity and imports nothing. The owner must tighten a private profile signature/marker or disable the obsolete profile. Account matching and filenames are never tie-breakers.

For the two current identical-header variants, the private card profile has a positive normalized data-column marker and the ordinary profile has the corresponding negative marker. This is the only current need for markers.

## 5. Generic parser primitive set

The generic engine should remain ordinary PHP and expose only these primitives:

1. **OOXML row extraction:** bounded ZIP/XML parsing, shared/inline strings, first worksheet, no formulas/macros.
2. **Text/header normalization:** current encoding repair, whitespace normalization, lowercasing, and deterministic header keys.
3. **Header/signature matcher:** first-20-row positioned header vectors plus bounded data-column markers.
4. **Column resolver:** validated unique header-key-to-column mapping.
5. **Date converter:** current Excel serial and fixed textual-date whitelist; ordered 1–2-source booking-date fallback and optional value date.
6. **Numeric normalizer:** current finite decimal parsing.
7. **Amount normalizer:** only `signed`, `signed_less_nonnegative_fee`, and `debit_credit`.
8. **Currency extractor:** fixed three-letter constant or three-letter row value.
9. **Description composer:** one base field plus optional sign-selected counterparty with fixed duplicate suppression/separator.
10. **Optional evidence extractors:** running balance, external reference, and one/two-column row type.
11. **Account-number extractor:** none, first non-empty mapped column, or bounded pre-header literal plus digits.
12. **Generic row validator:** invalid date/amount/fee/currency/description becomes parse-problem evidence; blank rows are skipped.
13. **Raw evidence capture:** persist all mapped source header values.
14. **Generic period, identity, duplicate, preview, and persistence path:** no profile-authored identity formula.
15. **Inert evidence classifier:** optional exact normalized external-value mapping to nullable canonical `income`; persist the result on `statement_rows` during import.

The parser stops at normalized inert evidence. Separately, generic reconciliation may treat a positive row whose persisted canonical classification is `income` as eligible for the existing deterministic Income path after every other safety condition passes. The profile is not consulted to choose or perform that financial action.

The fingerprint algorithm remains application code. To preserve the current parser-v1 overlap contract, `signed_less_nonnegative_fee` uses its primary pre-fee amount in the identity input; other modes use their normalized signed amount. Raw mapped date sources remain the identity detail. Profiles cannot customize either rule.

## 6. Mapping the current formats to private profiles

The following are conceptual private records, not files to create now. Names are deliberately neutral; historical provider names appear only in the migration explanation above.

| Private profile name | Signature/matcher | Parser configuration | Inert evidence classification |
|---|---|---|---|
| `Test Bank A` | First-sheet row-1 header vector for `dato/tekst/beløb/saldo/…/kontonummer/kontonavn/…`; `kontonavn not_contains <private card marker>`. | Booking `dato`; signed `beløb`; constant DKK; description `tekst`; balance `saldo`; row type `kategori`; Account number from first non-empty `kontonummer`; no value date/reference. | Exact private `hovedkategori` marker maps to canonical `income`; this only persists evidence metadata. |
| `Test Card` | Same header vector; `kontonavn contains <private card marker>`. | Same structural parser as `Test Bank A`. | None. |
| `Test Wallet` | First-sheet row-1 header vector containing type/product/start/description/amount/fee/currency/balance and, for the inspected variant, completion date/state. | Booking sources completion then start; start as value date when different; signed-minus-nonnegative-fee; currency column; description column; running balance; row type; no Account-number source/reference. | None. |
| `Test Bank B` | Distinct positioned header vector found after pre-header rows. No commercial marker required. | Separate booking/value dates; debit-credit amount; currency column; base description with sign-selected payee/payer; external reference; two-column row type; no running balance; pre-header-prefixed digit Account number. | None. |

All four use the same generic engine. `Test Bank A` and `Test Card` differ only in private match predicates and local display name, not parser code.

No current behavior requires arbitrary profile expressions. The only nontrivial operations—fee subtraction, debit/credit signing, sign-selected counterparties, date fallback, and pre-header Account extraction—are small reusable primitives justified by inspected formats.

## 7. First unknown import workflow

V1 should be CLI-assisted, because a new statement structure is rare in a single-principal self-hosted installation and semantic mapping cannot be inferred safely from headers alone.

1. Normal UI/CLI import reports **No matching Import Profile** and persists nothing.
2. An operator runs a future read-only command such as `php api.php inspect-statement FILE`.
3. The inspector prints bounded, redacted structural facts: candidate header rows/keys, positions, inferred cell kinds, and a few explicitly displayed sample cells. It may generate the deterministic signature skeleton.
4. The operator fills the closed JSON fields: local name, column mappings, one amount mode, currency source, optional evidence fields, and Account-number mode.
5. A future command such as `php api.php save-import-profile PROFILE.json` validates every key/type/reference, previews normalized rows without persistence, displays parse problems and extracted Account number, and requires explicit confirmation before saving to SQLite.
6. The same original file is retried through normal preview/import and now matches the saved profile.

Automatic work is limited to structural extraction, header candidate discovery, signature creation, and validation. FIN must not automatically guess debit/credit semantics, fee signs, Account-number meaning, or financial classification. No runtime AI is needed.

A large web profile-builder is rejected for v1. The ordinary import UI remains simple. If real operation later proves CLI editing too burdensome, a small authenticated admin form may expose the same closed fields without changing the model.

## 8. Known-profile import workflow

1. Decode and bound the source file.
2. Structurally inspect the first worksheet.
3. Resolve exactly one enabled profile through base signature plus markers.
4. Validate the stored profile against its schema version.
5. Run the generic parser primitives and produce normalized rows/problems, including nullable canonical inert evidence classification.
6. Require an explicit current Account selection before Preview. The selector starts at `—`; no file content preselects it.
7. Treat the selected Account as authoritative. An extracted Account identifier may be displayed as informational evidence only and never selects, overrides, rejects, or blocks it.
8. Preview duplicate counts and problems using the profile's immutable evidence namespace.
9. On confirmation, require the same explicit Account selection and persist inert evidence under that Account, with its import profile provenance and the row classification determined at import time.
10. Only after evidence commit, run generic deterministic reconciliation. Exact Account-identifier matching remains available only for counterpart-Account recognition in the canonical Transfer path. Its provider-neutral positive-plus-`income` rule may create Income only after all existing safety conditions pass. No profile match, mapping, or parser step directly creates or chooses financial state.

Later imports inspect only the small structural signature and marker window. They do not recreate or rediscover parser mappings.

## 9. Existing-installation migration strategy

A safe migration needs two application releases rather than one cutover.

### Bridge release

1. Add the profile table/model, strict validator, generic engine, nullable import provenance field with retained-profile semantics, and nullable canonical statement-row classification.
2. Keep current hardcoded detection/dispatch as an explicit temporary fallback.
3. Allow profile-backed parsing in parallel and compare normalized output, problems, Account extraction, period, raw fields, and fingerprints in tests/diagnostics.
4. Add generic CLI inspect/save/list/disable commands.
5. The operator imports four private profile definitions outside the public repository. No production profile definition or commercial display name is seeded by public migration source.
6. Against private local sample files, run a read-only equivalence preview proving generic output and duplicate identity match current behavior.

### Cutover release

1. Require structural profile matching for new imports; historical namespace lookup is never parser selection.
2. Keep historical evidence readable through retained profile FK or opaque namespace resolution.
3. Remove fallback only after the installation has the needed private profiles and equivalence checks pass.
4. Remove commercial detector/dispatch/labels and convert public tests to neutral profile identities.

This avoids embedding real private profiles in a public migration and avoids a flag-day rewrite.

## 10. Historical `statement_imports` / `statement_rows` compatibility

### Keep historical provider strings unchanged

Existing `statement_imports.provider` and `statement_rows.provider` values should remain byte-for-byte unchanged. They are historical opaque provenance/duplicate namespaces after cutover, not active parser identities and not user-facing labels. Rewriting them risks unique constraints, file duplicate recognition, row fingerprints, and reconciliation history for no financial benefit.

### New nullable provenance and classification fields

A later migration should add:

```text
statement_imports.import_profile_id NULL
    REFERENCES statement_import_profiles(id)
    ON DELETE RESTRICT

statement_rows.classification NULL
    CHECK(classification IS NULL OR classification = 'income')
```

No profile FK is needed on every `statement_rows` row because `import_id` already identifies its import. Every new profile-backed import sets `import_profile_id`; old pre-profile imports legitimately remain null and require no backfill. Every new row persists the classification produced at import time. Existing historical rows remain `classification = NULL`; they are not retroactively reclassified or rewritten. Already-created authoritative records and reconciliation links remain unchanged, while unresolved historical credits remain reviewable rather than being newly guessed.

Profile lifecycle is strict:

- an unused profile may be deleted only when no import references it;
- a used profile may be renamed for local display or disabled for future matching;
- `ON DELETE RESTRICT` prevents deletion while any import references it;
- `evidence_namespace` remains immutable after first use;
- v1 has no profile-history/version table and does not snapshot full profile JSON into each import.

For interpreting historical evidence only, generic code resolves profile context in this order:

1. the import's `import_profile_id`, when present;
2. otherwise the unique retained profile whose immutable `evidence_namespace` equals the old provider string;
3. otherwise no profile context is available and the evidence remains reviewable/reconcilable without guessing.

This fallback is never used to select a parser for a new file. New files are selected only by structural signature and bounded markers. Disabling a profile prevents new structural matching but does not remove its historical provenance/context.

### Duplicate/fingerprint compatibility

Existing private profiles for the current formats receive their old provider strings as private `evidence_namespace` values. New profile-backed imports continue using that namespace in existing provider columns and identity inputs, so exact-file and overlapping-row detection continue to see old evidence. Newly created profiles receive generated opaque namespaces.

The generic engine must reproduce the current row fingerprint inputs, including pre-fee primary amount identity for the fee format and raw mapped date source detail. Existing fingerprints are never recalculated.

### Historical financial safety

`statement_imports` and `statement_rows` remain disposable evidence. Used profiles are retained as provenance while referenced and become deletable only after their imports no longer reference them. No Transaction, Account event/movement, Envelope movement, Balance, Plan, report, or recommendation gains a dependency on a profile or classification. Existing reconciliation links and authoritative records remain unchanged if evidence is later removed.

## 11. Public/private boundary

### Public repository after completed implementation

Public source contains:

- generic OOXML extraction and fixed parser primitives;
- profile schema validation/storage/API/CLI machinery;
- structural signature matching and collision refusal;
- neutral profile names and fully synthetic structural fixtures in tests;
- generic evidence namespace/profile terminology;
- no commercial provider registry, detector result, dispatch branch, parser/helper name, label map, constant, fixture selector, or test assertion;
- no real Account numbers, filenames, private statements, or installation profile definitions.

External-language header strings may appear only in synthetic structural fixtures when they are necessary to prove normalization of that file shape; they do not identify a commercial provider. Public helpers should use names such as structural format A, card variant, fee format, and debit/credit format—not institution names.

### Private installation data

The SQLite database contains:

- actual profile definitions and signatures;
- local profile display names, which may use real commercial names;
- private distinguishing markers and exact external values mapped to inert canonical `income` evidence classification;
- compatibility evidence namespaces;
- profile-to-import provenance.

Private source statement files remain outside Git exactly as today.

## 12. Testing strategy

All tests continue to use temporary SQLite databases and self-owned synthetic OOXML.

1. **Profile validation tests:** reject unknown keys, executable-looking fields, invalid enums, bad column references, duplicate headers, invalid currency, oversized marker values, unsafe Account extraction, any classification other than `income`, and any mapping shape beyond exact normalized equality.
2. **Signature tests:** stable hash across transaction periods/values/Account numbers; header-position changes produce a new hash; zero/one/multiple profile outcomes; positive/negative marker distinction.
3. **Primitive unit tests:** three amount modes, date-source fallback, value-date omission, fixed currency/column currency, running balance, reference, row type, counterparty composition, Account extraction, and exact inert classification mapping.
4. **Neutral contract tests:** four temporary profiles named `Test Bank A`, `Test Card`, `Test Wallet`, and `Test Bank B` exercise the current four structural shapes without commercial IDs.
5. **Inertness tests:** parsing and classification create only statement evidence; classification alone has no financial effect. Generic reconciliation separately proves positive persisted `income` classification plus all safety checks, without provider/profile branching.
6. **Provenance lifecycle tests:** deletion of an unused profile succeeds; deletion of a referenced profile is restricted; rename/disable retains new-import provenance; deleting evidence never deletes financial truth.
7. **Account-separation tests:** identical format files can belong to different Accounts; profile match never depends on Account name/number; Account matching occurs only after parsing.
8. **Compatibility tests:** bridge generic output equals current parser output; legacy provider namespace yields identical file/row duplicate behavior; old imports with null profile FK and null classification remain readable; historical namespace resolution never selects a parser for a new file.
9. **Migration tests:** profile/FK/classification schema creation is idempotent, existing classifications remain null, and all old evidence bytes/IDs/fingerprints/reconciliation links are preserved without backfill.
10. **Browser tests:** only normal unknown/known preview behavior and Account selection need browser coverage. Profile primitive permutations belong in PHP tests, not duplicated browser scenarios.

Real installation profile definitions and real files must never be copied into the public test tree.

## 13. Staged implementation plan

### Stage 1 — closed profile model and validator

Add the SQLite profile table, nullable `statement_rows.classification`, model, schema-v1 validator, canonical signature hashing, and neutral profile fixtures. Existing rows remain null and no import behavior changes. Test validation, unused deletion, disable lifecycle, and migration preservation.

### Stage 2 — generic engine in parallel

Extract current common OOXML, normalization, and evidence-row behavior into generic primitives. Parse neutral test profiles through the engine while old dispatch remains authoritative. Add byte/row contract comparisons. No production cutover.

### Stage 3 — provenance and compatibility bridge

Add nullable `statement_imports.import_profile_id` with `ON DELETE RESTRICT`, immutable evidence namespaces, historical-only namespace resolution, and generic fingerprint compatibility. Profile-backed imports may be explicitly previewed, while the temporary old parser fallback remains available. Verify no old evidence rewrite and retained provenance for new imports.

### Stage 4 — minimal operator tooling and private provisioning

Add read-only inspect plus validated save/list/disable CLI commands. The operator creates the installation's private profiles and runs read-only equivalence previews against local samples. No public commercial definitions are added.

### Stage 5 — profile-first matching

Make structural profile matching the normal path. Zero/multiple matches fail safely; exactly one invokes the generic engine and persists its inert classification result. Account resolution stays separate. Generic reconciliation consumes persisted canonical classification without provider/profile-name branching. Retain the old path only as a temporary explicit bridge and measure/test equivalence.

### Stage 6 — neutralize public tests, then remove fallback

Move all public parser/import tests and generators to neutral profile names/constants/helpers. Remove old commercial parser dispatch only after equivalent neutral tests cover every primitive and the private installation is provisioned.

### Stage 7 — final source audit

Search code, tests, fixtures, configuration, current architecture, and user-facing output for commercial parser identity, obsolete provider APIs, and accidental private profile data. Preserve historical reports only as historical documentation. Verify only generic profile machinery remains active.

This ordering keeps each stage independently understandable and testable and avoids combining storage, parser rewrite, production provisioning, test conversion, and deletion in one release.

## 14. Deletion target after completed migration

The final migration should remove or replace, not retain under aliases:

- `STATEMENT_PROVIDERS`;
- `statement_detect_provider()` and provider-returning detector values;
- `statement_provider_label()` and hardcoded provider labels;
- provider argument/selector compatibility on `statement_parse_file()`, preview, and import;
- provider-specific dispatch in `statement_parse_file()`;
- the three provider-named parser entry points as named public mechanisms after their behavior is absorbed by primitives;
- provider-string branches in Account-number extraction;
- provider-string branch used for the pre-fee fingerprint compatibility rule, replaced by generic amount-mode semantics;
- provider-string gating of deterministic Income in `statement_reconciliation_is_deterministic_income()`, replaced by generic reconciliation checking positive evidence plus persisted canonical `classification = 'income'` and all existing safety conditions;
- commercial provider constants, selectors, helper/method names, filenames, and assertions in public PHP/browser tests and synthetic generators;
- provider-specific parser error text and the current `.xls` error wording that names a source;
- API response fields whose only purpose is a hardcoded provider label. If diagnostics need a label, use the private profile name; ordinary users need no parser selector;
- active documentation claiming a built-in list of supported commercial providers.

The existing database columns named `provider` are not a required physical deletion target. They may remain compatibility columns containing opaque historical namespaces until a separately justified schema cleanup; active code must stop treating their values as commercial dispatch identifiers.

Unrelated historical migration fields or old factual reports are not parser architecture and should not be rewritten merely to satisfy a text search.

## 15. Why this is not overengineering

Profiles solve one real distinction: external statement structures vary while FIN's normalized evidence contract does not. Today that distinction is encoded as commercial names and branching PHP. A validated data record plus three amount modes and a handful of field extractors moves only the variation into installation data.

This is smaller than provider plugins/classes because:

- there is one process and one parser engine;
- profiles contain data only;
- all operations come from closed enums;
- one SQLite table replaces a registry, detector values, label map, dispatch, and provider-specific helpers;
- first-format setup is rare and CLI-assisted;
- normal imports remain automatic and the ordinary UI gains no parser choice.

Deliberately rejected abstractions include:

- plugin marketplace or provider SDK;
- arbitrary PHP/JavaScript/scripts;
- callbacks, expressions, `eval`, or generic ETL DSL;
- arbitrary regex/transformation pipelines;
- provider classes or one process per parser;
- remote profile service/registry or automatic profile download;
- automatic web scraping;
- runtime AI dependency;
- filename/provider/geography-based selection;
- Account-to-profile binding;
- generic CSV/PDF/HTML ingestion;
- arbitrary multicurrency architecture;
- profile-driven financial posting or merchant classification;
- arbitrary evidence-classification taxonomies or mappings beyond the one reviewed exact external-value-to-`income` need;
- a large profile-builder UI.

The one classification mapping is not a posting abstraction: it preserves inert source meaning once at import, while generic reconciliation alone owns any later Income creation.

FIN still supports only bounded OOXML statements expressible by the actual primitives above. It remains a financial planner with inert statement evidence, not a general data-integration platform.

## 16. Risks and hard stops

### Risks controlled by the design

- **Identical headers:** bounded private markers may distinguish them; otherwise ambiguity stops import.
- **Header drift:** exact structural mismatch stops import rather than guessing.
- **Fragile card marker:** it is a private secondary discriminator. Missing/inconsistent marker must stop or become ambiguous; Account identity and filename must not substitute.
- **Profile editing:** schema validation, immutable evidence namespace, disable-not-delete lifecycle for used profiles, restricted deletion, and read-only preview are required before use. Persisted row classification never changes when a profile is edited.
- **Malicious profile JSON:** enforce size/depth/string limits and closed keys/enums. Never interpret code or regex.
- **Historical duplicates:** preserve namespace and fingerprint inputs; do not recalculate existing rows.
- **Commercial policy leakage:** actual names, markers, and exact external classification values stay in private SQLite; public reconciliation knows only canonical inert `income`.
- **Profile provenance loss:** `ON DELETE RESTRICT` retains every profile referenced by a new import, and SQLite backup/restore preserves it with the evidence.
- **Multiple profile candidates:** no scoring or fallback; stop.

### Hard stops

The profile language must not be extended to express any of these:

- arbitrary formulas or conditional expressions;
- executable date/number transformations;
- arbitrary regex capture/replacement;
- multi-row state machines beyond the existing running-balance read;
- formula evaluation, macros, encrypted workbooks, web requests, or external commands;
- multiple unrelated tables/sheets in one file;
- financial posting, Envelope choice, fuzzy merchant matching, or evidence classifications beyond the explicitly reviewed canonical inert `income` value.

If a real future statement needs behavior outside the closed primitives, implementation must stop at design review. Inspect real structural evidence, then add the smallest named generic PHP primitive with bounded inputs and focused tests. Do not solve it by making profiles executable.

No inspected current behavior crosses this hard stop.

## 17. Files inspected

Architecture/product context read:

- `AGENTS.md`
- `docs/architecture/FIN_BACKLOG.md`
- `docs/architecture/VISION.md`
- `docs/architecture/PRINCIPLES.md`
- `docs/architecture/PROJECT.md`
- `docs/architecture/QUESTIONS.md`
- `docs/architecture/ARCHITECT_REVIEW.md`
- `docs/architecture/ROADMAP.md`
- `docs/architecture/CURRENT_STATE.md`
- `docs/architecture/FIN_PROVIDER_TEST_LABEL_FINAL_CLEANUP_2026-09-07.md`

Implementation/configuration inspected without editing:

- `statement_import.php` (complete file)
- `accounting.php` (statement identity, normalization, and Account-number sections)
- `api.php` (statement endpoints, evidence schema/migrations, and CLI entry points)
- `statement_reconciliation.php` (provider-dependent deterministic-income section and provider references)
- `config.php`
- `config.local.example.php`

Parser-related tests/helpers read without editing:

- `tests/php/Fixtures/SyntheticStatementGenerator.php`
- `tests/php/SyntheticStatementParserContractTest.php`
- `tests/php/StatementImportTest.php`
- `tests/php/StatementImportRealFilesTest.php`
- `tests/php/StatementExplicitAccountOwnershipTest.php`
- `tests/browser/SyntheticXlsx.cjs`

Repository searches were also used to locate active commercial/provider dispatch dependencies and test references. No source file was executed.

## 18. Task confirmations

- Exactly one new file was created by Task 3A: this report.
- Task 3A1 corrected this report in place and created no additional report.
- No other existing file was modified by Task 3A or Task 3A1.
- `FIN_BACKLOG.md` was inspected and not modified.
- No application, test, fixture, configuration, migration, protected-test, runtime-data, or `/tmp` file was modified.
- No Import Profile implementation, statement-exception batch, or Playwright port work was started.
- No test, syntax suite, migration, statement import, or application command was run.
- Git index remained empty.
- Nothing was staged, committed, pushed, tagged, released, or deployed.
