<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseTestCase.php';

final class TransfersApiTest extends DatabaseTestCase
{
    private string|false $previousToday;

    protected function setUp(): void
    {
        $this->previousToday = getenv('FIN_TODAY');
        putenv('FIN_TODAY=2026-08-14');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        if ($this->previousToday === false) putenv('FIN_TODAY');
        else putenv('FIN_TODAY=' . $this->previousToday);
        parent::tearDown();
    }

    public function testTransferRangeUsesTheSameLimitsAsTheTransactionRange(): void
    {
        try {
            build_transfers_response($this->db, '2026-07', '2026-10');
            self::fail('A four-month transfer range must be rejected');
        } catch (InvalidArgumentException $error) {
            self::assertSame('Transfer range cannot exceed 3 months', $error->getMessage());
        }

        try {
            build_transfers_response($this->db, '2026-09', '2026-07');
            self::fail('A reversed transfer range must be rejected');
        } catch (InvalidArgumentException $error) {
            self::assertSame('From month must be before to month', $error->getMessage());
        }

        $valid = build_transfers_response($this->db, '2026-07', '2026-09');
        self::assertSame('2026-07', $valid['from']);
        self::assertSame('2026-09', $valid['to']);
        self::assertSame([], $valid['transfers']);
    }

    public function testEnvelopeTransferIsTheMoveTheMonthReportShows(): void
    {
        $this->createMonth(2026, 8);
        $accountA = $this->createAccount('Account A', 'ACCOUNT-A-1');
        $accountB = $this->createAccount('Account B', 'ACCOUNT-B-2');
        $sourceId = $this->createEnvelopeWithAccount('Envelope A', 'daily', 'CURRENCY', $accountA);
        $destinationId = $this->createEnvelopeWithAccount('Envelope B', 'daily', 'CURRENCY', $accountB);
        record_envelope_balance_observation($this->db, $sourceId, 1000, '2026-08-01');
        record_envelope_balance_observation($this->db, $destinationId, 100, '2026-08-01');

        $transferId = transfer_envelope_balance($this->db, [
            'source_envelope_id' => $sourceId,
            'destination_envelope_id' => $destinationId,
            'amount' => 250,
            'transfer_date' => '2026-08-05',
            'note' => 'Move money to Envelope B',
            'cross_account_confirmed' => true,
        ]);

        $response = build_transfers_response($this->db, '2026-08', '2026-08');
        self::assertCount(1, $response['transfers']);
        $row = $response['transfers'][0];
        self::assertSame(
            ['kind', 'id', 'date', 'from_envelope', 'to_envelope', 'amount', 'note', 'undone', 'payment_id'],
            array_keys($row)
        );
        self::assertSame('envelope', $row['kind']);
        self::assertSame($transferId, $row['id']);
        self::assertSame('2026-08-05', $row['date']);
        self::assertSame('Envelope A', $row['from_envelope']);
        self::assertSame('Envelope B', $row['to_envelope']);
        self::assertEqualsWithDelta(250.0, $row['amount'], 0.001);
        self::assertSame('Move money to Envelope B', $row['note']);
        self::assertFalse($row['undone']);
        self::assertSame('envelope:' . $transferId, $row['payment_id']);

        // The same move, as the monthly report shows it.
        $report = get_report_data($this->db, '2026-08', '2026-08');
        $model = build_pdf_report_model($this->db, $report);
        $moves = array_values(array_filter($model['months'][0]['transfers_adjustments'], fn(array $event): bool => $event['type'] === 'Move money'));
        self::assertCount(1, $moves);
        self::assertSame($row['date'], $moves[0]['date']);
        self::assertSame('Envelope A → Envelope B', $moves[0]['description']);
        self::assertEqualsWithDelta($row['amount'], $moves[0]['amount'], 0.001);
        self::assertSame($row['note'], $moves[0]['note']);

        // The same move, as the Envelope Balance history shows it.
        $state = get_state($this->db, 2026);
        $history = array_values(array_filter($state['movements'], fn(array $movement): bool => $movement['transfer_id'] === $transferId));
        self::assertCount(2, $history);
        $outgoing = array_values(array_filter($history, fn(array $movement): bool => $movement['type'] === 'transfer_out'))[0];
        self::assertSame($row['date'], $outgoing['movement_date']);
        self::assertSame($row['from_envelope'], $outgoing['source_envelope']);
        self::assertSame($row['to_envelope'], $outgoing['destination_envelope']);
        self::assertEqualsWithDelta($row['amount'], abs((float)$outgoing['amount']), 0.001);
        self::assertSame($row['note'], $outgoing['note']);

        // An undone transfer is still reported, but it says so.
        undo_envelope_transfer($this->db, $transferId);
        $undone = build_transfers_response($this->db, '2026-08', '2026-08');
        self::assertCount(1, $undone['transfers']);
        self::assertTrue($undone['transfers'][0]['undone']);
        self::assertEqualsWithDelta(250.0, $undone['transfers'][0]['amount'], 0.001);
    }

    public function testAccountTransferIsTheMoveTheAccountsScreenShows(): void
    {
        $this->createMonth(2026, 8);
        $accountA = $this->createAccount('Account A', 'ACCOUNT-A-1');
        $accountB = $this->createAccount('Account B', 'ACCOUNT-B-2');
        $eventId = record_account_transfer($this->db, $accountA, $accountB, 250, '2026-08-06', 'Account transfer');

        $response = build_transfers_response($this->db, '2026-08', '2026-08');
        self::assertCount(1, $response['transfers']);
        $row = $response['transfers'][0];
        self::assertSame(
            ['kind', 'id', 'date', 'from_account', 'to_account', 'amount', 'currency', 'note', 'linked_to_statement_row', 'payment_id'],
            array_keys($row)
        );
        self::assertSame('account', $row['kind']);
        self::assertSame($eventId, $row['id']);
        self::assertSame('2026-08-06', $row['date']);
        self::assertSame('Account A', $row['from_account']);
        self::assertSame('Account B', $row['to_account']);
        self::assertEqualsWithDelta(250.0, $row['amount'], 0.001);
        self::assertSame('DKK', $row['currency']);
        self::assertSame('Account transfer', $row['note']);
        self::assertFalse($row['linked_to_statement_row']);
        self::assertSame('account:' . $eventId, $row['payment_id']);

        // The same transfer, as the Accounts screen shows it.
        $history = get_account_history($this->db, $accountA);
        $leg = array_values(array_filter($history['history'], fn(array $item): bool => $item['event_type'] === 'transfer'))[0];
        self::assertSame($row['date'], $leg['movement_date']);
        self::assertSame($row['from_account'], $leg['source_account']);
        self::assertSame($row['to_account'], $leg['destination_account']);
        self::assertEqualsWithDelta($row['amount'], abs((float)$leg['amount']), 0.001);
        self::assertSame($row['note'], $leg['note']);

        // A transfer that is linked to an imported statement row says so.
        $this->linkStatementRowToAccountEvent($accountA, $eventId, '2026-08-06');
        $linked = build_transfers_response($this->db, '2026-08', '2026-08');
        self::assertTrue($linked['transfers'][0]['linked_to_statement_row']);
    }

    public function testOnePaymentRecordedAsBothKindsSharesOnePaymentId(): void
    {
        $this->createMonth(2026, 8);
        $accountA = $this->createAccount('Account A', 'ACCOUNT-A-1');
        $accountB = $this->createAccount('Account B', 'ACCOUNT-B-2');
        $sourceId = $this->createEnvelopeWithAccount('Envelope A', 'daily', 'CURRENCY', $accountA);
        $destinationId = $this->createEnvelopeWithAccount('Envelope B', 'daily', 'CURRENCY', $accountB);
        record_envelope_balance_observation($this->db, $sourceId, 1000, '2026-08-01');
        record_envelope_balance_observation($this->db, $destinationId, 100, '2026-08-01');

        // The same real bank payment, recorded as an Envelope Transfer and as
        // an Account Transfer.
        $transferId = transfer_envelope_balance($this->db, [
            'source_envelope_id' => $sourceId,
            'destination_envelope_id' => $destinationId,
            'amount' => 300,
            'transfer_date' => '2026-08-10',
            'note' => 'Move money to Envelope B',
            'cross_account_confirmed' => true,
        ]);
        $eventId = record_account_transfer($this->db, $accountA, $accountB, 300, '2026-08-10', 'Account transfer');

        // A different real payment, between the same Accounts on another day.
        $otherEventId = record_account_transfer($this->db, $accountA, $accountB, 75, '2026-08-12', 'Other transfer');
        // A move inside one Account, and a real Account payment of the same
        // amount on the same day. The move is not the Account side of it.
        $sameAmountEventId = record_account_transfer($this->db, $accountA, $accountB, 50, '2026-08-12', 'Same amount transfer');

        $response = build_transfers_response($this->db, '2026-08', '2026-08');
        self::assertCount(4, $response['transfers']);
        $byKind = [];
        foreach ($response['transfers'] as $row) $byKind[$row['kind'] . ':' . $row['id']] = $row;

        $envelope = $byKind['envelope:' . $transferId];
        $account = $byKind['account:' . $eventId];
        $other = $byKind['account:' . $otherEventId];

        // The two rows of one real payment share one id, so a client counts
        // the payment once instead of adding it up twice.
        self::assertSame($envelope['payment_id'], $account['payment_id']);
        self::assertSame('account:' . $eventId . '+envelope:' . $transferId, $envelope['payment_id']);
        self::assertNotSame($other['payment_id'], $envelope['payment_id']);
        self::assertSame('account:' . $otherEventId, $other['payment_id']);

        $groups = [];
        foreach ($response['transfers'] as $row) $groups[$row['payment_id']] = ($groups[$row['payment_id']] ?? 0) + 1;
        self::assertSame(3, count($groups), 'One real payment is one group, plus the two other transfers');
        self::assertSame(1, $groups['account:' . $otherEventId]);

        // A move inside one Account is not the Account side of a real payment.
        $internalId = $this->createEnvelopeWithAccount('Envelope C', 'daily', 'CURRENCY', $accountA);
        record_envelope_balance_observation($this->db, $internalId, 100, '2026-08-01');
        $internalTransferId = transfer_envelope_balance($this->db, [
            'source_envelope_id' => $sourceId,
            'destination_envelope_id' => $internalId,
            'amount' => 50,
            'transfer_date' => '2026-08-12',
            'note' => 'Internal move',
        ]);
        $internal = build_transfers_response($this->db, '2026-08', '2026-08');
        $internalRow = array_values(array_filter($internal['transfers'], fn(array $row): bool => $row['kind'] === 'envelope' && $row['id'] === $internalTransferId))[0];
        self::assertSame('envelope:' . $internalTransferId, $internalRow['payment_id']);
        $sameAmountRow = array_values(array_filter($internal['transfers'], fn(array $row): bool => $row['kind'] === 'account' && $row['id'] === $sameAmountEventId))[0];
        self::assertSame('account:' . $sameAmountEventId, $sameAmountRow['payment_id']);
    }

    public function testOnlyTransfersAreReported(): void
    {
        $this->createMonth(2026, 8);
        $monthId = (int)$this->db->query("SELECT id FROM months WHERE year=2026 AND month=8")->fetchColumn();
        $accountA = $this->createAccount('Account A', 'ACCOUNT-A-1');
        $accountB = $this->createAccount('Account B', 'ACCOUNT-B-2');
        $envelopeId = $this->createEnvelope('Envelope A', 'daily', 'CURRENCY');
        record_envelope_balance_observation($this->db, $envelopeId, 1000, '2026-08-01');

        // An Expense and a Refund are Transactions, not Transfers.
        save_transaction($this->db, [
            'month_id' => null,
            'envelope_id' => $envelopeId,
            'envelope' => 'Envelope A',
            'payment_account_id' => $accountA,
            'amount' => 100,
            'spent_date' => '2026-08-05',
            'note' => 'Weekly shopping',
            'kind' => 'expense',
        ]);
        save_transaction($this->db, [
            'month_id' => null,
            'envelope_id' => $envelopeId,
            'envelope' => 'Envelope A',
            'payment_account_id' => $accountA,
            'amount' => 25,
            'spent_date' => '2026-08-06',
            'note' => 'Returned shopping',
            'kind' => 'refund',
        ]);
        // An Income event is Income, not a Transfer.
        record_account_event($this->db, 'income', 5000, '2026-08-07', null, $accountA, null, 'August salary');
        // A Funding movement is Funding, not a Transfer.
        save_entry($this->db, ['month_id' => $monthId, 'envelope_id' => $envelopeId, 'type' => 'expense', 'amount' => 500, 'status' => 'paid']);
        // An Account Balance observation is not a Transfer.
        record_account_balance_observation($this->db, $accountA, 2000, '2026-08-01');
        // A future-dated transfer is not yet actual.
        $futureTransferId = transfer_envelope_balance($this->db, [
            'source_envelope_id' => $envelopeId,
            'destination_envelope_id' => $this->createEnvelopeWithAccount('Envelope B', 'daily', 'CURRENCY', $accountB),
            'amount' => 40,
            'transfer_date' => '2026-08-20',
            'note' => 'Future move',
            'negative_source_confirmed' => true,
            'cross_account_confirmed' => true,
        ]);

        $response = build_transfers_response($this->db, '2026-08', '2026-08');
        self::assertSame([], $response['transfers']);
        self::assertNotContains($futureTransferId, array_column($response['transfers'], 'id'));

        // A real transfer in the same month is reported.
        $transferId = transfer_envelope_balance($this->db, [
            'source_envelope_id' => $envelopeId,
            'destination_envelope_id' => $this->createEnvelopeWithAccount('Envelope C', 'daily', 'CURRENCY', $accountB),
            'amount' => 60,
            'transfer_date' => '2026-08-08',
            'note' => 'Real move',
            'negative_source_confirmed' => true,
            'cross_account_confirmed' => true,
        ]);
        $reported = build_transfers_response($this->db, '2026-08', '2026-08');
        self::assertCount(1, $reported['transfers']);
        self::assertSame($transferId, $reported['transfers'][0]['id']);
    }

    public function testTheActionChangesNoDataAndNeverReportsAccountNumbers(): void
    {
        $this->createMonth(2026, 8);
        $accountA = $this->createAccount('Account A', 'ACCOUNT-A-1');
        $accountB = $this->createAccount('Account B', 'ACCOUNT-B-2');
        record_account_transfer($this->db, $accountA, $accountB, 250, '2026-08-06', 'Account transfer');

        $before = $this->databaseDigest();
        $response = build_transfers_response($this->db, '2026-08', '2026-08');
        self::assertSame($before, $this->databaseDigest(), 'The Transfers action must change no data');

        $body = json_encode($response);
        self::assertStringNotContainsString('ACCOUNT-A-1', (string)$body);
        self::assertStringNotContainsString('ACCOUNT-B-2', (string)$body);
        self::assertStringNotContainsString('account_number', (string)$body);
    }

    private function createAccount(string $name, string $number): int
    {
        return save_account_definition($this->db, [
            'name' => $name,
            'account_number' => $number,
            'currency' => 'CURRENCY',
        ]);
    }

    private function createEnvelopeWithAccount(string $name, string $role, string $currency, ?int $defaultAccountId): int
    {
        return save_envelope($this->db, [
            'name' => $name,
            'role' => $role,
            'currency' => $currency,
            'default_account_id' => $defaultAccountId,
        ]);
    }

    /**
     * Links one imported statement row to one Account event, the way a
     * reconciliation of that row does.
     */
    private function linkStatementRowToAccountEvent(int $accountId, int $eventId, string $bookingDate): void
    {
        $this->db->exec("INSERT INTO statement_imports (account_id, provider, original_filename, file_checksum, parser_version, row_count, valid_row_count, problem_count)
            VALUES ($accountId, 'test-bank', 'statement.xlsx', 'checksum-1', '1', 1, 1, 0)");
        $importId = (int)$this->db->lastInsertId();
        $this->db->exec("INSERT INTO statement_rows (import_id, account_id, provider, source_row_number, booking_date, amount, currency, description_original, description_normalized, row_fingerprint, occurrence_index, parse_problem, raw_fields_json)
            VALUES ($importId, $accountId, 'test-bank', 1, '$bookingDate', -250, 'DKK', 'Transfer to savings', 'transfer to savings', 'fingerprint-1', 0, '', '{}')");
        $rowId = (int)$this->db->lastInsertId();
        $this->db->exec("INSERT INTO statement_reconciliations (statement_row_id, target_account_event_id) VALUES ($rowId, $eventId)");
    }

    /**
     * A digest of every table, so a read can be shown to have changed nothing.
     */
    private function databaseDigest(): string
    {
        $digest = [];
        $tables = $this->db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll();
        foreach ($tables as $table) {
            $rows = $this->db->query('SELECT * FROM "' . $table['name'] . '"')->fetchAll(PDO::FETCH_ASSOC);
            $digest[(string)$table['name']] = count($rows) . ':' . md5((string)json_encode($rows));
        }
        return md5((string)json_encode($digest));
    }
}
