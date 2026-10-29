<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseTestCase.php';

/**
 * A new Transaction that repeats an existing one exactly - the same date,
 * Envelope, amount and description - is reported to the owner instead of being
 * written silently. Identical same-day entries stay legitimate, so the
 * duplicate is never blocked: the owner confirms it.
 */
final class DuplicateTransactionWarningTest extends DatabaseTestCase
{
    private string|false $previousToday;
    private int $sourceRow = 0;

    protected function setUp(): void
    {
        $this->previousToday = getenv('FIN_TODAY');
        putenv('FIN_TODAY=2026-10-05');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        if ($this->previousToday === false) putenv('FIN_TODAY');
        else putenv('FIN_TODAY=' . $this->previousToday);
    }

    public function testRepeatingTransactionIsRefusedUntilTheOwnerConfirmsIt(): void
    {
        $monthId = $this->createMonth(2026, 10);
        $envelopeId = $this->createEnvelope('Envelope D', 'reserved');
        $entryId = save_entry($this->db, [
            'month_id' => $monthId, 'envelope_id' => $envelopeId, 'type' => 'expense',
            'amount' => 2864, 'status' => 'paid', 'due_date' => '2026-10-01', 'is_bs' => 1,
        ]);
        $existingId = (int)$this->db->query("SELECT id FROM transactions WHERE entry_id = $entryId")->fetchColumn();
        self::assertGreaterThan(0, $existingId);

        $payload = [
            'month_id' => $monthId, 'entry_id' => $entryId, 'envelope_id' => $envelopeId,
            'envelope' => 'Envelope D', 'amount' => 2864, 'note' => 'Direct debit', 'spent_date' => '2026-10-01',
        ];

        try {
            save_transaction($this->db, $payload);
            self::fail('A repeating transaction was accepted without confirmation');
        } catch (DuplicateTransactionException $error) {
            $duplicate = $error->duplicate();
            self::assertSame($existingId, (int)$duplicate['id']);
            self::assertSame('Envelope D', (string)$duplicate['envelope']);
            self::assertSame('Direct debit', (string)$duplicate['note']);
            self::assertSame('2026-10-01', (string)$duplicate['spent_date']);
            self::assertEqualsWithDelta(2864, (float)$duplicate['amount'], 0.001);
            self::assertSame('expense', (string)$duplicate['kind']);
        }

        self::assertSame(1, $this->transactionCount());
        self::assertEqualsWithDelta(0, $this->envelopeBalance($envelopeId), 0.001);

        $confirmedId = save_transaction($this->db, $payload, null, false, false, true);
        self::assertGreaterThan(0, $confirmedId);
        self::assertSame(2, $this->transactionCount());
        self::assertEqualsWithDelta(-2864, $this->envelopeBalance($envelopeId), 0.001);
    }

    public function testOnlyAnExactRepeatIsReported(): void
    {
        $monthId = $this->createMonth(2026, 10);
        $envelopeId = $this->createEnvelope('Envelope E', 'reserved');
        $otherEnvelopeId = $this->createEnvelope('Envelope F', 'reserved');
        $base = [
            'month_id' => $monthId, 'envelope_id' => $envelopeId, 'envelope' => 'Envelope E',
            'amount' => 3973, 'note' => 'Direct debit', 'spent_date' => '2026-10-01',
        ];
        save_transaction($this->db, $base);

        $differentDate = $base; $differentDate['spent_date'] = '2026-10-02';
        $differentAmount = $base; $differentAmount['amount'] = 3972.99;
        $differentNote = $base; $differentNote['note'] = 'Envelope E October';
        $differentEnvelope = $base; $differentEnvelope['envelope_id'] = $otherEnvelopeId; $differentEnvelope['envelope'] = 'Envelope F';
        $differentKind = $base; $differentKind['kind'] = 'refund';

        foreach ([$differentDate, $differentAmount, $differentNote, $differentEnvelope, $differentKind] as $variant) {
            save_transaction($this->db, $variant);
        }

        self::assertSame(6, $this->transactionCount());
    }

    public function testDescriptionComparisonIgnoresCaseAndExtraWhitespace(): void
    {
        $monthId = $this->createMonth(2026, 10);
        $envelopeId = $this->createEnvelope('Envelope G', 'reserved');
        $base = [
            'month_id' => $monthId, 'envelope_id' => $envelopeId, 'envelope' => 'Envelope G',
            'amount' => 1412.63, 'note' => 'Direct debit', 'spent_date' => '2026-10-01',
        ];
        save_transaction($this->db, $base);

        $this->expectException(DuplicateTransactionException::class);
        save_transaction($this->db, array_merge($base, ['note' => '  direct debit  ']));
    }

    public function testPlanDerivedBetalingsservicePaymentIsNeverBlocked(): void
    {
        $monthId = $this->createMonth(2026, 10);
        $envelopeId = $this->createEnvelope('Envelope E', 'reserved');
        save_transaction($this->db, [
            'month_id' => $monthId, 'envelope_id' => $envelopeId, 'envelope' => 'Envelope E',
            'amount' => 3973, 'note' => 'Direct debit', 'spent_date' => '2026-10-01',
        ]);

        $entryId = save_entry($this->db, [
            'month_id' => $monthId, 'envelope_id' => $envelopeId, 'type' => 'expense',
            'amount' => 3973, 'status' => 'paid', 'due_date' => '2026-10-01', 'is_bs' => 1,
        ]);

        self::assertSame(2, $this->transactionCount());
        self::assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM transactions WHERE entry_id = $entryId AND is_automatic = 1")->fetchColumn());
    }

    public function testEditingATransactionIsNotTreatedAsACreation(): void
    {
        $monthId = $this->createMonth(2026, 10);
        $envelopeId = $this->createEnvelope('Envelope D', 'reserved');
        $transactionId = save_transaction($this->db, [
            'month_id' => $monthId, 'envelope_id' => $envelopeId, 'envelope' => 'Envelope D',
            'amount' => 2864, 'note' => 'Direct debit', 'spent_date' => '2026-10-01',
        ]);

        save_transaction($this->db, [
            'month_id' => $monthId, 'envelope_id' => $envelopeId, 'envelope' => 'Envelope D',
            'amount' => 2864, 'note' => 'Direct debit', 'spent_date' => '2026-10-01',
        ], $transactionId);

        self::assertSame(1, $this->transactionCount());
    }

    public function testStatementReconciliationReportsTheDuplicateAndWaitsForConfirmation(): void
    {
        $monthId = $this->createMonth(2026, 10);
        $envelopeId = $this->createEnvelope('Envelope E', 'reserved');
        $accountId = $this->account('Statement Account');
        save_entry($this->db, [
            'month_id' => $monthId, 'envelope_id' => $envelopeId, 'type' => 'expense',
            'amount' => 3973, 'status' => 'paid', 'due_date' => '2026-10-01', 'is_bs' => 1,
        ]);
        $rowId = $this->statementRow($accountId, '2026-10-01', -3973, 'Direct debit');

        $results = statement_reconciliation_batch_expenses($this->db, $accountId, [
            ['statement_row_id' => $rowId, 'classification' => 'envelope:' . $envelopeId],
        ]);

        self::assertCount(1, $results);
        self::assertSame('duplicate', $results[0]['status']);
        self::assertEqualsWithDelta(3973, (float)($results[0]['duplicate']['amount'] ?? 0), 0.001);
        self::assertSame('Direct debit', (string)($results[0]['duplicate']['note'] ?? ''));
        self::assertSame(1, $this->transactionCount());
        self::assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM statement_reconciliations WHERE statement_row_id = $rowId")->fetchColumn());

        $confirmed = statement_reconciliation_batch_expenses($this->db, $accountId, [
            ['statement_row_id' => $rowId, 'classification' => 'envelope:' . $envelopeId, 'confirm_duplicate' => true],
        ]);

        self::assertCount(1, $confirmed);
        self::assertSame('saved', $confirmed[0]['status']);
        self::assertSame(2, $this->transactionCount());
        self::assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM statement_reconciliations WHERE statement_row_id = $rowId")->fetchColumn());
    }

    public function testDeterministicStatementProcessingReportsTheDuplicateInsteadOfWritingASecondRecord(): void
    {
        $monthId = $this->createMonth(2026, 10);
        $envelopeId = $this->createEnvelope('Envelope D', 'reserved');
        $accountId = $this->account('Statement Account');
        $firstRowId = $this->statementRow($accountId, '2026-10-01', -2864, 'Direct debit');
        statement_reconciliation_batch_expenses($this->db, $accountId, [
            ['statement_row_id' => $firstRowId, 'classification' => 'envelope:' . $envelopeId],
        ]);
        self::assertSame(1, $this->transactionCount());

        // The second, identical statement row is processed deterministically.
        // The first row's Transaction is already reconciled, so it is not a
        // candidate and the deterministic rule would write it a second time.
        $secondRowId = $this->statementRow($accountId, '2026-10-01', -2864, 'Direct debit');
        $processed = statement_reconciliation_auto_process_row($this->db, $secondRowId);

        self::assertSame('exception', $processed['outcome']);
        self::assertSame('duplicate_transaction', $processed['code']);
        self::assertEqualsWithDelta(2864, (float)($processed['duplicate']['amount'] ?? 0), 0.001);
        self::assertSame(1, $this->transactionCount());
    }

    private function transactionCount(): int
    {
        return (int)$this->db->query('SELECT COUNT(*) FROM transactions')->fetchColumn();
    }

    private function account(string $name): int
    {
        return save_account_definition($this->db, [
            'name' => $name,
            'account_number' => 'TEST-' . strtoupper(str_replace(' ', '-', $name)) . '-' . (++$this->sourceRow),
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
            ':currency' => (string)currency_context($this->db)['currency'],
            ':description' => $description,
            ':normalized' => statement_normalize_token($description),
            ':fingerprint' => hash('sha256', $checksum . '|row'),
        ]);
        return (int)$this->db->lastInsertId();
    }
}
