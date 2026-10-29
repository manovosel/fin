<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseTestCase.php';

final class StatementNoFinActionTest extends DatabaseTestCase
{
    public function testNoFinActionDispositionCreatesNoFinancialRecord(): void
    {
        $accountId = $this->createAccount('No FIN action asset', 'asset');
        $rowId = $this->insertStatementRow($accountId, '2026-08-05', -42.50, 'DKK', 'Reviewed external evidence');

        $beforeTransactions = (int)$this->db->query('SELECT COUNT(*) FROM transactions')->fetchColumn();
        $beforeEvents = (int)$this->db->query('SELECT COUNT(*) FROM account_events')->fetchColumn();
        $beforeMovements = (int)$this->db->query('SELECT COUNT(*) FROM account_movements')->fetchColumn();
        $beforeEnvelopeMovements = (int)$this->db->query('SELECT COUNT(*) FROM envelope_movements')->fetchColumn();

        $linkId = statement_reconciliation_mark_no_fin_action($this->db, $rowId);
        self::assertGreaterThan(0, $linkId);

        // Evidence remains stored.
        self::assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM statement_rows WHERE id = ' . $rowId)->fetchColumn());
        self::assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM statement_imports')->fetchColumn());
        self::assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM statement_reconciliations WHERE id = ' . $linkId)->fetchColumn());

        // No financial record was created.
        self::assertSame($beforeTransactions, (int)$this->db->query('SELECT COUNT(*) FROM transactions')->fetchColumn());
        self::assertSame($beforeEvents, (int)$this->db->query('SELECT COUNT(*) FROM account_events')->fetchColumn());
        self::assertSame($beforeMovements, (int)$this->db->query('SELECT COUNT(*) FROM account_movements')->fetchColumn());
        self::assertSame($beforeEnvelopeMovements, (int)$this->db->query('SELECT COUNT(*) FROM envelope_movements')->fetchColumn());

        $link = $this->db->query("SELECT statement_row_id, transaction_id, target_account_event_id, disposition FROM statement_reconciliations WHERE id = $linkId")->fetch();
        self::assertSame($rowId, (int)$link['statement_row_id']);
        self::assertNull($link['transaction_id']);
        self::assertNull($link['target_account_event_id']);
        self::assertSame('no_fin_action', (string)$link['disposition']);
    }

    public function testNoFinActionMarksRowAsResolvedInLoadState(): void
    {
        $accountId = $this->createAccount('No FIN action asset 2', 'asset');
        $rowId = $this->insertStatementRow($accountId, '2026-08-05', -10.00, 'DKK', 'Reviewed evidence');

        $state = statement_reconciliation_load_for_account($this->db, $accountId);
        self::assertCount(1, $state['unresolved']);
        self::assertCount(0, $state['reconciled']);

        statement_reconciliation_mark_no_fin_action($this->db, $rowId);

        $state = statement_reconciliation_load_for_account($this->db, $accountId);
        self::assertCount(0, $state['unresolved']);
        self::assertCount(1, $state['reconciled']);
        self::assertSame('no_fin_action', (string)$state['reconciled'][0]['target_kind']);
        self::assertSame($rowId, (int)$state['reconciled'][0]['statement_row_id']);
    }

    public function testNoFinActionCannotCoexistWithExpenseLink(): void
    {
        $accountId = $this->createAccount('No FIN action asset 3', 'asset');
        $rowId = $this->insertStatementRow($accountId, '2026-08-05', -10.00, 'DKK', 'Conflict test');
        $envelopeId = $this->createEnvelope('Envelope A');
        $txId = save_transaction($this->db, [
            'month_id' => $this->createMonth(2026, 8),
            'envelope_id' => $envelopeId,
            'payment_account_id' => $accountId,
            'amount' => 10.00,
            'spent_date' => '2026-08-05',
        ]);
        statement_reconciliation_link_insert($this->db, $rowId, $txId);

        try {
            statement_reconciliation_mark_no_fin_action($this->db, $rowId);
            self::fail('Expected to reject marking No FIN action when already reconciled to an Expense');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('already has', $error->getMessage());
        }
    }

    public function testNoFinActionCannotCoexistWithTransferLink(): void
    {
        $accountId = $this->createAccount('No FIN action asset 4', 'asset');
        $destinationId = $this->createAccount('No FIN action destination 4', 'asset');
        $rowId = $this->insertStatementRow($accountId, '2026-08-05', -10.00, 'DKK', 'Conflict test');
        $eventId = record_account_transfer($this->db, $accountId, $destinationId, 10.00, '2026-08-05', 'test');
        statement_reconciliation_link_insert($this->db, $rowId, null, $eventId);

        try {
            statement_reconciliation_mark_no_fin_action($this->db, $rowId);
            self::fail('Expected to reject marking No FIN action when already reconciled to a Transfer');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('already has', $error->getMessage());
        }
    }

    public function testRemovingNoFinActionReturnsRowToUnresolvedAndAltersNoFinancialRecord(): void
    {
        $accountId = $this->createAccount('No FIN action asset 5', 'asset');
        $rowId = $this->insertStatementRow($accountId, '2026-08-05', -10.00, 'DKK', 'Reviewed operation');

        $beforeTransactions = (int)$this->db->query('SELECT COUNT(*) FROM transactions')->fetchColumn();
        $beforeEvents = (int)$this->db->query('SELECT COUNT(*) FROM account_events')->fetchColumn();
        $beforeMovements = (int)$this->db->query('SELECT COUNT(*) FROM account_movements')->fetchColumn();
        $beforeEnvelopeMovements = (int)$this->db->query('SELECT COUNT(*) FROM envelope_movements')->fetchColumn();

        $linkId = statement_reconciliation_mark_no_fin_action($this->db, $rowId);
        statement_reconciliation_remove_link($this->db, $linkId);

        // Row returns to unresolved.
        $state = statement_reconciliation_load_for_account($this->db, $accountId);
        self::assertCount(1, $state['unresolved']);
        self::assertCount(0, $state['reconciled']);

        // No financial record was altered.
        self::assertSame($beforeTransactions, (int)$this->db->query('SELECT COUNT(*) FROM transactions')->fetchColumn());
        self::assertSame($beforeEvents, (int)$this->db->query('SELECT COUNT(*) FROM account_events')->fetchColumn());
        self::assertSame($beforeMovements, (int)$this->db->query('SELECT COUNT(*) FROM account_movements')->fetchColumn());
        self::assertSame($beforeEnvelopeMovements, (int)$this->db->query('SELECT COUNT(*) FROM envelope_movements')->fetchColumn());
    }

    public function testNoFinActionDoesNotChangeAccountBalance(): void
    {
        $accountId = $this->createAccount('No FIN action asset 6', 'asset');
        $rowId = $this->insertStatementRow($accountId, '2026-08-05', -50.00, 'DKK', 'Reviewed evidence');

        $balanceBefore = account_balance_as_of($this->db, $accountId, '2026-08-06');

        statement_reconciliation_mark_no_fin_action($this->db, $rowId);

        $balanceAfter = account_balance_as_of($this->db, $accountId, '2026-08-06');
        self::assertSame($balanceBefore, $balanceAfter);
    }

    public function testExpenseReconciliationStillWorksAfterNoFinActionFeature(): void
    {
        $accountId = $this->createAccount('No FIN action asset 7', 'asset');
        $envelopeId = $this->createEnvelope('Envelope A');
        $rowId = $this->insertStatementRow($accountId, '2026-08-05', -25.00, 'DKK', 'Grocery store');
        $txId = save_transaction($this->db, [
            'month_id' => $this->createMonth(2026, 8),
            'envelope_id' => $envelopeId,
            'payment_account_id' => $accountId,
            'amount' => 25.00,
            'spent_date' => '2026-08-05',
        ]);
        $linkId = statement_reconciliation_link_insert($this->db, $rowId, $txId);
        self::assertGreaterThan(0, $linkId);

        $state = statement_reconciliation_load_for_account($this->db, $accountId);
        self::assertSame('transaction', (string)$state['reconciled'][0]['target_kind']);
    }

    public function testTransferReconciliationStillWorksAfterNoFinActionFeature(): void
    {
        $accountId = $this->createAccount('No FIN action asset 8', 'asset');
        $destinationId = $this->createAccount('No FIN action destination 8', 'asset');
        $rowId = $this->insertStatementRow($accountId, '2026-08-05', -30.00, 'DKK', 'Outgoing transfer');
        $eventId = record_account_transfer($this->db, $accountId, $destinationId, 30.00, '2026-08-05', 'test');
        $linkId = statement_reconciliation_link_insert($this->db, $rowId, null, $eventId);
        self::assertGreaterThan(0, $linkId);

        $state = statement_reconciliation_load_for_account($this->db, $accountId);
        self::assertSame('account_event', (string)$state['reconciled'][0]['target_kind']);
    }

    public function testStatementImportInertnessIsPreserved(): void
    {
        // Sanity: importing a statement must not by itself change any financial count.
        $accountId = $this->createAccount('No FIN action asset 9', 'asset');
        $rowId = $this->insertStatementRow($accountId, '2026-08-05', -1.00, 'DKK', 'Inertness test');

        $beforeTransactions = (int)$this->db->query('SELECT COUNT(*) FROM transactions')->fetchColumn();
        $beforeEvents = (int)$this->db->query('SELECT COUNT(*) FROM account_events')->fetchColumn();
        $beforeReconciliations = (int)$this->db->query('SELECT COUNT(*) FROM statement_reconciliations')->fetchColumn();

        // Simply reading via find_reconciliation_candidates must not insert anything.
        $candidates = find_reconciliation_candidates($this->db, $rowId);
        self::assertGreaterThanOrEqual(0, count($candidates['candidates']));

        self::assertSame($beforeTransactions, (int)$this->db->query('SELECT COUNT(*) FROM transactions')->fetchColumn());
        self::assertSame($beforeEvents, (int)$this->db->query('SELECT COUNT(*) FROM account_events')->fetchColumn());
        self::assertSame($beforeReconciliations, (int)$this->db->query('SELECT COUNT(*) FROM statement_reconciliations')->fetchColumn());
    }

    public function testSchemaEnforcesExactlyOneDisposition(): void
    {
        $accountId = $this->createAccount('No FIN action schema asset', 'asset');
        $destinationId = $this->createAccount('No FIN action schema destination', 'asset');
        $rowId = $this->insertStatementRow($accountId, '2026-08-05', -10.00, 'DKK', 'Schema invariant');
        $transactionRowId = $this->insertStatementRow($accountId, '2026-08-06', -10.00, 'DKK', 'Transaction conflict');
        $transferRowId = $this->insertStatementRow($accountId, '2026-08-07', -10.00, 'DKK', 'Transfer conflict');
        $transactionId = save_transaction($this->db, [
            'envelope_id' => $this->createEnvelope('Schema spending'),
            'payment_account_id' => $accountId,
            'amount' => 10.00,
            'spent_date' => '2026-08-06',
        ]);
        $eventId = record_account_transfer($this->db, $accountId, $destinationId, 10.00, '2026-08-07');

        foreach ([
            "INSERT INTO statement_reconciliations (statement_row_id) VALUES ($rowId)",
            "INSERT INTO statement_reconciliations (statement_row_id, disposition) VALUES ($rowId, 'unsupported')",
            "INSERT INTO statement_reconciliations (statement_row_id, transaction_id, disposition) VALUES ($transactionRowId, $transactionId, 'no_fin_action')",
            "INSERT INTO statement_reconciliations (statement_row_id, target_account_event_id, disposition) VALUES ($transferRowId, $eventId, 'no_fin_action')",
        ] as $sql) {
            try {
                $this->db->exec($sql);
                self::fail('Expected the reconciliation disposition CHECK constraint to reject invalid data');
            } catch (PDOException $error) {
                self::assertStringContainsString('CHECK constraint failed', $error->getMessage());
            }
        }
    }

    public function testNoFinActionLoadReturnsLinkIdInTargetIdForUiUniqueness(): void
    {
        $accountId = $this->createAccount('No FIN action asset 10', 'asset');
        $rowId = $this->insertStatementRow($accountId, '2026-08-05', -10.00, 'DKK', 'UI link uniqueness');
        $linkId = statement_reconciliation_mark_no_fin_action($this->db, $rowId);
        $state = statement_reconciliation_load_for_account($this->db, $accountId);
        self::assertSame('no_fin_action', (string)$state['reconciled'][0]['target_kind']);
        self::assertSame($linkId, (int)$state['reconciled'][0]['target_id']);
    }

    private function createAccount(string $name, string $type): int
    {
        $suffix = bin2hex(random_bytes(4));
        return save_account_definition($this->db, [
            'name' => $name,
            'account_number' => "TEST-$suffix",
            'type' => $type,
            'currency' => 'CURRENCY',
            'active' => 1,
        ]);
    }

    private function insertStatementRow(int $accountId, string $bookingDate, float $amount, string $currency, string $description): int
    {
        $checksum = 'sha256:' . bin2hex(random_bytes(8));
        $importId = (int)$this->db->query("INSERT INTO statement_imports (account_id, provider, original_filename, file_checksum, statement_from, statement_to, parser_version, row_count, valid_row_count, problem_count) VALUES ($accountId, 'test', 'test.xlsx', '$checksum', '$bookingDate', '$bookingDate', 1, 1, 1, 0) RETURNING id")->fetchColumn();
        $stmt = $this->db->prepare('INSERT INTO statement_rows (import_id, account_id, provider, source_row_number, booking_date, value_date, amount, currency, description_original, description_normalized, row_fingerprint) VALUES (:import_id, :account_id, :provider, :row_number, :booking_date, :value_date, :amount, :currency, :description, :description_normalized, :fingerprint)');
        $stmt->execute([
            ':import_id' => $importId,
            ':account_id' => $accountId,
            ':provider' => 'test',
            ':row_number' => 1,
            ':booking_date' => $bookingDate,
            ':value_date' => $bookingDate,
            ':amount' => $amount,
            ':currency' => $currency,
            ':description' => $description,
            ':description_normalized' => strtolower($description),
            ':fingerprint' => hash('sha256', $importId . '|' . $accountId . '|' . $bookingDate . '|' . $amount . '|' . $currency . '|' . $description),
        ]);
        return (int)$this->db->lastInsertId();
    }
}
