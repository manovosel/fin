<?php

declare(strict_types=1);

const FIN_ACCOUNT_EVENT_TYPES = ['income', 'expense', 'refund', 'transfer'];
const FIN_ACCOUNT_BALANCE_OBSERVATION_SOURCES = ['manual', 'account_creation', 'migration', 'statement'];

function account_assert_amount(float $amount): float
{
    if (!is_finite($amount) || $amount <= 0) throw new InvalidArgumentException('Amount must be greater than zero');
    return round($amount, 2);
}

function account_ref(array $account): array
{
    $id = (int)($account['id'] ?? 0);
    if ($id <= 0) throw new InvalidArgumentException('Account id is required');
    return ['account_id' => $id];
}

function account_movement(int $accountId, float $amount): array
{
    if ($accountId <= 0) throw new InvalidArgumentException('Account id is required');
    if (!is_finite($amount) || abs($amount) < 0.005) throw new InvalidArgumentException('Account movement must be non-zero');
    return ['account_id' => $accountId, 'amount' => round($amount, 2)];
}

/** Pure account-event effects. Classification never alters signed Account movements. */
function account_event_effects(string $eventType, float $amount, ?array $source = null, ?array $destination = null): array
{
    $eventType = strtolower(trim($eventType));
    if (!in_array($eventType, FIN_ACCOUNT_EVENT_TYPES, true)) {
        throw new InvalidArgumentException('Unsupported account event type');
    }
    $sourceRef = $source === null ? null : account_ref($source);
    $destinationRef = $destination === null ? null : account_ref($destination);
    $movements = [];
    $income = 0.0;
    $expense = 0.0;

    $amount = account_assert_amount($amount);
    switch ($eventType) {
        case 'income':
            if ($sourceRef !== null || $destinationRef === null) throw new InvalidArgumentException('Income requires one destination Account');
            $movements[] = account_movement($destinationRef['account_id'], $amount);
            $income = $amount;
            break;

        case 'expense':
            if ($sourceRef === null || $destinationRef !== null) throw new InvalidArgumentException('Expense requires one payment account');
            $movements[] = account_movement($sourceRef['account_id'], -$amount);
            $expense = $amount;
            break;

        case 'refund':
            if ($sourceRef !== null || $destinationRef === null) throw new InvalidArgumentException('Refund requires one receiving account');
            $movements[] = account_movement($destinationRef['account_id'], $amount);
            $expense = -$amount;
            break;

        case 'transfer':
            if ($sourceRef === null || $destinationRef === null) throw new InvalidArgumentException('Transfer requires a source and a destination Account');
            if ($sourceRef['account_id'] === $destinationRef['account_id']) throw new InvalidArgumentException('Choose two different accounts');
            $movements[] = account_movement($sourceRef['account_id'], -$amount);
            $movements[] = account_movement($destinationRef['account_id'], $amount);
            break;
    }

    return [
        'event_type' => $eventType,
        'amount' => $amount,
        'movements' => $movements,
        'income' => $income,
        'actual_expense' => $expense,
        'net_position_change' => round(array_sum(array_column($movements, 'amount')), 2),
    ];
}

function account_position_from_movements(array $movements): float
{
    $position = 0.0;
    foreach ($movements as $movement) {
        $amount = is_array($movement) ? ($movement['amount'] ?? null) : $movement;
        if (!is_numeric($amount) || !is_finite((float)$amount)) throw new InvalidArgumentException('Movement amount must be numeric');
        $position += (float)$amount;
    }
    return round($position, 2);
}

function statement_normalize_token(string $value): string
{
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function statement_file_identity(int $accountId, string $provider, string $fileContents): string
{
    if ($accountId <= 0 || trim($provider) === '') throw new InvalidArgumentException('Account and provider are required');
    return hash('sha256', $accountId . "\n" . statement_normalize_token($provider) . "\n" . hash('sha256', $fileContents));
}

function statement_provider_transaction_identity(int $accountId, string $provider, ?string $externalId): ?string
{
    $externalId = trim((string)$externalId);
    if ($externalId === '') return null;
    if ($accountId <= 0 || trim($provider) === '') throw new InvalidArgumentException('Account and provider are required');
    return hash('sha256', $accountId . "\n" . statement_normalize_token($provider) . "\n" . $externalId);
}

function statement_row_fingerprint(array $row): string
{
    $required = ['account_id', 'provider', 'booking_date', 'amount', 'currency', 'description'];
    foreach ($required as $key) if (!array_key_exists($key, $row)) throw new InvalidArgumentException("Statement row $key is required");
    $accountId = (int)$row['account_id'];
    $amount = (float)$row['amount'];
    if ($accountId <= 0 || !is_finite($amount)) throw new InvalidArgumentException('Statement row account and amount must be valid');
    $identity = [
        'account_id' => $accountId,
        'provider' => statement_normalize_token((string)$row['provider']),
        'booking_date' => trim((string)$row['booking_date']),
        'value_date' => trim((string)($row['value_date'] ?? '')),
        'amount' => number_format($amount, 8, '.', ''),
        'currency' => strtoupper(trim((string)$row['currency'])),
        'description' => statement_normalize_token((string)$row['description']),
        'provider_reference' => trim((string)($row['provider_reference'] ?? '')),
        'occurrence' => max(0, (int)($row['occurrence'] ?? 0)),
    ];
    return hash('sha256', json_encode($identity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function statement_rows_are_duplicates(array $left, array $right): bool
{
    $leftProvider = statement_provider_transaction_identity((int)$left['account_id'], (string)$left['provider'], $left['external_id'] ?? null);
    $rightProvider = statement_provider_transaction_identity((int)$right['account_id'], (string)$right['provider'], $right['external_id'] ?? null);
    if ($leftProvider !== null || $rightProvider !== null) return $leftProvider !== null && hash_equals($leftProvider, (string)$rightProvider);
    return hash_equals(statement_row_fingerprint($left), statement_row_fingerprint($right));
}

function save_account_definition(PDO $db, array $account): int
{
    $id = (int)($account['id'] ?? 0);
    $name = trim((string)($account['name'] ?? ''));
    $accountNumber = trim((string)($account['account_number'] ?? ''));
    $currency = currency_assert_preference((string)($account['currency'] ?? FIN_CURRENCY_PREFERENCE));
    if ($name === '') throw new InvalidArgumentException('Account name is required');
    if ($accountNumber === '') throw new InvalidArgumentException('Account number is required');
    $duplicate = $db->prepare('SELECT id FROM accounts WHERE lower(trim(name))=lower(trim(:name)) AND id<>:id LIMIT 1');
    $duplicate->execute([':name' => $name, ':id' => $id]);
    if ($duplicate->fetchColumn()) throw new InvalidArgumentException('An account with this name already exists');
    $existingNumber = $db->prepare('SELECT id FROM accounts WHERE account_number=:account_number AND id<>:id LIMIT 1');
    $existingNumber->execute([':account_number' => $accountNumber, ':id' => $id]);
    if ($existingNumber->fetchColumn()) throw new InvalidArgumentException('An account with this Account number already exists');
    $params = [
        ':name' => $name,
        ':account_number' => $accountNumber,
        ':currency' => $currency,
        ':active' => !array_key_exists('active', $account) || !empty($account['active']) ? 1 : 0,
    ];
    if ($id > 0) {
        $params[':id'] = $id;
        $db->prepare('UPDATE accounts SET name=:name, account_number=:account_number, currency=:currency, active=:active, updated_at=CURRENT_TIMESTAMP WHERE id=:id')->execute($params);
        return $id;
    }
    $db->prepare('INSERT INTO accounts (name,account_number,currency,active) VALUES (:name,:account_number,:currency,:active)')->execute($params);
    return (int)$db->lastInsertId();
}

function account_by_id(PDO $db, int $id): array
{
    $stmt = $db->prepare('SELECT id,name,account_number,currency,active FROM accounts WHERE id=:id');
    $stmt->execute([':id' => $id]);
    $account = $stmt->fetch();
    if (!$account) throw new InvalidArgumentException('Account not found');
    return $account;
}

/** Normalize an Account number for exact comparison despite harmless whitespace. */
function statement_normalize_account_number(string $value): string
{
    return preg_replace('/\s+/u', '', $value ?? '') ?? '';
}

function create_account_with_initial_position(PDO $db, array $input): int
{
    $balanceProvided = array_key_exists('initial_balance', $input)
        && $input['initial_balance'] !== null
        && trim((string)$input['initial_balance']) !== '';
    $amount = $balanceProvided ? (float)$input['initial_balance'] : null;
    if ($balanceProvided && !is_finite((float)$amount)) throw new InvalidArgumentException('Current Balance must be a number');
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) $db->beginTransaction();
    try {
        $accountId = save_account_definition($db, [
            'name' => $input['name'] ?? '',
            'account_number' => $input['account_number'] ?? '',
            'currency' => $input['currency'] ?? FIN_CURRENCY_PREFERENCE,
            'active' => 1,
        ]);
        if ($balanceProvided) {
            record_account_balance_observation($db, $accountId, (float)$amount, effective_today(), '', 'account_creation');
        }
        if ($ownsTransaction) $db->commit();
        return $accountId;
    } catch (Throwable $error) {
        if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function update_managed_account(PDO $db, int $accountId, array $input): int
{
    $current = account_by_id($db, $accountId);
    return save_account_definition($db, [
        'id' => $accountId,
        'name' => $input['name'] ?? $current['name'],
        'account_number' => $input['account_number'] ?? $current['account_number'] ?? '',
        'currency' => $input['currency'] ?? $current['currency'],
        'active' => (int)$current['active'],
    ]);
}

function set_account_active(PDO $db, int $accountId, bool $active): void
{
    account_by_id($db, $accountId);
    $stmt = $db->prepare('UPDATE accounts SET active=:active,updated_at=CURRENT_TIMESTAMP WHERE id=:id');
    $stmt->execute([':active' => $active ? 1 : 0, ':id' => $accountId]);
}

function account_balance_state_as_of(PDO $db, int $accountId, string $date): array
{
    if (!valid_date($date)) throw new InvalidArgumentException('Balance date must be YYYY-MM-DD');
    account_by_id($db, $accountId);
    $observation = $db->prepare('SELECT id,effective_date,balance FROM account_balance_observations WHERE account_id=:account_id AND effective_date<=:effective_date ORDER BY effective_date DESC LIMIT 1');
    $observation->execute([':account_id' => $accountId, ':effective_date' => $date]);
    $anchor = $observation->fetch();
    if (!$anchor) return ['balance_known' => false, 'balance' => null];
    $movements = $db->prepare('SELECT COALESCE(SUM(amount),0) FROM account_movements WHERE account_id=:account_id AND movement_date>:observation_date AND movement_date<=:as_of');
    $movements->execute([':account_id' => $accountId, ':observation_date' => (string)$anchor['effective_date'], ':as_of' => $date]);
    return [
        'balance_known' => true,
        'balance' => round((float)$anchor['balance'] + (float)$movements->fetchColumn(), 2),
    ];
}

function account_balance_as_of(PDO $db, int $accountId, string $date): ?float
{
    return account_balance_state_as_of($db, $accountId, $date)['balance'];
}

function record_account_balance_observation(PDO $db, int $accountId, float $balance, string $date, string $note = '', string $source = 'manual'): int
{
    if (!valid_date($date)) throw new InvalidArgumentException('Balance date must be YYYY-MM-DD');
    if ($date > effective_today()) throw new InvalidArgumentException('Balance date cannot be in the future');
    if (!is_finite($balance)) throw new InvalidArgumentException('Account Balance must be a number');
    if (!in_array($source, FIN_ACCOUNT_BALANCE_OBSERVATION_SOURCES, true)) throw new InvalidArgumentException('Unknown Account Balance source');
    $account = account_by_id($db, $accountId);
    $storedBalance = abs($balance) < 0.005
        ? 0.0
        : amount_to_currency($db, $balance, (string)$account['currency'], $date);
    $stmt = $db->prepare("INSERT INTO account_balance_observations (account_id,effective_date,balance,source,note)
        VALUES (:account_id,:effective_date,:balance,:source,:note)
        ON CONFLICT(account_id,effective_date) DO UPDATE SET
            balance=excluded.balance,
            source=excluded.source,
            note=excluded.note,
            updated_at=STRFTIME('%Y-%m-%d %H:%M:%f','now')");
    $stmt->execute([
        ':account_id' => $accountId,
        ':effective_date' => $date,
        ':balance' => $storedBalance,
        ':source' => $source,
        ':note' => trim($note),
    ]);
    $lookup = $db->prepare('SELECT id FROM account_balance_observations WHERE account_id=:account_id AND effective_date=:effective_date');
    $lookup->execute([':account_id' => $accountId, ':effective_date' => $date]);
    return (int)$lookup->fetchColumn();
}

function delete_account_balance_observation(PDO $db, int $accountId, int $observationId): void
{
    account_by_id($db, $accountId);
    if ($observationId <= 0) throw new InvalidArgumentException('Balance observation is required');
    $stmt = $db->prepare('DELETE FROM account_balance_observations WHERE id=:id AND account_id=:account_id');
    $stmt->execute([':id' => $observationId, ':account_id' => $accountId]);
    if ($stmt->rowCount() !== 1) throw new InvalidArgumentException('Balance observation not found');
}

function get_accounts(PDO $db, ?string $asOf = null): array
{
    $asOf = $asOf ?: effective_today();
    if (!valid_date($asOf)) throw new InvalidArgumentException('Account balance date must be YYYY-MM-DD');
    $rows = $db->query("SELECT a.id,a.name,a.account_number,a.currency,a.active,
            COUNT(am.id) AS movement_count,MAX(am.movement_date) AS last_movement_date
        FROM accounts a LEFT JOIN account_movements am ON am.account_id=a.id
        GROUP BY a.id ORDER BY a.active DESC,a.name")->fetchAll();
    return array_map(function(array $row) use ($db, $asOf): array {
        $state = account_balance_state_as_of($db, (int)$row['id'], $asOf);
        $display = $state['balance_known']
            ? amount_from_currency($db, (float)$state['balance'], (string)$row['currency'], $asOf)
            : null;
        return [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'account_number' => (string)($row['account_number'] ?? ''),
            'currency' => (string)$row['currency'],
            'active' => (int)$row['active'],
            'balance_known' => (bool)$state['balance_known'],
            'balance' => $state['balance'],
            'display_balance' => $display,
            'display_currency' => currency_code_for_preference($db, (string)$row['currency']),
            'conversion_available' => (bool)$state['balance_known'] && $display !== null,
            'movement_count' => (int)$row['movement_count'],
            'last_movement_date' => $row['last_movement_date'],
        ];
    }, $rows);
}

function get_account_history(PDO $db, int $accountId): array
{
    $account = account_by_id($db, $accountId);
    $movements = $db->prepare("SELECT 'movement' AS history_kind,am.id AS history_id,am.id AS movement_id,NULL AS observation_id,
            am.amount,am.movement_date AS history_date,am.sequence,ae.id AS event_id,ae.event_type,ae.event_date,
            ae.amount AS event_amount,ae.note,ae.transaction_id,NULL AS observation_source,
            source.name AS source_account,destination.name AS destination_account,t.envelope AS transaction_envelope
        FROM account_movements am
        JOIN account_events ae ON ae.id=am.event_id
        LEFT JOIN accounts source ON source.id=ae.source_account_id
        LEFT JOIN accounts destination ON destination.id=ae.destination_account_id
        LEFT JOIN transactions t ON t.id=ae.transaction_id
        WHERE am.account_id=:account_id");
    $movements->execute([':account_id' => $accountId]);
    $observations = $db->prepare("SELECT 'observation' AS history_kind,id AS history_id,NULL AS movement_id,id AS observation_id,
            balance AS amount,effective_date AS history_date,NULL AS sequence,NULL AS event_id,NULL AS event_type,
            effective_date AS event_date,balance AS event_amount,note,NULL AS transaction_id,source AS observation_source,
            NULL AS source_account,NULL AS destination_account,NULL AS transaction_envelope
        FROM account_balance_observations WHERE account_id=:account_id");
    $observations->execute([':account_id' => $accountId]);
    $history = array_merge($movements->fetchAll(), $observations->fetchAll());
    usort($history, static function(array $left, array $right): int {
        $date = strcmp((string)$right['history_date'], (string)$left['history_date']);
        if ($date !== 0) return $date;
        $kind = ($right['history_kind'] === 'observation' ? 1 : 0) <=> ($left['history_kind'] === 'observation' ? 1 : 0);
        return $kind !== 0 ? $kind : ((int)$right['history_id'] <=> (int)$left['history_id']);
    });
    return [
        'account' => $account,
        'history' => array_map(function(array $row) use ($db, $account): array {
            $display = amount_from_currency($db, (float)$row['amount'], (string)$account['currency'], (string)$row['history_date']);
            return [
                'history_kind' => (string)$row['history_kind'],
                'movement_id' => $row['movement_id'] === null ? null : (int)$row['movement_id'],
                'observation_id' => $row['observation_id'] === null ? null : (int)$row['observation_id'],
                'event_id' => $row['event_id'] === null ? null : (int)$row['event_id'],
                'event_type' => $row['event_type'] === null ? null : (string)$row['event_type'],
                'event_date' => (string)$row['event_date'],
                'movement_date' => (string)$row['history_date'],
                'amount' => (float)$row['amount'],
                'event_amount' => (float)$row['event_amount'],
                'note' => (string)$row['note'],
                'observation_source' => $row['observation_source'] === null ? null : (string)$row['observation_source'],
                'transaction_id' => $row['transaction_id'] === null ? null : (int)$row['transaction_id'],
                'transaction_envelope' => (string)($row['transaction_envelope'] ?? ''),
                'source_account' => (string)($row['source_account'] ?? ''),
                'destination_account' => (string)($row['destination_account'] ?? ''),
                'display_amount' => $display,
                'display_currency' => currency_code_for_preference($db, (string)$account['currency']),
                'conversion_available' => $display !== null,
            ];
        }, $history),
    ];
}

function record_account_event(PDO $db, string $eventType, float $amount, string $date, ?int $sourceAccountId = null, ?int $destinationAccountId = null, ?int $transactionId = null, string $note = ''): int
{
    if (!valid_date($date)) throw new InvalidArgumentException('Event date must be YYYY-MM-DD');
    $source = $sourceAccountId === null ? null : account_by_id($db, $sourceAccountId);
    $destination = $destinationAccountId === null ? null : account_by_id($db, $destinationAccountId);
    $effect = account_event_effects($eventType, $amount, $source, $destination);
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) $db->beginTransaction();
    try {
        $stmt = $db->prepare('INSERT INTO account_events (event_type,event_date,amount,source_account_id,destination_account_id,transaction_id,note) VALUES (:event_type,:event_date,:amount,:source_account_id,:destination_account_id,:transaction_id,:note)');
        $stmt->execute([
            ':event_type' => $effect['event_type'], ':event_date' => $date, ':amount' => $effect['amount'],
            ':source_account_id' => $sourceAccountId, ':destination_account_id' => $destinationAccountId,
            ':transaction_id' => $transactionId, ':note' => trim($note),
        ]);
        $eventId = (int)$db->lastInsertId();
        $insert = $db->prepare('INSERT INTO account_movements (event_id,account_id,sequence,amount,movement_date) VALUES (:event_id,:account_id,:sequence,:amount,:movement_date)');
        foreach ($effect['movements'] as $index => $movement) {
            $insert->execute([':event_id' => $eventId, ':account_id' => $movement['account_id'], ':sequence' => $index + 1, ':amount' => $movement['amount'], ':movement_date' => $date]);
        }
        if ($ownsTransaction) $db->commit();
        return $eventId;
    } catch (Throwable $error) {
        if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function sync_transaction_account_event(PDO $db, int $transactionId): void
{
    $stmt = $db->prepare('SELECT id,kind,envelope,amount,note,spent_date,payment_account_id FROM transactions WHERE id=:id');
    $stmt->execute([':id' => $transactionId]);
    $transaction = $stmt->fetch();
    $eventStmt = $db->prepare('SELECT * FROM account_events WHERE transaction_id=:transaction_id');
    $eventStmt->execute([':transaction_id' => $transactionId]);
    $event = $eventStmt->fetch() ?: null;
    if (!$transaction || (int)($transaction['payment_account_id'] ?? 0) <= 0) {
        if ($event) $db->prepare('DELETE FROM account_events WHERE id=:id')->execute([':id' => (int)$event['id']]);
        return;
    }

    $kind = (string)($transaction['kind'] ?? '') === 'refund' ? 'refund' : 'expense';
    $accountId = (int)$transaction['payment_account_id'];
    $account = account_by_id($db, $accountId);
    $effect = $kind === 'refund'
        ? account_event_effects('refund', (float)$transaction['amount'], null, $account)
        : account_event_effects('expense', (float)$transaction['amount'], $account, null);
    $note = trim((string)$transaction['note']) !== '' ? (string)$transaction['note'] : ucfirst($kind) . ': ' . (string)$transaction['envelope'];
    $sourceAccountId = $kind === 'expense' ? $accountId : null;
    $destinationAccountId = $kind === 'refund' ? $accountId : null;
    $eventValues = [
        'event_type' => $kind, 'event_date' => (string)$transaction['spent_date'], 'amount' => (float)$effect['amount'],
        'source_account_id' => $sourceAccountId, 'destination_account_id' => $destinationAccountId, 'note' => $note,
    ];
    if (!$event) {
        $insert = $db->prepare('INSERT INTO account_events (event_type,event_date,amount,source_account_id,destination_account_id,transaction_id,note) VALUES (:event_type,:event_date,:amount,:source_account_id,:destination_account_id,:transaction_id,:note)');
        $insert->execute([':event_type' => $kind, ':event_date' => $eventValues['event_date'], ':amount' => $eventValues['amount'], ':source_account_id' => $sourceAccountId, ':destination_account_id' => $destinationAccountId, ':transaction_id' => $transactionId, ':note' => $note]);
        $eventId = (int)$db->lastInsertId();
    } else {
        $eventId = (int)$event['id'];
        $changed = (string)$event['event_type'] !== $kind
            || (string)$event['event_date'] !== $eventValues['event_date']
            || abs((float)$event['amount'] - $eventValues['amount']) > 0.004
            || (int)($event['source_account_id'] ?? -1) !== (int)($sourceAccountId ?? -1)
            || (int)($event['destination_account_id'] ?? -1) !== (int)($destinationAccountId ?? -1)
            || (string)$event['note'] !== $note;
        if ($changed) {
            $update = $db->prepare('UPDATE account_events SET event_type=:event_type,event_date=:event_date,amount=:amount,source_account_id=:source_account_id,destination_account_id=:destination_account_id,note=:note,updated_at=CURRENT_TIMESTAMP WHERE id=:id');
            $update->execute([':event_type' => $kind, ':event_date' => $eventValues['event_date'], ':amount' => $eventValues['amount'], ':source_account_id' => $sourceAccountId, ':destination_account_id' => $destinationAccountId, ':note' => $note, ':id' => $eventId]);
        }
    }

    $movement = $effect['movements'][0];
    $movementAccountId = (int)($movement['account_id'] ?? 0);
    if ($movementAccountId <= 0) {
        throw new RuntimeException('Refund accounting effect missing account');
    }
    $movementStmt = $db->prepare('SELECT * FROM account_movements WHERE event_id=:event_id AND sequence=1');
    $movementStmt->execute([':event_id' => $eventId]);
    $existingMovement = $movementStmt->fetch() ?: null;
    if (!$existingMovement) {
        $db->prepare('INSERT INTO account_movements (event_id,account_id,sequence,amount,movement_date) VALUES (:event_id,:account_id,1,:amount,:movement_date)')->execute([
            ':event_id' => $eventId, ':account_id' => $movementAccountId, ':amount' => $movement['amount'], ':movement_date' => $eventValues['event_date'],
        ]);
    } elseif ((int)$existingMovement['account_id'] !== $movementAccountId
        || abs((float)$existingMovement['amount'] - (float)$movement['amount']) > 0.004
        || (string)$existingMovement['movement_date'] !== $eventValues['event_date']) {
        $db->prepare('UPDATE account_movements SET account_id=:account_id,amount=:amount,movement_date=:movement_date WHERE id=:id')->execute([
            ':account_id' => $movementAccountId, ':amount' => $movement['amount'], ':movement_date' => $eventValues['event_date'], ':id' => (int)$existingMovement['id'],
        ]);
    }
    $db->prepare('DELETE FROM account_movements WHERE event_id=:event_id AND sequence<>1')->execute([':event_id' => $eventId]);
}

/**
 * Ordinary Account-to-Account Transfer creation.
 *
 * Writes event_type='transfer' directly. The amount is stored as the authoritative
 * Currency value. Movements are signed ±amount, both dated on `event_date`,
 * both owned by the same event_id, and sum to zero.
 *
 * Source and destination are ordinary Accounts; only source != destination is enforced.
 */
function record_account_transfer(PDO $db, int $sourceAccountId, int $destinationAccountId, float $amount, string $date, string $note = ''): int
{
    if ($sourceAccountId <= 0 || $destinationAccountId <= 0) throw new InvalidArgumentException('Source and destination Account are required');
    if ($sourceAccountId === $destinationAccountId) throw new InvalidArgumentException('Choose two different accounts');
    $amount = account_assert_amount($amount);
    if (!valid_date($date)) throw new InvalidArgumentException('Event date must be YYYY-MM-DD');
    account_by_id($db, $sourceAccountId);
    account_by_id($db, $destinationAccountId);
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) $db->beginTransaction();
    try {
        $insertEvent = $db->prepare("INSERT INTO account_events (event_type,event_date,amount,source_account_id,destination_account_id,note) VALUES ('transfer',:event_date,:amount,:source_account_id,:destination_account_id,:note)");
        $insertEvent->execute([
            ':event_date' => $date, ':amount' => $amount,
            ':source_account_id' => $sourceAccountId, ':destination_account_id' => $destinationAccountId,
            ':note' => trim($note) !== '' ? trim($note) : 'Account transfer',
        ]);
        $eventId = (int)$db->lastInsertId();
        $insertMovement = $db->prepare('INSERT INTO account_movements (event_id,account_id,sequence,amount,movement_date) VALUES (:event_id,:account_id,:sequence,:amount,:movement_date)');
        $insertMovement->execute([':event_id' => $eventId, ':account_id' => $sourceAccountId, ':sequence' => 1, ':amount' => round(-$amount, 2), ':movement_date' => $date]);
        $insertMovement->execute([':event_id' => $eventId, ':account_id' => $destinationAccountId, ':sequence' => 2, ':amount' => round($amount, 2), ':movement_date' => $date]);
        if ($ownsTransaction) $db->commit();
        return $eventId;
    } catch (Throwable $error) {
        if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
        throw $error;
    }
}
