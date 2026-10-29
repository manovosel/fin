<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseTestCase.php';

/**
 * A Plan-derived Betalingsservice payment and the real payment that arrives later as
 * statement evidence are one and the same payment.
 *
 * The automatic Betalingsservice Transaction carries the planned amount and, when the
 * allocation has no "Paid from" Account, no payment Account at all. The statement row is
 * the proof of what was really paid and from which Account, so the deterministic
 * reconciliation links the two instead of writing a second Expense.
 */
final class StatementAutomaticBsReconciliationTest extends DatabaseTestCase
{
    private string|false $previousToday;
    private string $currency;
    private int $sourceRow = 0;

    protected function setUp(): void
    {
        $this->previousToday = getenv('FIN_TODAY');
        putenv('FIN_TODAY=2026-10-05');
        parent::setUp();
        $this->currency = (string)currency_context($this->db)['currency'];
    }

    protected function tearDown(): void
    {
        if ($this->previousToday === false) putenv('FIN_TODAY');
        else putenv('FIN_TODAY=' . $this->previousToday);
    }

    public function testAutomaticBsTransactionWithoutPaymentAccountIsLinkedAndAdoptsTheStatementAccount(): void
    {
        $monthId = $this->createMonth(2026, 10);
        $envelopeId = $this->createEnvelope('Envelope D', 'reserved');
        $accountId = $this->account('Statement Account', 'BS-NULL-ACCOUNT');
        $transactionId = $this->fundBetalingsservice($monthId, $envelopeId, 2864, '2026-10-01');

        self::assertNull($this->paymentAccount($transactionId));
        $rowId = $this->statementRow($accountId, '2026-10-01', -2864, 'Direct debit');

        $result = statement_reconciliation_auto_process_row($this->db, $rowId);

        self::assertSame('processed', $result['outcome']);
        self::assertSame('link_existing', $result['action']);
        self::assertSame($transactionId, $result['target_id']);
        self::assertSame(1, $this->tableCount('transactions'));
        self::assertSame(1, $this->tableCount('statement_reconciliations'));
        self::assertSame($accountId, $this->paymentAccount($transactionId));
        self::assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM account_events WHERE event_type='expense' AND source_account_id = " . $accountId)->fetchColumn());
        self::assertEqualsWithDelta(0, $this->envelopeBalance($envelopeId), 0.001);
    }

    public function testExactAccountMatchIsPreferredOverANullAccountMatch(): void
    {
        $monthId = $this->createMonth(2026, 10);
        $envelopeId = $this->createEnvelope('Envelope D', 'reserved');
        $accountId = $this->account('Statement Account', 'BS-EXACT-PREFERRED');
        $nullAccountId = save_transaction($this->db, [
            'month_id' => $monthId, 'envelope_id' => $envelopeId, 'envelope' => 'Envelope D',
            'payment_account_id' => null, 'amount' => 2864, 'note' => 'Unknown payer', 'spent_date' => '2026-10-01',
        ]);
        $exactAccountId = save_transaction($this->db, [
            'month_id' => $monthId, 'envelope_id' => $envelopeId, 'envelope' => 'Envelope D',
            'payment_account_id' => $accountId, 'amount' => 2864, 'note' => 'Known payer', 'spent_date' => '2026-10-01',
        ]);
        $rowId = $this->statementRow($accountId, '2026-10-01', -2864, 'Direct debit');

        $candidates = find_reconciliation_candidates($this->db, $rowId)['candidates'];
        self::assertSame([$exactAccountId], array_column($candidates, 'transaction_id'));

        $result = statement_reconciliation_auto_process_row($this->db, $rowId);

        self::assertSame('processed', $result['outcome']);
        self::assertSame($exactAccountId, $result['target_id']);
        self::assertNull($this->paymentAccount($nullAccountId));
        self::assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM statement_reconciliations WHERE transaction_id = " . $nullAccountId)->fetchColumn());
    }

    public function testSeveralNullAccountCandidatesRemainAnAmbiguousException(): void
    {
        $monthId = $this->createMonth(2026, 10);
        $envelopeId = $this->createEnvelope('Envelope D', 'reserved');
        $accountId = $this->account('Statement Account', 'BS-NULL-AMBIGUOUS');
        save_transaction($this->db, [
            'month_id' => $monthId, 'envelope_id' => $envelopeId, 'envelope' => 'Envelope D',
            'payment_account_id' => null, 'amount' => 2864, 'note' => 'First unknown payer', 'spent_date' => '2026-10-01',
        ]);
        save_transaction($this->db, [
            'month_id' => $monthId, 'envelope_id' => $envelopeId, 'envelope' => 'Envelope D',
            'payment_account_id' => null, 'amount' => 2864, 'note' => 'Second unknown payer', 'spent_date' => '2026-10-02',
        ]);
        $rowId = $this->statementRow($accountId, '2026-10-01', -2864, 'Direct debit');

        $result = statement_reconciliation_auto_process_row($this->db, $rowId);

        self::assertSame('exception', $result['outcome']);
        self::assertSame('ambiguous_match', $result['code']);
        self::assertCount(2, $result['candidates']);
        self::assertSame(2, $this->tableCount('transactions'));
        self::assertSame(0, $this->tableCount('statement_reconciliations'));
    }

    public function testATransactionPaidFromAnotherAccountIsNeverACandidate(): void
    {
        $monthId = $this->createMonth(2026, 10);
        $envelopeId = $this->createEnvelope('Envelope A', 'daily');
        $accountId = $this->account('Statement Account', 'BS-OTHER-ACCOUNT');
        $otherAccountId = $this->account('Other Account', 'BS-OTHER-PAID-FROM');
        save_transaction($this->db, [
            'month_id' => $monthId, 'envelope_id' => $envelopeId, 'envelope' => 'Envelope A',
            'payment_account_id' => $otherAccountId, 'amount' => 250, 'note' => 'Paid elsewhere', 'spent_date' => '2026-10-01',
        ]);
        $rowId = $this->statementRow($accountId, '2026-10-01', -250, 'Envelope A');

        $candidates = find_reconciliation_candidates($this->db, $rowId)['candidates'];

        self::assertSame([], $candidates);
    }

    public function testAmountMismatchLinksTheAutomaticBsTransactionAndAdoptsTheRealAmount(): void
    {
        $monthId = $this->createMonth(2026, 10);
        $envelopeId = $this->createEnvelope('Envelope F', 'reserved');
        $accountId = $this->account('Statement Account', 'BS-AMOUNT-MISMATCH');
        $this->reconcilePriorMerchant($accountId, $envelopeId, '2026-09-01', -999, 'Envelope F');
        $transactionId = $this->fundBetalingsservice($monthId, $envelopeId, 999, '2026-10-01');
        $rowId = $this->statementRow($accountId, '2026-10-01', -1092.73, 'Envelope F');

        $result = statement_reconciliation_auto_process_row($this->db, $rowId);
        $transaction = $this->db->query('SELECT * FROM transactions WHERE id=' . $transactionId)->fetch();

        self::assertSame('processed', $result['outcome']);
        self::assertSame('link_automatic_bs', $result['action']);
        self::assertSame($transactionId, $result['target_id']);
        self::assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM statement_reconciliations WHERE statement_row_id = " . $rowId . " AND transaction_id = " . $transactionId)->fetchColumn());
        self::assertEqualsWithDelta(1092.73, (float)$transaction['amount'], 0.001);
        self::assertSame($accountId, (int)$transaction['payment_account_id']);
        self::assertSame(1, (int)$transaction['is_automatic']);
        self::assertSame('Direct debit', (string)$transaction['note']);
        self::assertEqualsWithDelta(-1092.73, (float)$this->expenseMovementAmount($transactionId), 0.001);
        self::assertEqualsWithDelta(999, (float)$this->fundingMovementAmount($transactionId), 0.001);
        // The prior month's 999 expense and the real 1092.73 payment both leave the Envelope
        // short; the Plan funded 999 in this month only.
        self::assertEqualsWithDelta(-1092.73, $this->envelopeBalance($envelopeId), 0.001);
    }

    public function testSeveralRealDebitsAgainstOnePlannedPaymentStayUnmatched(): void
    {
        $monthId = $this->createMonth(2026, 10);
        $envelopeId = $this->createEnvelope('Envelope G', 'reserved');
        $accountId = $this->account('Statement Account', 'BS-TWO-DEBITS');
        $this->reconcilePriorMerchant($accountId, $envelopeId, '2026-09-01', -1412.63, 'Envelope G');
        $transactionId = $this->fundBetalingsservice($monthId, $envelopeId, 1412.63, '2026-10-01');
        $firstRowId = $this->statementRow($accountId, '2026-10-01', -1200, 'Envelope G');
        $secondRowId = $this->statementRow($accountId, '2026-10-01', -212.63, 'Envelope G');

        $first = statement_reconciliation_auto_process_row($this->db, $firstRowId);
        $second = statement_reconciliation_auto_process_row($this->db, $secondRowId);
        $transaction = $this->db->query('SELECT * FROM transactions WHERE id=' . $transactionId)->fetch();

        self::assertSame('exception', $first['outcome']);
        self::assertSame('ambiguous_automatic_payment', $first['code']);
        self::assertSame('exception', $second['outcome']);
        self::assertSame('ambiguous_automatic_payment', $second['code']);
        self::assertEqualsWithDelta(1412.63, (float)$transaction['amount'], 0.001);
        self::assertNull($this->paymentAccount($transactionId));
        self::assertSame(2, $this->tableCount('transactions'));
        self::assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM statement_reconciliations WHERE statement_row_id IN (" . $firstRowId . ',' . $secondRowId . ')')->fetchColumn());
    }

    /**
     * A bounded fixture measures how much of the Transaction history carries no payment
     * Account at all, and how many of those Transactions a statement row of the same amount
     * and date could claim. The fixture is synthetic and small on purpose: no production
     * data is read.
     */
    public function testBoundedFixtureMeasuresTheUnpaidTransactionExposure(): void
    {
        $monthId = $this->createMonth(2026, 10);
        $accountId = $this->account('Statement Account', 'BS-EXPOSURE');
        $envelopeId = $this->createEnvelope('Envelope A', 'daily');
        for ($index = 0; $index < 5; $index++) {
            save_transaction($this->db, [
                'month_id' => $monthId, 'envelope_id' => $envelopeId, 'envelope' => 'Envelope A',
                'payment_account_id' => null, 'amount' => 100, 'note' => 'Unpaid ' . $index, 'spent_date' => '2026-10-01',
            ]);
        }
        $rowId = $this->statementRow($accountId, '2026-10-01', -100, 'Envelope A');

        $unpaid = (int)$this->db->query('SELECT COUNT(*) FROM transactions WHERE payment_account_id IS NULL')->fetchColumn();
        $candidates = find_reconciliation_candidates($this->db, $rowId)['candidates'];

        self::assertSame(5, $unpaid);
        self::assertCount(5, $candidates);
        foreach ($candidates as $candidate) {
            self::assertNull($candidate['payment_account_id']);
        }
    }

    private function fundBetalingsservice(int $monthId, int $envelopeId, float $amount, string $dueDate): int
    {
        $entryId = save_entry($this->db, [
            'month_id' => $monthId, 'envelope_id' => $envelopeId, 'type' => 'expense',
            'amount' => $amount, 'status' => 'paid', 'due_date' => $dueDate, 'is_bs' => 1,
        ]);
        $transactionId = (int)$this->db->query("SELECT id FROM transactions WHERE entry_id = " . $entryId . " AND is_automatic = 1")->fetchColumn();
        self::assertGreaterThan(0, $transactionId);
        return $transactionId;
    }

    /** A prior reconciled statement row that establishes the Envelope of one merchant. */
    private function reconcilePriorMerchant(int $accountId, int $envelopeId, string $date, float $amount, string $description): void
    {
        $rowId = $this->statementRow($accountId, $date, $amount, $description);
        $results = statement_reconciliation_batch_expenses($this->db, $accountId, [
            ['statement_row_id' => $rowId, 'classification' => 'envelope:' . $envelopeId],
        ]);
        self::assertSame('saved', $results[0]['status']);
    }

    private function paymentAccount(int $transactionId): ?int
    {
        $value = $this->db->query('SELECT payment_account_id FROM transactions WHERE id=' . $transactionId)->fetchColumn();
        return $value === null ? null : (int)$value;
    }

    private function expenseMovementAmount(int $transactionId): float
    {
        return (float)$this->db->query("SELECT amount FROM envelope_movements WHERE transaction_id = " . $transactionId . " AND type = 'expense'")->fetchColumn();
    }

    private function fundingMovementAmount(int $transactionId): float
    {
        return (float)$this->db->query("SELECT amount FROM envelope_movements WHERE entry_id = (SELECT entry_id FROM transactions WHERE id = " . $transactionId . ") AND type = 'funding'")->fetchColumn();
    }

    private function account(string $name, string $number): int
    {
        return save_account_definition($this->db, [
            'name' => $name,
            'account_number' => $number,
            'currency' => 'CURRENCY',
        ]);
    }

    private function statementRow(int $accountId, string $date, float $amount, string $description): int
    {
        $this->sourceRow++;
        $checksum = hash('sha256', $accountId . '|' . $this->sourceRow . '|' . $description);
        $insertImport = $this->db->prepare('INSERT INTO statement_imports
            (account_id,provider,original_filename,file_checksum,parser_version,row_count,valid_row_count,problem_count)
            VALUES (:account_id,:provider,:filename,:checksum,:parser_version,1,1,0)');
        $insertImport->execute([
            ':account_id' => $accountId,
            ':provider' => 'test_provider',
            ':filename' => 'fixture-' . $this->sourceRow . '.xlsx',
            ':checksum' => $checksum,
            ':parser_version' => STATEMENT_PARSER_VERSION,
        ]);
        $importId = (int)$this->db->lastInsertId();
        $insert = $this->db->prepare('INSERT INTO statement_rows
            (import_id,account_id,provider,source_row_number,booking_date,value_date,amount,currency,description_original,description_normalized,row_fingerprint,occurrence_index,parse_problem,raw_fields_json)
            VALUES (:import_id,:account_id,:provider,:source_row,:booking_date,:value_date,:amount,:currency,:description,:normalized,:fingerprint,0,\'\',\'{}\')');
        $insert->execute([
            ':import_id' => $importId,
            ':account_id' => $accountId,
            ':provider' => 'test_provider',
            ':source_row' => $this->sourceRow,
            ':booking_date' => $date,
            ':value_date' => $date,
            ':amount' => $amount,
            ':currency' => $this->currency,
            ':description' => $description,
            ':normalized' => statement_normalize_token($description),
            ':fingerprint' => hash('sha256', $checksum . '|row'),
        ]);
        return (int)$this->db->lastInsertId();
    }

    private function tableCount(string $table): int
    {
        return (int)$this->db->query("SELECT COUNT(*) FROM $table")->fetchColumn();
    }
}
