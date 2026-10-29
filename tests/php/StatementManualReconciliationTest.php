<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseTestCase.php';

final class StatementManualReconciliationTest extends DatabaseTestCase
{
    private string $currency;
    private int $sourceRow = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->currency = (string)currency_context($this->db)['currency'];
    }

    public function testAccountLoadReturnsOnlyTheNextBoundedBatchAndKeepsEmptyAccountsEmpty(): void
    {
        $account = $this->account('Batch load Account', 'BATCH-LOAD');
        $emptyAccount = $this->account('Empty Account', 'EMPTY');
        $rowIds = [];
        for ($index = 1; $index <= 40; $index++) {
            $rowIds[] = $this->statementRow($account, '2026-06-01', -$index, 'Batch row ' . $index);
        }

        $state = statement_reconciliation_load_for_account($this->db, $account);
        $loaded = $state['unresolved'];

        self::assertSame(32, STATEMENT_RECONCILIATION_BATCH_SIZE);
        self::assertSame(40, $state['unresolved_count']);
        self::assertCount(STATEMENT_RECONCILIATION_BATCH_SIZE, $loaded);
        self::assertSame(
            array_reverse(array_slice($rowIds, -STATEMENT_RECONCILIATION_BATCH_SIZE)),
            array_map('intval', array_column($loaded, 'id'))
        );
        self::assertSame(40, (int)$this->db->query("SELECT COUNT(*) FROM statement_rows WHERE account_id=$account")->fetchColumn());
        $emptyState = statement_reconciliation_load_for_account($this->db, $emptyAccount);
        self::assertSame(0, $emptyState['unresolved_count']);
        self::assertSame([], $emptyState['unresolved']);
    }

    public function testIgnorePersistsForTheStatementAccountAndRefillsTheBoundedBatch(): void
    {
        $account = $this->account('Ignore Account', 'IGNORE');
        $rowIds = [];
        for ($index = 1; $index <= 33; $index++) {
            $rowIds[] = $this->statementRow($account, '2026-06-01', -$index, 'Ignore batch row ' . $index);
        }
        $loadedState = statement_reconciliation_load_for_account($this->db, $account);
        self::assertSame(33, $loadedState['unresolved_count']);
        $loaded = $loadedState['unresolved'];
        $ignoredRow = (int)$loaded[0]['id'];
        self::assertSame($rowIds[32], $ignoredRow);
        $financialCountsBefore = [
            $this->tableCount('transactions'),
            $this->tableCount('account_events'),
            $this->tableCount('account_movements'),
            $this->tableCount('envelope_movements'),
        ];

        $results = statement_reconciliation_batch_expenses($this->db, $account, [
            ['statement_row_id' => $ignoredRow, 'classification' => 'ignore'],
        ]);

        self::assertCount(1, $results);
        self::assertSame($ignoredRow, $results[0]['statement_row_id']);
        self::assertSame('saved', $results[0]['status']);
        self::assertSame('no_fin_action', $results[0]['target_kind']);
        self::assertGreaterThan(0, $results[0]['reconciliation_id']);
        $trace = $this->db->query("SELECT sr.disposition, sr.transaction_id, sr.target_account_event_id, r.account_id
            FROM statement_reconciliations sr
            JOIN statement_rows r ON r.id=sr.statement_row_id
            WHERE sr.statement_row_id=$ignoredRow")->fetch();
        self::assertSame('no_fin_action', $trace['disposition']);
        self::assertNull($trace['transaction_id']);
        self::assertNull($trace['target_account_event_id']);
        self::assertSame($account, (int)$trace['account_id']);
        self::assertSame($financialCountsBefore, [
            $this->tableCount('transactions'),
            $this->tableCount('account_events'),
            $this->tableCount('account_movements'),
            $this->tableCount('envelope_movements'),
        ]);

        $refilledState = statement_reconciliation_load_for_account($this->db, $account);
        self::assertSame(32, $refilledState['unresolved_count']);
        $refilled = $refilledState['unresolved'];
        self::assertCount(STATEMENT_RECONCILIATION_BATCH_SIZE, $refilled);
        self::assertSame(array_reverse(array_slice($rowIds, 0, 32)), array_map('intval', array_column($refilled, 'id')));
        self::assertNotContains($ignoredRow, array_map('intval', array_column($refilled, 'id')));
    }

    public function testMixedPartialBatchUsesEnvelopeAndTransferPathsForBothDirections(): void
    {
        $statementAccount = $this->account('Statement Account', 'STATEMENT');
        $otherAccount = $this->account('Other Account', 'OTHER');
        $envelope = $this->createEnvelope('Manual purpose');
        $expenseRow = $this->statementRow($statementAccount, '2026-06-01', -11, 'Outgoing transaction');
        $refundRow = $this->statementRow($statementAccount, '2026-06-02', 12, 'Incoming transaction');
        $outgoingTransferRow = $this->statementRow($statementAccount, '2026-06-03', -13, 'Outgoing transfer');
        $incomingTransferRow = $this->statementRow($statementAccount, '2026-06-04', 14, 'Incoming transfer');
        $untouchedRow = $this->statementRow($statementAccount, '2026-06-05', -15, 'Untouched');

        $results = statement_reconciliation_batch_expenses($this->db, $statementAccount, [
            ['statement_row_id' => $expenseRow, 'classification' => 'envelope:' . $envelope],
            ['statement_row_id' => $refundRow, 'classification' => 'envelope:' . $envelope],
            ['statement_row_id' => $outgoingTransferRow, 'classification' => 'account:' . $otherAccount],
            ['statement_row_id' => $incomingTransferRow, 'classification' => 'account:' . $otherAccount],
        ]);

        self::assertSame(['saved', 'saved', 'saved', 'saved'], array_column($results, 'status'));
        self::assertSame(['transaction', 'transaction', 'transfer', 'transfer'], array_column($results, 'target_kind'));

        $expense = $this->transactionForRow($expenseRow);
        self::assertSame('expense', $expense['kind']);
        self::assertSame($envelope, (int)$expense['envelope_id']);
        self::assertSame($statementAccount, (int)$expense['payment_account_id']);

        $refund = $this->transactionForRow($refundRow);
        self::assertSame('refund', $refund['kind']);
        self::assertSame($envelope, (int)$refund['envelope_id']);
        self::assertSame($statementAccount, (int)$refund['payment_account_id']);

        $outgoing = $this->eventForRow($outgoingTransferRow);
        self::assertSame('transfer', $outgoing['event_type']);
        self::assertSame($statementAccount, (int)$outgoing['source_account_id']);
        self::assertSame($otherAccount, (int)$outgoing['destination_account_id']);

        $incoming = $this->eventForRow($incomingTransferRow);
        self::assertSame('transfer', $incoming['event_type']);
        self::assertSame($otherAccount, (int)$incoming['source_account_id']);
        self::assertSame($statementAccount, (int)$incoming['destination_account_id']);

        self::assertSame(2, $this->tableCount('transactions'));
        self::assertSame(4, $this->tableCount('statement_reconciliations'));
        self::assertSame(4, $this->tableCount('account_events'));
        self::assertSame(6, $this->tableCount('account_movements'));
        $unresolved = statement_reconciliation_load_for_account($this->db, $statementAccount)['unresolved'];
        self::assertSame([$untouchedRow], array_map('intval', array_column($unresolved, 'id')));
    }

    public function testInvalidSubmittedRowDoesNotBlockValidSubmittedRow(): void
    {
        $account = $this->account('Batch Account', 'BATCH');
        $other = $this->account('Transfer Account', 'TRANSFER');
        $envelope = $this->createEnvelope('Batch purpose');
        $invalidRow = $this->statementRow($account, '2026-06-06', -20, 'Invalid choice');
        $validRow = $this->statementRow($account, '2026-06-07', -21, 'Valid choice');

        $results = statement_reconciliation_batch_expenses($this->db, $account, [
            ['statement_row_id' => $invalidRow, 'classification' => 'envelope:' . $envelope, 'transfer_account_id' => $other],
            ['statement_row_id' => $validRow, 'classification' => 'envelope:' . $envelope],
        ]);

        self::assertSame(['error', 'saved'], array_column($results, 'status'));
        self::assertStringContainsString('exactly one namespaced reconciliation classification', $results[0]['error']);
        self::assertSame(1, $this->tableCount('transactions'));
        self::assertSame(1, $this->tableCount('statement_reconciliations'));
        self::assertSame([$invalidRow], array_map('intval', array_column(statement_reconciliation_load_for_account($this->db, $account)['unresolved'], 'id')));
    }

    public function testBatchRejectsMissingChoiceAndStatementAccountAsTransferDestination(): void
    {
        $account = $this->account('Source Account', 'SOURCE');
        $missingRow = $this->statementRow($account, '2026-06-08', -22, 'Missing choice');
        $selfTransferRow = $this->statementRow($account, '2026-06-09', -23, 'Self transfer');

        $results = statement_reconciliation_batch_expenses($this->db, $account, [
            ['statement_row_id' => $missingRow],
            ['statement_row_id' => $selfTransferRow, 'classification' => 'account:' . $account],
        ]);

        self::assertSame(['error', 'error'], array_column($results, 'status'));
        self::assertStringContainsString('classification must be a string', $results[0]['error']);
        self::assertStringContainsString('distinct counterpart Account', $results[1]['error']);
        self::assertSame(0, $this->tableCount('transactions'));
        self::assertSame(0, $this->tableCount('account_events'));
        self::assertSame(0, $this->tableCount('statement_reconciliations'));
    }

    public function testClassificationContractRejectsMalformedUnknownInvalidArchivedAndDuplicateChoices(): void
    {
        $account = $this->account('Strict Account', 'STRICT');
        $archived = $this->account('Archived Account', 'ARCHIVED');
        $this->db->exec("UPDATE accounts SET active=0 WHERE id=$archived");
        $envelope = $this->createEnvelope('Strict purpose');
        $rows = [];
        foreach (['Malformed', 'Unknown kind', 'Unknown envelope', 'Unknown account', 'Archived account', 'Unknown field', 'Duplicate', 'Invalid row id'] as $description) {
            $rows[] = $this->statementRow($account, '2026-06-11', -25, $description);
        }

        $results = statement_reconciliation_batch_expenses($this->db, $account, [
            ['statement_row_id' => $rows[0], 'classification' => 'envelope'],
            ['statement_row_id' => $rows[1], 'classification' => 'transaction:1'],
            ['statement_row_id' => $rows[2], 'classification' => 'envelope:999999'],
            ['statement_row_id' => $rows[3], 'classification' => 'account:999999'],
            ['statement_row_id' => $rows[4], 'classification' => 'account:' . $archived],
            ['statement_row_id' => $rows[5], 'classification' => 'envelope:' . $envelope, 'unexpected' => true],
            ['statement_row_id' => $rows[6], 'classification' => 'envelope:' . $envelope],
            ['statement_row_id' => $rows[6], 'classification' => 'account:' . $archived],
            ['statement_row_id' => (string)$rows[7], 'classification' => 'envelope:' . $envelope],
        ]);

        self::assertSame(array_fill(0, 9, 'error'), array_column($results, 'status'));
        self::assertStringContainsString('malformed', strtolower($results[0]['error']));
        self::assertStringContainsString('malformed', strtolower($results[1]['error']));
        self::assertStringContainsString('not found', strtolower($results[2]['error']));
        self::assertStringContainsString('not found', strtolower($results[3]['error']));
        self::assertStringContainsString('not active', strtolower($results[4]['error']));
        self::assertStringContainsString('unknown', strtolower($results[5]['error']));
        self::assertStringContainsString('only one', strtolower($results[6]['error']));
        self::assertStringContainsString('only one', strtolower($results[7]['error']));
        self::assertStringContainsString('positive integer', strtolower($results[8]['error']));
        self::assertSame(0, $this->tableCount('transactions'));
        self::assertSame(0, $this->tableCount('account_events'));
        self::assertSame(0, $this->tableCount('statement_reconciliations'));
    }

    public function testManualTransferRerunIsIdempotent(): void
    {
        $statementAccount = $this->account('Idempotent Account', 'IDEMPOTENT');
        $otherAccount = $this->account('Idempotent Other', 'IDEMPOTENT-OTHER');
        $row = $this->statementRow($statementAccount, '2026-06-10', -24, 'Transfer once');
        $selection = [['statement_row_id' => $row, 'classification' => 'account:' . $otherAccount]];

        $first = statement_reconciliation_batch_expenses($this->db, $statementAccount, $selection);
        $before = [$this->tableCount('account_events'), $this->tableCount('account_movements'), $this->tableCount('statement_reconciliations')];
        $second = statement_reconciliation_batch_expenses($this->db, $statementAccount, $selection);

        self::assertSame('saved', $first[0]['status']);
        self::assertSame('already_reconciled', $second[0]['status']);
        self::assertSame($before, [$this->tableCount('account_events'), $this->tableCount('account_movements'), $this->tableCount('statement_reconciliations')]);
    }

    private function account(string $name, string $number): int
    {
        return save_account_definition($this->db, [
            'name' => $name,
            'account_number' => 'TEST-' . $number . '-' . $this->sourceRow,
            'currency' => 'CURRENCY',
        ]);
    }

    private function statementRow(int $accountId, string $date, float $amount, string $description): int
    {
        $this->sourceRow++;
        $checksum = hash('sha256', $accountId . '|' . $this->sourceRow . '|' . $description);
        $stmt = $this->db->prepare('INSERT INTO statement_imports
            (account_id,provider,original_filename,file_checksum,parser_version,row_count,valid_row_count,problem_count)
            VALUES (:account_id,:provider,:filename,:checksum,:parser_version,1,1,0)');
        $stmt->execute([
            ':account_id' => $accountId,
            ':provider' => 'test_provider',
            ':filename' => 'fixture-' . $this->sourceRow . '.xlsx',
            ':checksum' => $checksum,
            ':parser_version' => STATEMENT_PARSER_VERSION,
        ]);
        $importId = (int)$this->db->lastInsertId();
        $insert = $this->db->prepare('INSERT INTO statement_rows
            (import_id,account_id,provider,source_row_number,booking_date,value_date,amount,currency,description_original,description_normalized,row_fingerprint,occurrence_index,parse_problem,raw_fields_json)
            VALUES (:import_id,:account_id,:provider,:source_row,:date,:date,:amount,:currency,:description,:normalized,:fingerprint,0,\'\',\'{}\')');
        $insert->execute([
            ':import_id' => $importId,
            ':account_id' => $accountId,
            ':provider' => 'test_provider',
            ':source_row' => $this->sourceRow,
            ':date' => $date,
            ':amount' => $amount,
            ':currency' => $this->currency,
            ':description' => $description,
            ':normalized' => statement_normalize_token($description),
            ':fingerprint' => hash('sha256', $checksum . '|row'),
        ]);
        return (int)$this->db->lastInsertId();
    }

    private function transactionForRow(int $rowId): array
    {
        return $this->db->query("SELECT t.* FROM statement_reconciliations sr JOIN transactions t ON t.id=sr.transaction_id WHERE sr.statement_row_id=$rowId")->fetch();
    }

    private function eventForRow(int $rowId): array
    {
        return $this->db->query("SELECT ae.* FROM statement_reconciliations sr JOIN account_events ae ON ae.id=sr.target_account_event_id WHERE sr.statement_row_id=$rowId")->fetch();
    }

    private function tableCount(string $table): int
    {
        return (int)$this->db->query("SELECT COUNT(*) FROM $table")->fetchColumn();
    }
}
