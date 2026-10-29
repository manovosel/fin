<?php

declare(strict_types=1);

// Statement reconciliation — ordinary Expense, Refund, Account-to-Account Transfer, and No FIN action slices.
//
// Scope of this module:
//   - v1.24.0 / DB v2.9 ordinary-expense reconciliation slice;
//   - v1.27.0 / DB v2.11 ordinary Account-to-Account Transfer reconciliation slice;
//   - v1.29.0 / DB v2.13 explicit "No FIN action" disposition slice;
//   - v1.30.0 / DB v2.14 explicit authoritative Refund Transaction slice;
//   - v1.31.0 parser v2 effective provider-fee amount flows through the same ordinary semantics.
//
// Concrete responsibilities:
//   - find plausible existing FIN Expense/Refund Transactions for one statement row;
//   - find plausible existing FIN Account events (event_type='transfer') for one statement row;
//   - confirm an existing match (creates a statement_reconciliations link only);
//   - link one statement row to the Transfer that the other side of the same real movement
//     already recorded, so one real payment is one Transfer;
//   - create a missing Expense or Refund through the normal Transaction path and link it to the
//     statement row in the same DB transaction;
//   - create the missing ordinary Account-to-Account Transfer through record_account_transfer
//     and link it to the statement row in the same DB transaction;
//   - explicitly mark a statement row as "No FIN action" when the user has reviewed it and
//     decided the external evidence has no authoritative FIN financial counterpart;
//   - remove a reconciliation link (provenance only — never touches finance).
//
// Explicitly out of scope for this module:
//   - borrowing / repayment / card-chain reconciliation (subsumed by ordinary Transfer);
//   - persistent merchant rules/suggestion models, confidence tiers, or suggestion UI;
//   - file-drop ingestion, import/imported movement, watcher infrastructure;
//   - FX-accounting semantics (cross-currency Transfer accounting is not invented here).
//
// Provider-specific raw amount/fee normalization belongs to statement_import.php. This module
// deliberately consumes only the resulting effective statement_rows.amount.

const STATEMENT_RECONCILIATION_DATE_TOLERANCE_DAYS = 4;
const STATEMENT_RECONCILIATION_AMOUNT_TOLERANCE = 0.01;
const STATEMENT_RECONCILIATION_PRESELECTION_THRESHOLD = 75.0;
const STATEMENT_RECONCILIATION_BATCH_SIZE = 32;

function statement_reconciliation_repair_persisted_description(PDO $db, array $row): array
{
    $description = (string)($row['description_original'] ?? '');
    if (statement_text_is_valid_utf8($description)) return $row;

    $repaired = statement_repair_text($description);
    if (!statement_text_is_valid_utf8($repaired)) {
        throw new RuntimeException('Statement description could not be repaired as UTF-8');
    }
    $normalized = statement_normalize_token($repaired);
    $update = $db->prepare('UPDATE statement_rows
        SET description_original = :description, description_normalized = :normalized
        WHERE id = :id');
    $update->execute([
        ':description' => $repaired,
        ':normalized' => $normalized,
        ':id' => (int)($row['id'] ?? $row['statement_row_id'] ?? 0),
    ]);
    $row['description_original'] = $repaired;
    $row['description_normalized'] = $normalized;
    return $row;
}

function statement_reconciliation_comparison_description(string $description): string
{
    $description = statement_repair_text($description);
    $normalized = function_exists('mb_strtolower')
        ? mb_strtolower($description, 'UTF-8')
        : strtolower($description);
    $normalized = preg_replace('/[\p{P}]+/u', '', $normalized) ?? $normalized;
    $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
    return trim($normalized);
}

function statement_reconciliation_levenshtein_score(string $left, string $right): float
{
    if ($left === $right) return 100.0;
    $length = max(strlen($left), strlen($right));
    if ($length === 0) return 100.0;
    return max(0.0, (1.0 - (levenshtein($left, $right) / $length)) * 100.0);
}

function statement_reconciliation_characters(string $value): array
{
    $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
    return $characters === false ? str_split($value) : $characters;
}

function statement_reconciliation_description_similarity(string $left, string $right): float
{
    $left = statement_reconciliation_comparison_description($left);
    $right = statement_reconciliation_comparison_description($right);
    $wholeStringScore = statement_reconciliation_levenshtein_score($left, $right);

    $leftCompact = preg_replace('/\s+/u', '', $left) ?? $left;
    $rightCompact = preg_replace('/\s+/u', '', $right) ?? $right;
    $leftCharacters = statement_reconciliation_characters($leftCompact);
    $rightCharacters = statement_reconciliation_characters($rightCompact);
    if (count($leftCharacters) <= count($rightCharacters)) {
        $shorter = $leftCompact;
        $shorterCharacters = $leftCharacters;
        $longerCharacters = $rightCharacters;
    } else {
        $shorter = $rightCompact;
        $shorterCharacters = $rightCharacters;
        $longerCharacters = $leftCharacters;
    }

    $shorterLength = count($shorterCharacters);
    if ($shorterLength < 4) return $wholeStringScore;

    $bestLocalScore = 0.0;
    $lastOffset = count($longerCharacters) - $shorterLength;
    for ($offset = 0; $offset <= $lastOffset; $offset++) {
        $window = implode('', array_slice($longerCharacters, $offset, $shorterLength));
        $bestLocalScore = max(
            $bestLocalScore,
            statement_reconciliation_levenshtein_score($shorter, $window)
        );
        if ($bestLocalScore === 100.0) break;
    }

    return max($wholeStringScore, $bestLocalScore);
}

/**
 * Return ordinary pending classifications inferred from existing FIN history.
 * Transaction notes, Transfer notes, and reconciled statement descriptions are
 * compared without persisting a separate model. The result is transient transport
 * data; the caller places it directly into the
 * existing pending-selection path and does not retain separate suggestion state.
 *
 * A Transfer destination is pre-filled only when the statement evidence itself names a
 * counterpart Account. A row whose evidence names none keeps the destination to the
 * owner's choice: the statement cannot say where the money went or came from.
 */
function statement_reconciliation_history_preselections(PDO $db, int $statementAccountId, array $rows): array
{
    if (!$rows) return [];

    $selectableEnvelopeIds = array_fill_keys(array_map('intval', $db->query('SELECT id FROM envelopes')->fetchAll(PDO::FETCH_COLUMN)), true);
    $accountStmt = $db->prepare('SELECT id FROM accounts WHERE active=1 AND id<>:statement_account_id');
    $accountStmt->execute([':statement_account_id' => $statementAccountId]);
    $selectableAccountIds = array_fill_keys(array_map('intval', $accountStmt->fetchAll(PDO::FETCH_COLUMN)), true);

    $history = [];
    foreach ($db->query("SELECT t.id, t.created_at, t.note, t.envelope_id
        FROM transactions t
        WHERE trim(t.note)<>'' AND t.envelope_id IS NOT NULL") as $transaction) {
        $envelopeId = (int)$transaction['envelope_id'];
        if (!isset($selectableEnvelopeIds[$envelopeId])) continue;
        $history[] = [
            'kind' => 'envelope',
            'description' => (string)$transaction['note'],
            'target_id' => $envelopeId,
            'statement_account_id' => null,
            'direction' => 0,
            'source_account_id' => null,
            'destination_account_id' => null,
            'recency' => (string)$transaction['created_at'],
            'record_id' => (int)$transaction['id'],
            'source_key' => 'transaction',
        ];
    }
    foreach ($db->query("SELECT ae.id, ae.created_at, ae.note, ae.source_account_id, ae.destination_account_id
        FROM account_events ae
        WHERE ae.event_type='transfer' AND trim(ae.note)<>''") as $event) {
        $history[] = [
            'kind' => 'transfer',
            'description' => (string)$event['note'],
            'target_id' => null,
            'statement_account_id' => null,
            'direction' => 0,
            'source_account_id' => (int)$event['source_account_id'],
            'destination_account_id' => (int)$event['destination_account_id'],
            'recency' => (string)$event['created_at'],
            'record_id' => (int)$event['id'],
            'source_key' => 'transfer',
        ];
    }
    foreach ($db->query("SELECT sr.id, sr.created_at, sr.disposition,
            prior.account_id, prior.amount, prior.description_original,
            t.envelope_id,
            ae.event_type, ae.source_account_id, ae.destination_account_id
        FROM statement_reconciliations sr
        JOIN statement_rows prior ON prior.id=sr.statement_row_id
        LEFT JOIN transactions t ON t.id=sr.transaction_id
        LEFT JOIN account_events ae ON ae.id=sr.target_account_event_id
        WHERE sr.transaction_id IS NOT NULL
            OR sr.target_account_event_id IS NOT NULL
            OR sr.disposition='no_fin_action'") as $reconciliation) {
        $envelopeId = $reconciliation['envelope_id'] === null ? 0 : (int)$reconciliation['envelope_id'];
        if ((string)($reconciliation['disposition'] ?? '') === 'no_fin_action') {
            $kind = 'no_fin_action';
            $targetId = null;
        } elseif ($envelopeId > 0 && isset($selectableEnvelopeIds[$envelopeId])) {
            $kind = 'envelope';
            $targetId = $envelopeId;
        } elseif ((string)($reconciliation['event_type'] ?? '') === 'transfer') {
            $kind = 'transfer';
            $targetId = null;
        } else {
            continue;
        }
        $amount = (float)$reconciliation['amount'];
        $history[] = [
            'kind' => $kind,
            'description' => (string)$reconciliation['description_original'],
            'target_id' => $targetId,
            'statement_account_id' => (int)$reconciliation['account_id'],
            'direction' => $amount < 0 ? -1 : ($amount > 0 ? 1 : 0),
            'source_account_id' => $reconciliation['source_account_id'] === null ? null : (int)$reconciliation['source_account_id'],
            'destination_account_id' => $reconciliation['destination_account_id'] === null ? null : (int)$reconciliation['destination_account_id'],
            'recency' => (string)$reconciliation['created_at'],
            'record_id' => (int)$reconciliation['id'],
            'source_key' => 'reconciliation',
        ];
    }

    $preselections = [];
    foreach ($rows as $row) {
        $description = statement_reconciliation_comparison_description((string)($row['description_original'] ?? ''));
        if ($description === '') continue;
        $currentAmount = (float)($row['amount'] ?? 0);
        $currentDirection = $currentAmount < 0 ? -1 : ($currentAmount > 0 ? 1 : 0);
        $best = null;
        $counterpartNamed = null;

        foreach ($history as $historical) {
            if ($historical['kind'] === 'envelope') {
                $classification = 'envelope:' . (int)$historical['target_id'];
            } elseif ($historical['kind'] === 'no_fin_action') {
                $classification = 'no_fin_action';
            } else {
                // The Transfer destination is a routing decision the statement itself has to
                // make. When the evidence names no counterpart Account, FIN cannot know the
                // destination, so earlier history must not fill it in either.
                if ($counterpartNamed === null) {
                    $counterpartNamed = statement_reconciliation_row_names_counterpart_account($db, (int)($row['id'] ?? 0));
                }
                if (!$counterpartNamed) continue;
                if ($currentDirection === 0) continue;
                if ($historical['statement_account_id'] !== null
                    && ((int)$historical['statement_account_id'] !== $statementAccountId
                        || (int)$historical['direction'] !== $currentDirection)) continue;
                if ($currentDirection < 0 && (int)$historical['source_account_id'] === $statementAccountId) {
                    $counterpartId = (int)$historical['destination_account_id'];
                } elseif ($currentDirection > 0 && (int)$historical['destination_account_id'] === $statementAccountId) {
                    $counterpartId = (int)$historical['source_account_id'];
                } else {
                    continue;
                }
                if (!isset($selectableAccountIds[$counterpartId])) continue;
                $classification = 'account:' . $counterpartId;
            }

            $score = statement_reconciliation_description_similarity($description, (string)$historical['description']);
            if ($score < STATEMENT_RECONCILIATION_PRESELECTION_THRESHOLD) continue;
            $isNewerTie = $best !== null
                && $score === $best['score']
                && ((string)$historical['recency'] > $best['recency']
                    || ((string)$historical['recency'] === $best['recency']
                        && ((int)$historical['record_id'] > $best['record_id']
                            || ((int)$historical['record_id'] === $best['record_id']
                                && (string)$historical['source_key'] > $best['source_key']))));
            if ($best === null || $score > $best['score'] || $isNewerTie) {
                $best = [
                    'classification' => $classification,
                    'score' => $score,
                    'recency' => (string)$historical['recency'],
                    'record_id' => (int)$historical['record_id'],
                    'source_key' => (string)$historical['source_key'],
                ];
            }
        }

        if ($best !== null && $best['classification'] !== 'no_fin_action') {
            $preselections[] = [
                'statement_row_id' => (int)$row['id'],
                'classification' => $best['classification'],
            ];
        }
    }
    return $preselections;
}

function statement_reconciliation_supported_currency(PDO $db, string $currency): bool
{
    $context = currency_context($db);
    return in_array(strtoupper(trim($currency)), [$context['currency'], $context['helper_currency']], true);
}

function statement_reconciliation_load_row(PDO $db, int $rowId): array
{
    $stmt = $db->prepare('SELECT r.id, r.import_id, r.account_id, r.provider, r.booking_date, r.value_date,
        r.amount, r.currency, r.description_original, r.description_normalized,
        r.provider_reference, r.running_balance, r.provider_row_type, r.parse_problem, r.raw_fields_json,
        r.classification,
        (SELECT s.original_filename FROM statement_imports s WHERE s.id = r.import_id) AS import_filename,
        (SELECT s.import_profile_id FROM statement_imports s WHERE s.id = r.import_id) AS import_profile_id
        FROM statement_rows r WHERE r.id = :id');
    $stmt->execute([':id' => $rowId]);
    $row = $stmt->fetch();
    if (!$row) throw new InvalidArgumentException('Statement row not found');
    return statement_reconciliation_repair_persisted_description($db, $row);
}

/**
 * Convert a Helper-currency statement amount to the authoritative Currency value
 * using the Month containing the statement's booking_date. Returns null when no usable rate
 * exists, in which case the matching rule must skip cross-currency comparison for that row.
 */
function statement_reconciliation_amount_in_currency(PDO $db, float $amount, string $currency, string $eventDate): ?float
{
    return evidence_amount_to_currency($db, $amount, $currency, $eventDate);
}

/**
 * Find plausible existing transaction and Transfer matches for a single statement row.
 *
 * Transaction matching rule (deterministic, no confidence tiers):
 *   1. payment_account_id = statement row's account_id, or payment_account_id IS NULL when no
 *      Transaction with that exact Account matches: a known payment Account is the stronger
 *      discriminator and always wins over an unknown one, so a Transaction paid from a
 *      different Account is still never a candidate;
 *   2. signed direction maps negative evidence to Expense and positive evidence to Refund;
 *   3. authoritative Currency amounts match within 0.01 after statement conversion;
 *   4. |transaction.spent_date - statement_row.booking_date| <= 4 calendar days;
 *   5. rows with parse problems, zero amounts, unsupported currencies, or unusable rates yield
 *      no candidates;
 *   6. an already reconciled statement row yields no candidates.
 *
 * Transfer matching rule (deterministic, no confidence tiers):
 *   1. account_events.source_account_id OR destination_account_id = statement row's account_id;
 *   2. event_type = 'transfer';
 *   3. signed direction matches: a negative statement amount matches an event whose source-side
 *      movement on the statement's Account is negative; a positive amount matches an event whose
 *      destination-side movement on the statement's Account is positive;
 *   4. |movement amount| matches |statement amount| in Currency after converting a
 *      Helper-currency statement using the Month exchange rate;
 *   5. |event_date - statement_row.booking_date| <= 4 calendar days.
 *
 * Candidates are returned as one combined list with a structural 'kind' discriminator
 * ('transaction' or 'account_event'); Transaction subtype is in 'transaction_kind'. They are
 * ordered by ascending date distance, structural kind, then target id. The Transfer slice
 * does not constrain candidate eligibility by the OTHER-side Account's configured currency; per
 * the canonical architecture Account currency is presentation only.
 */
function find_reconciliation_candidates(PDO $db, int $rowId): array
{
    $row = statement_reconciliation_load_row($db, $rowId);
    $existing = $db->prepare('SELECT 1 FROM statement_reconciliations WHERE statement_row_id = :rid');
    $existing->execute([':rid' => $rowId]);
    if ($existing->fetchColumn()) return ['statement_row' => $row, 'candidates' => [], 'already_reconciled' => true];

    if (!statement_reconciliation_supported_currency($db, (string)$row['currency'])) {
        return ['statement_row' => $row, 'candidates' => [], 'already_reconciled' => false];
    }
    if ((string)$row['booking_date'] === '' || $row['parse_problem'] !== '') {
        return ['statement_row' => $row, 'candidates' => [], 'already_reconciled' => false];
    }
    $amount = (float)$row['amount'];
    if (!is_finite($amount) || $amount === 0.0) {
        return ['statement_row' => $row, 'candidates' => [], 'already_reconciled' => false];
    }
    $absAmount = abs($amount);

    // Transaction candidates — convert evidence using each authoritative candidate date.
    // A Transaction without a payment Account is a candidate only when no Transaction paid
    // from the statement Account matches: the known Account is the stronger discriminator.
    $exactTransactionCandidates = [];
    $unpaidTransactionCandidates = [];
    $requiredKind = $amount > 0 ? 'refund' : 'expense';
    $preCandidates = $db->prepare("SELECT t.id, t.kind, t.spent_date, t.envelope, t.envelope_id, t.amount,
            t.note, t.payment_account_id,
            ABS(JULIANDAY(t.spent_date) - JULIANDAY(:booking_date)) AS date_distance
            FROM transactions t
            WHERE (t.payment_account_id = :account_id OR t.payment_account_id IS NULL)
              AND t.kind = :kind
              AND t.spent_date BETWEEN :from_date AND :to_date
              AND NOT EXISTS (SELECT 1 FROM statement_reconciliations linked WHERE linked.transaction_id = t.id)
            ORDER BY date_distance ASC, t.id ASC");
        $preCandidates->execute([
            ':account_id' => (int)$row['account_id'],
            ':kind' => $requiredKind,
            ':booking_date' => (string)$row['booking_date'],
            ':from_date' => date('Y-m-d', strtotime((string)$row['booking_date'] . ' -' . STATEMENT_RECONCILIATION_DATE_TOLERANCE_DAYS . ' days')),
            ':to_date' => date('Y-m-d', strtotime((string)$row['booking_date'] . ' +' . STATEMENT_RECONCILIATION_DATE_TOLERANCE_DAYS . ' days')),
        ]);
    foreach ($preCandidates->fetchAll() as $transaction) {
            $distance = (int)round((float)$transaction['date_distance']);
            if ($distance > STATEMENT_RECONCILIATION_DATE_TOLERANCE_DAYS) continue;
            $currencyAmount = statement_reconciliation_amount_in_currency($db, $absAmount, (string)$row['currency'], (string)$transaction['spent_date']);
            if ($currencyAmount === null || abs(abs((float)$transaction['amount']) - $currencyAmount) > STATEMENT_RECONCILIATION_AMOUNT_TOLERANCE) continue;
            $candidate = [
                'kind' => 'transaction',
                'transaction_kind' => $requiredKind,
                'transaction_id' => (int)$transaction['id'],
                'spent_date' => (string)$transaction['spent_date'],
                'envelope' => (string)$transaction['envelope'],
                'envelope_id' => $transaction['envelope_id'] === null ? null : (int)$transaction['envelope_id'],
                'amount' => (float)$transaction['amount'],
                'note' => (string)$transaction['note'],
                'payment_account_id' => $transaction['payment_account_id'] === null ? null : (int)$transaction['payment_account_id'],
                'date_distance_days' => $distance,
            ];
            if ($transaction['payment_account_id'] === null) {
                $unpaidTransactionCandidates[] = $candidate;
            } else {
                $exactTransactionCandidates[] = $candidate;
            }
        }
    // An exact-account match always wins over an unknown one; several equal matches stay
    // ambiguous downstream and are reported as such.
    $transactionCandidates = $exactTransactionCandidates !== [] ? $exactTransactionCandidates : $unpaidTransactionCandidates;

    // Transfer candidates — convert evidence using each authoritative candidate date.
    $transferCandidates = [];
    $eventCandidates = $db->prepare("SELECT ae.id, ae.event_date, ae.amount, ae.source_account_id, ae.destination_account_id, ae.note,
            src.name AS source_account_name, dest.name AS destination_account_name,
            ABS(JULIANDAY(ae.event_date) - JULIANDAY(:booking_date)) AS date_distance,
            (SELECT amount FROM account_movements WHERE event_id = ae.id AND account_id = :account_id LIMIT 1) AS movement_amount
            FROM account_events ae
            JOIN accounts src ON src.id = ae.source_account_id
            JOIN accounts dest ON dest.id = ae.destination_account_id
            WHERE ae.event_type = 'transfer'
              AND (ae.source_account_id = :account_id2 OR ae.destination_account_id = :account_id3)
              AND ae.event_date BETWEEN :from_date AND :to_date
              AND NOT EXISTS (
                  SELECT 1 FROM statement_reconciliations linked
                  JOIN statement_rows linked_row ON linked_row.id = linked.statement_row_id
                  WHERE linked.target_account_event_id = ae.id AND linked_row.account_id = :account_id4
              )
            ORDER BY date_distance ASC, ae.id ASC");
        $eventCandidates->execute([
            ':booking_date' => (string)$row['booking_date'],
            ':account_id' => (int)$row['account_id'],
            ':account_id2' => (int)$row['account_id'],
            ':account_id3' => (int)$row['account_id'],
            ':account_id4' => (int)$row['account_id'],
            ':from_date' => date('Y-m-d', strtotime((string)$row['booking_date'] . ' -' . STATEMENT_RECONCILIATION_DATE_TOLERANCE_DAYS . ' days')),
            ':to_date' => date('Y-m-d', strtotime((string)$row['booking_date'] . ' +' . STATEMENT_RECONCILIATION_DATE_TOLERANCE_DAYS . ' days')),
        ]);
        foreach ($eventCandidates->fetchAll() as $event) {
            $distance = (int)round((float)$event['date_distance']);
            if ($distance > STATEMENT_RECONCILIATION_DATE_TOLERANCE_DAYS) continue;
            $movementAmount = isset($event['movement_amount']) && $event['movement_amount'] !== null ? (float)$event['movement_amount'] : null;
            if ($movementAmount === null) continue;
            $currencyAmount = statement_reconciliation_amount_in_currency($db, $absAmount, (string)$row['currency'], (string)$event['event_date']);
            if ($currencyAmount === null) continue;
            // Signed direction must match the statement's signed direction. The movement on the
            // statement-side Account must carry the same sign as amount.
            if (($amount > 0 && $movementAmount <= 0) || ($amount < 0 && $movementAmount >= 0)) continue;
            if (abs(abs($movementAmount) - $currencyAmount) > STATEMENT_RECONCILIATION_AMOUNT_TOLERANCE) continue;
            $transferCandidates[] = [
                'kind' => 'account_event',
                'account_event_type' => 'transfer',
                'account_event_id' => (int)$event['id'],
                'event_date' => (string)$event['event_date'],
                'amount' => (float)$event['amount'],
                'source_account_id' => (int)$event['source_account_id'],
                'source_account_name' => (string)$event['source_account_name'],
                'destination_account_id' => (int)$event['destination_account_id'],
                'destination_account_name' => (string)$event['destination_account_name'],
                'note' => (string)$event['note'],
                'date_distance_days' => $distance,
            ];
        }

    // Existing Income candidates are eligible only for positive evidence on the receiving Account.
    $incomeCandidates = [];
    if ($amount > 0) {
        $incomeStmt = $db->prepare("SELECT ae.id, ae.event_date, ae.amount, ae.destination_account_id, ae.note,
                dest.name AS destination_account_name,
                ABS(JULIANDAY(ae.event_date) - JULIANDAY(:booking_date)) AS date_distance
            FROM account_events ae
            JOIN accounts dest ON dest.id = ae.destination_account_id
            WHERE ae.event_type = 'income'
              AND ae.destination_account_id = :account_id
              AND ae.event_date BETWEEN :from_date AND :to_date
              AND NOT EXISTS (SELECT 1 FROM statement_reconciliations linked WHERE linked.target_account_event_id = ae.id)
            ORDER BY date_distance ASC, ae.id ASC");
        $incomeStmt->execute([
            ':booking_date' => (string)$row['booking_date'],
            ':account_id' => (int)$row['account_id'],
            ':from_date' => date('Y-m-d', strtotime((string)$row['booking_date'] . ' -' . STATEMENT_RECONCILIATION_DATE_TOLERANCE_DAYS . ' days')),
            ':to_date' => date('Y-m-d', strtotime((string)$row['booking_date'] . ' +' . STATEMENT_RECONCILIATION_DATE_TOLERANCE_DAYS . ' days')),
        ]);
        foreach ($incomeStmt->fetchAll() as $event) {
            $distance = (int)round((float)$event['date_distance']);
            $currencyAmount = statement_reconciliation_amount_in_currency($db, $absAmount, (string)$row['currency'], (string)$event['event_date']);
            if ($currencyAmount === null || abs((float)$event['amount'] - $currencyAmount) > STATEMENT_RECONCILIATION_AMOUNT_TOLERANCE) continue;
            $incomeCandidates[] = [
                'kind' => 'account_event',
                'account_event_type' => 'income',
                'account_event_id' => (int)$event['id'],
                'event_date' => (string)$event['event_date'],
                'amount' => (float)$event['amount'],
                'source_account_id' => null,
                'source_account_name' => '',
                'destination_account_id' => (int)$event['destination_account_id'],
                'destination_account_name' => (string)$event['destination_account_name'],
                'note' => (string)$event['note'],
                'date_distance_days' => $distance,
            ];
        }
    }

    // Merge and order deterministically: date distance, Transactions before Account events, then id.
    $candidates = array_merge($transactionCandidates, $transferCandidates, $incomeCandidates);
    usort($candidates, static function(array $left, array $right): int {
        $distanceCompare = $left['date_distance_days'] <=> $right['date_distance_days'];
        if ($distanceCompare !== 0) return $distanceCompare;
        $kindCompare = ($left['kind'] === 'transaction' ? 0 : 1) <=> ($right['kind'] === 'transaction' ? 0 : 1);
        if ($kindCompare !== 0) return $kindCompare;
        $leftId = $left['kind'] === 'transaction' ? (int)$left['transaction_id'] : (int)$left['account_event_id'];
        $rightId = $right['kind'] === 'transaction' ? (int)$right['transaction_id'] : (int)$right['account_event_id'];
        return $leftId <=> $rightId;
    });

    return ['statement_row' => $row, 'candidates' => $candidates, 'already_reconciled' => false];
}

/** Revalidate a caller-selected Expense/Refund target against the same authoritative rules. */
function statement_reconciliation_assert_transaction_candidate(PDO $db, int $rowId, int $transactionId): void
{
    foreach (find_reconciliation_candidates($db, $rowId)['candidates'] as $candidate) {
        if ($candidate['kind'] === 'transaction' && (int)$candidate['transaction_id'] === $transactionId) return;
    }
    throw new InvalidArgumentException('Transaction is not a compatible reconciliation match');
}

function statement_reconciliation_assert_account_event_candidate(PDO $db, int $rowId, int $accountEventId): void
{
    foreach (find_reconciliation_candidates($db, $rowId)['candidates'] as $candidate) {
        if ($candidate['kind'] === 'account_event' && (int)$candidate['account_event_id'] === $accountEventId) return;
    }
    throw new InvalidArgumentException('Account event is not a compatible reconciliation match');
}

function statement_reconciliation_raw_fields(array $row): array
{
    $decoded = json_decode((string)($row['raw_fields_json'] ?? '{}'), true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Return exact configured counterpart Accounts whose identifiers occur as
 * complete raw field values. The profile-declared statement-owner identifier
 * column is excluded: it is informational evidence, never a counterpart.
 */
function statement_reconciliation_exact_counterpart_accounts(PDO $db, array $row): array
{
    $excludedKeys = [];
    if (($row['import_profile_id'] ?? null) !== null) {
        $profile = statement_import_profile_context($db, (int)$row['import_id']);
        $accountNumber = $profile['definition']['parser']['account_number'] ?? null;
        if (is_array($accountNumber) && ($accountNumber['mode'] ?? '') === 'column_first_nonempty') {
            $excludedKeys[statement_header_key((string)$accountNumber['column'])] = true;
        }
    }
    $values = [];
    $collect = static function(mixed $value) use (&$values, &$collect): void {
        if (is_array($value)) {
            foreach ($value as $nested) $collect($nested);
            return;
        }
        if (is_scalar($value)) {
            $normalized = strtoupper(statement_normalize_account_number((string)$value));
            if ($normalized !== '') $values[$normalized] = true;
        }
    };
    foreach (statement_reconciliation_raw_fields($row) as $key => $rawValue) {
        if (isset($excludedKeys[statement_header_key((string)$key)])) continue;
        $collect($rawValue);
    }
    $matches = [];
    foreach ($db->query('SELECT id,name,account_number FROM accounts ORDER BY id')->fetchAll() as $account) {
        if ((int)$account['id'] === (int)$row['account_id']) continue;
        $number = strtoupper(statement_normalize_account_number((string)$account['account_number']));
        if ($number !== '' && isset($values[$number])) {
            $matches[] = ['id' => (int)$account['id'], 'name' => (string)$account['name'], 'account_number' => (string)$account['account_number']];
        }
    }
    return $matches;
}

/**
 * True when the statement evidence itself names a counterpart Account.
 *
 * A row whose evidence names no counterpart Account cannot say where the money went or
 * came from. The Transfer destination is then the owner's choice alone: neither the
 * automatic path nor the history pre-selection may decide it.
 */
function statement_reconciliation_row_names_counterpart_account(PDO $db, int $rowId): bool
{
    if ($rowId <= 0) return false;
    $row = statement_reconciliation_load_row($db, $rowId);
    return statement_reconciliation_exact_counterpart_accounts($db, $row) !== [];
}

/** Exact prior reconciled description/account/direction history; zero, one, or many Envelope ids. */
function statement_reconciliation_prior_envelope_ids(PDO $db, array $row, string $transactionKind): array
{
    $stmt = $db->prepare("SELECT DISTINCT t.envelope_id
        FROM statement_rows prior
        JOIN statement_reconciliations sr ON sr.statement_row_id = prior.id
        JOIN transactions t ON t.id = sr.transaction_id
        JOIN envelopes e ON e.id = t.envelope_id
        WHERE prior.id <> :row_id
          AND prior.account_id = :account_id
          AND prior.description_normalized = :description
          AND t.kind = :kind
          AND t.envelope_id IS NOT NULL
        ORDER BY t.envelope_id");
    $stmt->execute([
        ':row_id' => (int)$row['id'],
        ':account_id' => (int)$row['account_id'],
        ':description' => (string)$row['description_normalized'],
        ':kind' => $transactionKind,
    ]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * The single unlinked Plan-derived Betalingsservice Transaction in an Envelope that can be
 * the real payment behind a statement row whose amount differs from the planned amount.
 *
 * A Plan-derived Betalingsservice payment is an automatic Transaction that belongs to one
 * allocation row. When the real statement amount differs from the planned one, this is the
 * only Transaction that may adopt the real amount. Returns null when no such Transaction
 * exists or when more than one does: one planned payment is never claimed by a guess.
 */
function statement_reconciliation_automatic_bs_transaction(PDO $db, array $row, int $envelopeId): ?array
{
    $stmt = $db->prepare("SELECT t.id, t.month_id, t.entry_id, t.envelope_id, t.envelope, t.amount, t.note,
            t.spent_date, t.payment_account_id, t.kind
        FROM transactions t
        WHERE t.envelope_id = :envelope_id
          AND t.is_automatic = 1
          AND t.entry_id IS NOT NULL
          AND t.kind = 'expense'
          AND t.spent_date BETWEEN :from_date AND :to_date
          AND NOT EXISTS (SELECT 1 FROM statement_reconciliations linked WHERE linked.transaction_id = t.id)
        ORDER BY t.spent_date ASC, t.id ASC");
    $stmt->execute([
        ':envelope_id' => $envelopeId,
        ':from_date' => date('Y-m-d', strtotime((string)$row['booking_date'] . ' -' . STATEMENT_RECONCILIATION_DATE_TOLERANCE_DAYS . ' days')),
        ':to_date' => date('Y-m-d', strtotime((string)$row['booking_date'] . ' +' . STATEMENT_RECONCILIATION_DATE_TOLERANCE_DAYS . ' days')),
    ]);
    $rows = $stmt->fetchAll();
    if (count($rows) !== 1) return null;
    $transaction = $rows[0];
    return [
        'transaction_id' => (int)$transaction['id'],
        'month_id' => $transaction['month_id'] === null ? null : (int)$transaction['month_id'],
        'entry_id' => $transaction['entry_id'] === null ? null : (int)$transaction['entry_id'],
        'envelope_id' => (int)$transaction['envelope_id'],
        'envelope' => (string)$transaction['envelope'],
        'amount' => (float)$transaction['amount'],
        'note' => (string)$transaction['note'],
        'spent_date' => (string)$transaction['spent_date'],
        'payment_account_id' => $transaction['payment_account_id'] === null ? null : (int)$transaction['payment_account_id'],
    ];
}

/**
 * The unresolved outgoing statement rows that compete for one planned payment.
 *
 * A row competes when its own prior reconciled history maps it to exactly the same single
 * Envelope as the planned Betalingsservice payment. One planned payment must never be spread
 * over several real debits, so those rows are counted and stay manual. The window is the
 * planned payment date, because that is the payment the rows would claim.
 */
function statement_reconciliation_competing_envelope_rows(PDO $db, array $row, int $envelopeId, string $transactionKind, string $anchorDate): int
{
    $accountId = (int)$row['account_id'];
    $rows = $db->prepare("SELECT r.id, r.description_normalized
        FROM statement_rows r
        WHERE r.account_id = :account_id
          AND r.id <> :row_id
          AND r.parse_problem = ''
          AND r.booking_date BETWEEN :from_date AND :to_date
          AND r.amount < 0
          AND NOT EXISTS (SELECT 1 FROM statement_reconciliations linked WHERE linked.statement_row_id = r.id)
        ORDER BY r.id");
    $rows->execute([
        ':account_id' => $accountId,
        ':row_id' => (int)$row['id'],
        ':from_date' => date('Y-m-d', strtotime($anchorDate . ' -' . STATEMENT_RECONCILIATION_DATE_TOLERANCE_DAYS . ' days')),
        ':to_date' => date('Y-m-d', strtotime($anchorDate . ' +' . STATEMENT_RECONCILIATION_DATE_TOLERANCE_DAYS . ' days')),
    ]);
    $mapping = [];
    $competing = 0;
    foreach ($rows->fetchAll() as $competingRow) {
        $description = (string)$competingRow['description_normalized'];
        if (!array_key_exists($description, $mapping)) {
            $mapping[$description] = statement_reconciliation_prior_envelope_ids($db, [
                'id' => (int)$competingRow['id'],
                'account_id' => $accountId,
                'description_normalized' => $description,
            ], $transactionKind);
        }
        if ($mapping[$description] === [$envelopeId]) $competing++;
    }
    return $competing;
}

/**
 * Link a statement row to an existing Transaction and adopt the statement Account.
 *
 * A Transaction without a payment Account takes the statement Account, because the statement
 * row is the proof of which Account paid. The authoritative amount is only rewritten when the
 * caller supplies one; the allocation, Envelope, note and date of the Transaction are kept.
 */
function statement_reconciliation_link_transaction(PDO $db, int $rowId, int $transactionId, ?float $currencyAmount = null): int
{
    $row = statement_reconciliation_load_row($db, $rowId);
    $existing = $db->prepare('SELECT id, month_id, entry_id, envelope_id, envelope, amount, note, spent_date, kind, payment_account_id
        FROM transactions WHERE id = :id');
    $existing->execute([':id' => $transactionId]);
    $transaction = $existing->fetch();
    if (!$transaction) throw new InvalidArgumentException('Transaction not found');
    $linked = $db->prepare('SELECT 1 FROM statement_reconciliations WHERE transaction_id = :tid');
    $linked->execute([':tid' => $transactionId]);
    if ($linked->fetchColumn()) throw new InvalidArgumentException('Transaction is already reconciled');

    $payload = [
        'month_id' => $transaction['month_id'] === null ? null : (int)$transaction['month_id'],
        'entry_id' => $transaction['entry_id'] === null ? null : (int)$transaction['entry_id'],
        'envelope_id' => (int)$transaction['envelope_id'],
        'amount' => $currencyAmount === null ? (float)$transaction['amount'] : round($currencyAmount, 2),
        'note' => (string)$transaction['note'],
        'spent_date' => (string)$transaction['spent_date'],
        'kind' => (string)$transaction['kind'],
    ];
    if ($transaction['payment_account_id'] === null) {
        $account = account_by_id($db, (int)$row['account_id']);
        if ((int)$account['active'] === 1) $payload['payment_account_id'] = (int)$row['account_id'];
    }

    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) $db->beginTransaction();
    try {
        save_transaction($db, $payload, $transactionId, true, true);
        statement_reconciliation_link_insert($db, $rowId, $transactionId, null);
        if ($ownsTransaction) $db->commit();
        return $transactionId;
    } catch (Throwable $error) {
        if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

/**
 * Whether a statement row is eligible for deterministic Income recognition.
 *
 * Only profile-backed imports (any row whose persisted import_profile_id is
 * non-NULL on the originating statement_imports row) can be deterministic
 * Income. The inert canonical `classification` frozen at import time is
 * authoritative; the profile definition was already consulted by the generic
 * parser, so reconciliation never re-routes through profile-name / profile-ID
 * / evidence-namespace branching.
 *
 * A row that is not profile-backed carries no authoritative Income
 * classification and stays an unresolved exception for the owner to classify
 * manually.
 */
function statement_reconciliation_is_deterministic_income(array $row): bool
{
    $profileBacked = array_key_exists('import_profile_id', $row)
        ? $row['import_profile_id'] !== null
        : false;
    if (!$profileBacked) return false;
    return (string)($row['classification'] ?? '') === 'income';
}

/** Pure decision description; it never writes financial or provenance state. */
function statement_reconciliation_assess_row(PDO $db, int $rowId): array
{
    $found = find_reconciliation_candidates($db, $rowId);
    $row = $found['statement_row'];
    $candidates = $found['candidates'];
    if (!empty($found['already_reconciled'])) return ['outcome' => 'already_reconciled', 'row' => $row, 'candidates' => []];
    if ((string)$row['booking_date'] === '' || (string)$row['parse_problem'] !== '' || !is_finite((float)$row['amount']) || (float)$row['amount'] == 0.0) {
        return ['outcome' => 'exception', 'code' => 'invalid_evidence', 'reason' => 'Evidence is incomplete', 'row' => $row, 'candidates' => $candidates];
    }
    if (!statement_reconciliation_supported_currency($db, (string)$row['currency'])) {
        return ['outcome' => 'exception', 'code' => 'unsupported_currency', 'reason' => 'Unsupported evidence currency', 'row' => $row, 'candidates' => $candidates];
    }
    if (count($candidates) === 1) {
        return ['outcome' => 'automatic', 'action' => 'link_existing', 'candidate' => $candidates[0], 'row' => $row, 'candidates' => $candidates];
    }
    if (count($candidates) > 1) {
        return ['outcome' => 'exception', 'code' => 'ambiguous_match', 'reason' => 'Multiple compatible existing records', 'row' => $row, 'candidates' => $candidates];
    }

    $counterparts = statement_reconciliation_exact_counterpart_accounts($db, $row);
    if (count($counterparts) === 1) {
        $currencyAmount = statement_reconciliation_amount_in_currency($db, abs((float)$row['amount']), (string)$row['currency'], (string)$row['booking_date']);
        if ($currencyAmount === null) return ['outcome' => 'exception', 'code' => 'missing_exchange_rate', 'reason' => 'Conversion unavailable', 'row' => $row, 'candidates' => []];
        return ['outcome' => 'automatic', 'action' => 'create_transfer', 'counterpart' => $counterparts[0], 'row' => $row, 'candidates' => []];
    }
    if (count($counterparts) > 1) {
        return ['outcome' => 'exception', 'code' => 'ambiguous_account', 'reason' => 'Multiple exact Account identifiers', 'row' => $row, 'candidates' => []];
    }

    $kind = (float)$row['amount'] < 0 ? 'expense' : 'refund';
    $envelopeIds = statement_reconciliation_prior_envelope_ids($db, $row, $kind);
    if (count($envelopeIds) === 1) {
        $currencyAmount = statement_reconciliation_amount_in_currency($db, abs((float)$row['amount']), (string)$row['currency'], (string)$row['booking_date']);
        if ($currencyAmount === null) return ['outcome' => 'exception', 'code' => 'missing_exchange_rate', 'reason' => 'Conversion unavailable', 'row' => $row, 'candidates' => []];
        if ($kind === 'expense') {
            $automatic = statement_reconciliation_automatic_bs_transaction($db, $row, $envelopeIds[0]);
            if ($automatic !== null) {
                $competing = statement_reconciliation_competing_envelope_rows($db, $row, $envelopeIds[0], $kind, $automatic['spent_date']);
                if ($competing === 0) {
                    return ['outcome' => 'automatic', 'action' => 'link_automatic_bs', 'transaction_id' => $automatic['transaction_id'], 'amount' => $currencyAmount, 'row' => $row, 'candidates' => []];
                }
                return ['outcome' => 'exception', 'code' => 'ambiguous_automatic_payment', 'reason' => 'Several real debits claim one planned payment', 'row' => $row, 'candidates' => []];
            }
        }
        return ['outcome' => 'automatic', 'action' => $kind === 'expense' ? 'create_expense' : 'create_refund', 'envelope_id' => $envelopeIds[0], 'row' => $row, 'candidates' => []];
    }
    if ((float)$row['amount'] > 0 && count($envelopeIds) === 0 && statement_reconciliation_is_deterministic_income($row)) {
        $currencyAmount = statement_reconciliation_amount_in_currency($db, (float)$row['amount'], (string)$row['currency'], (string)$row['booking_date']);
        if ($currencyAmount === null) return ['outcome' => 'exception', 'code' => 'missing_exchange_rate', 'reason' => 'Conversion unavailable', 'row' => $row, 'candidates' => []];
        return ['outcome' => 'automatic', 'action' => 'create_income', 'row' => $row, 'candidates' => []];
    }
    if ((float)$row['amount'] < 0) {
        return [
            'outcome' => 'exception',
            'code' => count($envelopeIds) > 1 ? 'ambiguous_envelope' : 'missing_envelope',
            'reason' => count($envelopeIds) > 1 ? 'Choose the Envelope' : 'Envelope required',
            'batch_action' => 'expense_envelope',
            'row' => $row,
            'candidates' => [],
        ];
    }
    return ['outcome' => 'exception', 'code' => count($envelopeIds) > 1 ? 'ambiguous_envelope' : 'credit_requires_classification', 'reason' => 'Credit requires review', 'row' => $row, 'candidates' => []];
}

/**
 * The existing Transfer that already describes the same movement as one statement row.
 *
 * One real movement can be reported by two statements: the debit on one Account's statement
 * and the credit on the other Account's statement. Both rows describe one payment, so they
 * must produce ONE Transfer. The second row links to the Transfer the first row recorded
 * instead of recording the same payment twice.
 *
 * Eligible means: the same two Accounts in the same direction, the same amount within the
 * existing amount tolerance, and the same date within the existing date tolerance. A
 * Transfer whose side for this row's Account is already reconciled to a statement row is
 * not eligible — that side already describes a statement row of its own, so a second row
 * on that Account is a different real movement.
 *
 * Returns the one eligible Account event id, or null when there is none or more than one.
 * A visible duplicate is preferable to a wrong link.
 */
function statement_reconciliation_twin_transfer_event(PDO $db, array $row, int $sourceAccountId, int $destinationAccountId, float $amount): ?int
{
    $bookingDate = (string)($row['booking_date'] ?? '');
    if ($bookingDate === '') return null;
    $stmt = $db->prepare("SELECT ae.id, ae.amount
        FROM account_events ae
        WHERE ae.event_type = 'transfer'
          AND ae.source_account_id = :source_account_id
          AND ae.destination_account_id = :destination_account_id
          AND ae.event_date BETWEEN :from_date AND :to_date
          AND NOT EXISTS (
              SELECT 1 FROM statement_reconciliations linked
              JOIN statement_rows linked_row ON linked_row.id = linked.statement_row_id
              WHERE linked.target_account_event_id = ae.id AND linked_row.account_id = :account_id
          )
        ORDER BY ae.id");
    $stmt->execute([
        ':source_account_id' => $sourceAccountId,
        ':destination_account_id' => $destinationAccountId,
        ':from_date' => date('Y-m-d', strtotime($bookingDate . ' -' . STATEMENT_RECONCILIATION_DATE_TOLERANCE_DAYS . ' days')),
        ':to_date' => date('Y-m-d', strtotime($bookingDate . ' +' . STATEMENT_RECONCILIATION_DATE_TOLERANCE_DAYS . ' days')),
        ':account_id' => (int)$row['account_id'],
    ]);
    $matches = [];
    foreach ($stmt->fetchAll() as $event) {
        if (abs(abs((float)$event['amount']) - $amount) > STATEMENT_RECONCILIATION_AMOUNT_TOLERANCE) continue;
        $matches[] = (int)$event['id'];
    }
    return count($matches) === 1 ? $matches[0] : null;
}

function statement_reconciliation_create_transfer_from_evidence(PDO $db, int $rowId, int $counterpartAccountId, ?string $note = null): int
{
    $row = statement_reconciliation_load_row($db, $rowId);
    $statementAccountId = (int)$row['account_id'];
    if ($counterpartAccountId <= 0 || $counterpartAccountId === $statementAccountId) throw new InvalidArgumentException('A distinct counterpart Account is required');
    $amount = statement_reconciliation_amount_in_currency($db, abs((float)$row['amount']), (string)$row['currency'], (string)$row['booking_date']);
    if ($amount === null || $amount <= 0) throw new InvalidArgumentException('A usable exchange rate is required for this Transfer date');
    $source = (float)$row['amount'] < 0 ? $statementAccountId : $counterpartAccountId;
    $destination = (float)$row['amount'] < 0 ? $counterpartAccountId : $statementAccountId;

    // One real movement can be reported by two statements. When the other side already
    // recorded that movement as a Transfer, that Transfer is the record of it: link the row
    // to it instead of recording the same payment twice.
    $twinEventId = statement_reconciliation_twin_transfer_event($db, $row, $source, $destination, $amount);
    if ($twinEventId !== null) {
        $ownsTransaction = !$db->inTransaction();
        if ($ownsTransaction) $db->beginTransaction();
        try {
            statement_reconciliation_link_insert($db, $rowId, null, $twinEventId);
            if ($ownsTransaction) $db->commit();
            return $twinEventId;
        } catch (Throwable $error) {
            if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
            throw $error;
        }
    }

    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) $db->beginTransaction();
    try {
        $eventId = record_account_transfer($db, $source, $destination, $amount, (string)$row['booking_date'], trim((string)$note) !== '' ? (string)$note : (string)$row['description_original']);
        statement_reconciliation_link_insert($db, $rowId, null, $eventId);
        if ($ownsTransaction) $db->commit();
        return $eventId;
    } catch (Throwable $error) {
        if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function statement_reconciliation_create_income(PDO $db, int $rowId): int
{
    $row = statement_reconciliation_load_row($db, $rowId);
    if ((float)$row['amount'] <= 0 || !statement_reconciliation_is_deterministic_income($row)) throw new InvalidArgumentException('Statement row is not deterministic Income');
    $amount = statement_reconciliation_amount_in_currency($db, (float)$row['amount'], (string)$row['currency'], (string)$row['booking_date']);
    if ($amount === null || $amount <= 0) throw new InvalidArgumentException('A usable exchange rate is required for this Income date');
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) $db->beginTransaction();
    try {
        $eventId = record_account_event($db, 'income', $amount, (string)$row['booking_date'], null, (int)$row['account_id'], null, (string)$row['description_original']);
        statement_reconciliation_link_insert($db, $rowId, null, $eventId);
        if ($ownsTransaction) $db->commit();
        return $eventId;
    } catch (Throwable $error) {
        if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function statement_reconciliation_auto_process_row(PDO $db, int $rowId): array
{
    $assessment = statement_reconciliation_assess_row($db, $rowId);
    if ($assessment['outcome'] !== 'automatic') return $assessment;
    $row = $assessment['row'];
    try {
        switch ($assessment['action']) {
            case 'link_existing':
                $candidate = $assessment['candidate'];
                $ownsTransaction = !$db->inTransaction();
                if ($ownsTransaction) $db->beginTransaction();
                try {
                    if ($candidate['kind'] === 'transaction') {
                        statement_reconciliation_assert_transaction_candidate($db, $rowId, (int)$candidate['transaction_id']);
                        $targetId = statement_reconciliation_link_transaction($db, $rowId, (int)$candidate['transaction_id']);
                    } else {
                        statement_reconciliation_assert_account_event_candidate($db, $rowId, (int)$candidate['account_event_id']);
                        $targetId = (int)$candidate['account_event_id'];
                        statement_reconciliation_link_insert($db, $rowId, null, $targetId);
                    }
                    if ($ownsTransaction) $db->commit();
                } catch (Throwable $error) {
                    if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
                    throw $error;
                }
                break;
            case 'create_transfer':
                $targetId = statement_reconciliation_create_transfer_from_evidence($db, $rowId, (int)$assessment['counterpart']['id']);
                break;
            case 'create_expense':
                $targetId = statement_reconciliation_create_expense($db, $rowId, ['envelope_id' => (int)$assessment['envelope_id']]);
                break;
            case 'create_refund':
                $targetId = statement_reconciliation_create_refund($db, $rowId, ['envelope_id' => (int)$assessment['envelope_id']]);
                break;
            case 'create_income':
                $targetId = statement_reconciliation_create_income($db, $rowId);
                break;
            case 'link_automatic_bs':
                $targetId = statement_reconciliation_link_transaction($db, $rowId, (int)$assessment['transaction_id'], (float)$assessment['amount']);
                break;
            default:
                throw new RuntimeException('Unknown automatic statement action');
        }
        return ['outcome' => 'processed', 'action' => $assessment['action'], 'statement_row_id' => $rowId, 'target_id' => $targetId];
    } catch (DuplicateTransactionException $error) {
        return ['outcome' => 'exception', 'code' => 'duplicate_transaction', 'reason' => 'An identical transaction already exists', 'duplicate' => $error->duplicate(), 'statement_row_id' => $rowId];
    } catch (Throwable $error) {
        return ['outcome' => 'exception', 'code' => 'processing_error', 'reason' => $error->getMessage(), 'statement_row_id' => $rowId];
    }
}

function statement_reconciliation_auto_process_rows(PDO $db, array $rowIds): array
{
    $results = [];
    foreach (array_values(array_unique(array_map('intval', $rowIds))) as $rowId) {
        if ($rowId > 0) $results[] = statement_reconciliation_auto_process_row($db, $rowId);
    }
    return $results;
}

function statement_reconciliation_auto_process_import(PDO $db, int $importId): array
{
    $stmt = $db->prepare("SELECT id FROM statement_rows WHERE import_id=:import_id AND parse_problem='' AND booking_date IS NOT NULL AND amount IS NOT NULL ORDER BY id");
    $stmt->execute([':import_id' => $importId]);
    return statement_reconciliation_auto_process_rows($db, $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Parse the non-persisted manual-classification contract used by the Reconcile UI.
 * Values are deliberately namespaced so an Envelope id can never be mistaken for
 * an Account id: `envelope:<positive integer>` or `account:<positive integer>`.
 * The exact `ignore` value records the existing No FIN action disposition.
 */
function statement_reconciliation_parse_classification(mixed $selection): array
{
    if (!is_array($selection)) throw new InvalidArgumentException('Reconciliation selection must be an object');
    if (array_key_exists('envelope_id', $selection) || array_key_exists('transfer_account_id', $selection)) {
        throw new InvalidArgumentException('Use exactly one namespaced reconciliation classification');
    }
    $confirmDuplicate = false;
    if (array_key_exists('confirm_duplicate', $selection)) {
        if (!is_bool($selection['confirm_duplicate'])) throw new InvalidArgumentException('Reconciliation duplicate confirmation must be a boolean');
        $confirmDuplicate = $selection['confirm_duplicate'];
    }
    foreach (array_keys($selection) as $key) {
        if (!in_array($key, ['statement_row_id', 'classification', 'confirm_duplicate'], true)) {
            throw new InvalidArgumentException('Unknown reconciliation selection field');
        }
    }
    if (!array_key_exists('statement_row_id', $selection) || !is_int($selection['statement_row_id']) || $selection['statement_row_id'] <= 0) {
        throw new InvalidArgumentException('Statement row id must be a positive integer');
    }
    if (!array_key_exists('classification', $selection) || !is_string($selection['classification'])) {
        throw new InvalidArgumentException('Reconciliation classification must be a string');
    }
    if ($selection['classification'] === 'ignore') {
        return [
            'statement_row_id' => $selection['statement_row_id'],
            'classification' => 'ignore',
            'target_kind' => 'no_fin_action',
            'target_id' => null,
            'confirm_duplicate' => $confirmDuplicate,
        ];
    }
    if (!preg_match('/\A(envelope|account):([1-9][0-9]*)\z/D', $selection['classification'], $matches)) {
        throw new InvalidArgumentException('Unknown or malformed reconciliation classification');
    }
    $targetId = filter_var($matches[2], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($targetId === false) throw new InvalidArgumentException('Reconciliation target id is invalid');
    return [
        'statement_row_id' => $selection['statement_row_id'],
        'classification' => $selection['classification'],
        'target_kind' => $matches[1],
        'target_id' => $targetId,
        'confirm_duplicate' => $confirmDuplicate,
    ];
}

/** Validate that one parsed classification names a currently selectable target. */
function statement_reconciliation_assert_classification_target(PDO $db, int $statementAccountId, array $classification): void
{
    if ($classification['target_kind'] === 'no_fin_action') return;
    if ($classification['target_kind'] === 'envelope') {
        $target = $db->prepare('SELECT 1 FROM envelopes WHERE id=:id');
        $target->execute([':id' => $classification['target_id']]);
        if (!$target->fetchColumn()) throw new InvalidArgumentException('Envelope classification target not found');
        return;
    }
    if ((int)$classification['target_id'] === $statementAccountId) {
        throw new InvalidArgumentException('A distinct counterpart Account is required');
    }
    $target = $db->prepare('SELECT active FROM accounts WHERE id=:id');
    $target->execute([':id' => $classification['target_id']]);
    $active = $target->fetchColumn();
    if ($active === false) throw new InvalidArgumentException('Account classification target not found');
    if ((int)$active !== 1) throw new InvalidArgumentException('Counterpart Account is not active');
}

/** Batch manual statement reconciliation. Rows are independent; one invalid row never rolls back valid rows. */
function statement_reconciliation_batch_expenses(PDO $db, int $accountId, array $selections): array
{
    $resultsByIndex = [];
    $preparedByIndex = [];
    $rowIdCounts = [];
    foreach ($selections as $selection) {
        if (is_array($selection) && isset($selection['statement_row_id']) && is_int($selection['statement_row_id']) && $selection['statement_row_id'] > 0) {
            $rowId = $selection['statement_row_id'];
            $rowIdCounts[$rowId] = ($rowIdCounts[$rowId] ?? 0) + 1;
        }
    }

    // Strictly preflight each selection. Malformed, duplicate, unknown,
    // cross-Account, self-Account, and archived-Account choices have no
    // financial side effects, while valid rows remain independent.
    foreach ($selections as $index => $selection) {
        $rowId = is_array($selection) && is_int($selection['statement_row_id'] ?? null)
            ? $selection['statement_row_id']
            : 0;
        try {
            $classification = statement_reconciliation_parse_classification($selection);
            $rowId = $classification['statement_row_id'];
            if (($rowIdCounts[$rowId] ?? 0) > 1) {
                throw new InvalidArgumentException('A statement row may have only one reconciliation classification');
            }
            $row = statement_reconciliation_load_row($db, $rowId);
            if ((int)$row['account_id'] !== $accountId) throw new InvalidArgumentException('Statement row belongs to another Account');
            statement_reconciliation_assert_classification_target($db, $accountId, $classification);
            $linked = $db->prepare('SELECT 1 FROM statement_reconciliations WHERE statement_row_id=:id');
            $linked->execute([':id' => $rowId]);
            if ($linked->fetchColumn()) {
                $resultsByIndex[$index] = ['statement_row_id' => $rowId, 'status' => 'already_reconciled'];
                continue;
            }
            $preparedByIndex[$index] = ['classification' => $classification, 'row' => $row];
        } catch (Throwable $error) {
            $resultsByIndex[$index] = [
                'statement_row_id' => $rowId,
                'status' => 'error',
                'error' => $error->getMessage(),
            ];
        }
    }

    foreach ($preparedByIndex as $index => $prepared) {
        $classification = $prepared['classification'];
        $row = $prepared['row'];
        $rowId = $classification['statement_row_id'];
        try {
            if ($classification['target_kind'] === 'no_fin_action') {
                $linkId = statement_reconciliation_mark_no_fin_action($db, $rowId);
                $resultsByIndex[$index] = ['statement_row_id' => $rowId, 'status' => 'saved', 'target_kind' => 'no_fin_action', 'reconciliation_id' => $linkId];
                continue;
            }
            if ($classification['target_kind'] === 'envelope') {
                $transactionId = (float)$row['amount'] < 0
                    ? statement_reconciliation_create_expense($db, $rowId, ['envelope_id' => $classification['target_id'], 'confirm_duplicate' => $classification['confirm_duplicate']])
                    : statement_reconciliation_create_refund($db, $rowId, ['envelope_id' => $classification['target_id'], 'confirm_duplicate' => $classification['confirm_duplicate']]);
                $resultsByIndex[$index] = ['statement_row_id' => $rowId, 'status' => 'saved', 'target_kind' => 'transaction', 'transaction_id' => $transactionId];
                continue;
            }
            $eventId = statement_reconciliation_create_transfer_from_evidence($db, $rowId, $classification['target_id']);
            $resultsByIndex[$index] = ['statement_row_id' => $rowId, 'status' => 'saved', 'target_kind' => 'transfer', 'account_event_id' => $eventId];
        } catch (DuplicateTransactionException $error) {
            $resultsByIndex[$index] = [
                'statement_row_id' => $rowId,
                'status' => 'duplicate',
                'duplicate' => $error->duplicate(),
            ];
        } catch (Throwable $error) {
            $resultsByIndex[$index] = [
                'statement_row_id' => $rowId,
                'status' => 'error',
                'error' => $error->getMessage(),
            ];
        }
    }
    ksort($resultsByIndex);
    return array_values($resultsByIndex);
}

function statement_reconciliation_unresolved_count(PDO $db, int $accountId): int
{
    $count = $db->prepare("SELECT COUNT(*) FROM statement_rows r
        WHERE r.account_id = :account_id
          AND r.parse_problem = ''
          AND r.booking_date IS NOT NULL
          AND r.amount IS NOT NULL
          AND NOT EXISTS (SELECT 1 FROM statement_reconciliations sr WHERE sr.statement_row_id = r.id)");
    $count->execute([':account_id' => $accountId]);
    return (int)$count->fetchColumn();
}

function statement_reconciliation_load_for_account(PDO $db, int $accountId): array
{
    // Count unresolved evidence without assessing or preselecting rows, then load
    // and assess only the next bounded batch ordered by date desc.
    $unresolvedCount = statement_reconciliation_unresolved_count($db, $accountId);
    $unresolved = $db->prepare("SELECT r.id, r.booking_date, r.amount, r.currency,
        r.description_original, r.description_normalized, r.parse_problem,
        (SELECT s.original_filename FROM statement_imports s WHERE s.id = r.import_id) AS import_filename,
        (SELECT s.imported_at FROM statement_imports s WHERE s.id = r.import_id) AS imported_at
        FROM statement_rows r
        WHERE r.account_id = :account_id
          AND r.parse_problem = ''
          AND r.booking_date IS NOT NULL
          AND r.amount IS NOT NULL
          AND NOT EXISTS (SELECT 1 FROM statement_reconciliations sr WHERE sr.statement_row_id = r.id)
        ORDER BY r.booking_date DESC, r.id DESC
        LIMIT :batch_size");
    $unresolved->bindValue(':account_id', $accountId, PDO::PARAM_INT);
    $unresolved->bindValue(':batch_size', STATEMENT_RECONCILIATION_BATCH_SIZE, PDO::PARAM_INT);
    $unresolved->execute();
    $unresolvedRows = $unresolved->fetchAll();
    foreach ($unresolvedRows as &$row) {
        $row = statement_reconciliation_repair_persisted_description($db, $row);
        $assessment = statement_reconciliation_assess_row($db, (int)$row['id']);
        $row['candidates'] = $assessment['candidates'] ?? [];
        $row['exception_code'] = $assessment['code'] ?? 'automatic_processing_pending';
        $row['exception_reason'] = $assessment['reason'] ?? 'Automatic processing pending';
        $row['batch_action'] = $assessment['batch_action'] ?? null;
    }
    unset($row);

    // Reconciled rows: one row per reconciliation, with summary fields from each side.
    $reconciled = $db->prepare("SELECT sr.id AS link_id, sr.created_at, sr.statement_row_id, sr.transaction_id, sr.target_account_event_id, sr.disposition,
        r.id AS statement_row_id, r.booking_date, r.amount AS evidence_amount, r.currency,
        r.description_original,
        t.id AS transaction_id, t.spent_date, t.envelope, t.amount AS target_amount, t.kind AS transaction_kind,
        ae.id AS event_id, ae.event_type AS event_type, ae.event_date AS event_date, ae.amount AS event_amount, ae.note AS event_note,
        ae.source_account_id AS event_source_account_id, ae.destination_account_id AS event_destination_account_id,
        src.name AS event_source_account_name, dest.name AS event_destination_account_name
        FROM statement_reconciliations sr
        JOIN statement_rows r ON r.id = sr.statement_row_id
        LEFT JOIN transactions t ON t.id = sr.transaction_id
        LEFT JOIN account_events ae ON ae.id = sr.target_account_event_id
        LEFT JOIN accounts src ON src.id = ae.source_account_id
        LEFT JOIN accounts dest ON dest.id = ae.destination_account_id
        WHERE r.account_id = :account_id
        ORDER BY r.booking_date DESC, r.id DESC");
    $reconciled->execute([':account_id' => $accountId]);
    $rows = $reconciled->fetchAll();
    foreach ($rows as &$row) {
        $row = statement_reconciliation_repair_persisted_description($db, $row);
        if ($row['transaction_id'] !== null) {
            $row['target_kind'] = (string)($row['transaction_kind'] ?? 'expense') === 'refund' ? 'refund' : 'transaction';
            $row['target_id'] = (int)$row['transaction_id'];
        } elseif ($row['event_id'] !== null) {
            $row['target_kind'] = (string)$row['event_type'] === 'income' ? 'income' : 'account_event';
            $row['target_id'] = (int)$row['event_id'];
        } elseif ($row['disposition'] === 'no_fin_action') {
            $row['target_kind'] = 'no_fin_action';
            $row['target_id'] = (int)$row['link_id'];
        } else {
            throw new RuntimeException('Invalid statement reconciliation disposition');
        }
    }
    unset($row);
    return ['unresolved' => $unresolvedRows, 'unresolved_count' => $unresolvedCount, 'reconciled' => $rows];
}

function statement_reconciliation_link_insert(PDO $db, int $rowId, ?int $transactionId, ?int $accountEventId = null): int
{
    if ($transactionId !== null && $accountEventId !== null) {
        throw new InvalidArgumentException('Reconciliation links to either a transaction or an Account event, never both');
    }
    if ($transactionId === null && $accountEventId === null) {
        throw new InvalidArgumentException('A reconciliation link requires a transaction or Account event target');
    }
    $exists = $db->prepare('SELECT 1 FROM statement_rows WHERE id = :id');
    $exists->execute([':id' => $rowId]);
    if (!$exists->fetchColumn()) throw new InvalidArgumentException('Statement row not found');
    if ($transactionId !== null) {
        $targetExists = $db->prepare('SELECT 1 FROM transactions WHERE id = :id');
        $targetExists->execute([':id' => $transactionId]);
        if (!$targetExists->fetchColumn()) throw new InvalidArgumentException('Transaction not found');
    } else {
        $targetExists = $db->prepare('SELECT 1 FROM account_events WHERE id = :id');
        $targetExists->execute([':id' => $accountEventId]);
        if (!$targetExists->fetchColumn()) throw new InvalidArgumentException('Account event not found');
    }
    // One reconciliation per statement row — matches the UNIQUE(statement_row_id) constraint.
    $insert = $db->prepare('INSERT INTO statement_reconciliations (statement_row_id, transaction_id, target_account_event_id) VALUES (:rid, :tid, :eid)');
    $insert->execute([':rid' => $rowId, ':tid' => $transactionId, ':eid' => $accountEventId]);
    return (int)$db->lastInsertId();
}

function statement_reconciliation_remove_link(PDO $db, int $linkId): void
{
    $stmt = $db->prepare('DELETE FROM statement_reconciliations WHERE id = :id');
    $stmt->execute([':id' => $linkId]);
    if ($stmt->rowCount() === 0) throw new InvalidArgumentException('Reconciliation link not found');
}

/**
 * Resolve reviewed statement evidence without creating or changing a FIN financial record.
 */
function statement_reconciliation_mark_no_fin_action(PDO $db, int $rowId): int
{
    $rowExists = $db->prepare('SELECT 1 FROM statement_rows WHERE id = :id');
    $rowExists->execute([':id' => $rowId]);
    if (!$rowExists->fetchColumn()) throw new InvalidArgumentException('Statement row not found');
    $existing = $db->prepare('SELECT 1 FROM statement_reconciliations WHERE statement_row_id = :rid');
    $existing->execute([':rid' => $rowId]);
    if ($existing->fetchColumn()) throw new InvalidArgumentException('Statement row already has a reconciliation disposition');
    $insert = $db->prepare("INSERT INTO statement_reconciliations (statement_row_id, disposition) VALUES (:rid, 'no_fin_action')");
    $insert->execute([':rid' => $rowId]);
    return (int)$db->lastInsertId();
}
