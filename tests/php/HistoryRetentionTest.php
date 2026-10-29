<?php

declare(strict_types=1);

final class HistoryRetentionTest extends DatabaseTestCase
{
    public function testPreviewAndCleanupKeepNewestTwelveMonthsWithoutChangingBalances(): void
    {
        $months = [];
        for ($offset = 0; $offset < 14; $offset++) {
            $date = (new DateTimeImmutable('2025-01-01'))->modify("+$offset months");
            $months[$date->format('Y-m')] = $this->createMonth((int)$date->format('Y'), (int)$date->format('n'));
        }
        $source = $this->createEnvelope('Source');
        $destination = $this->createEnvelope('Destination');
        $oldEntry = $this->insertEntry($months['2025-02'], $source, 200);
        $oldTransaction = $this->insertTransaction($months['2025-02'], $oldEntry, $source, 100, '2025-02-10');
        $currentTransaction = $this->insertTransaction($months['2026-02'], null, $source, 10, '2026-02-10');

        $this->insertMovement($source, $months['2025-01'], 'opening_balance', 1000, '2025-01-01');
        $this->insertMovement($source, $months['2025-02'], 'funding', 200, '2025-02-05', $oldEntry);
        $this->insertMovement($source, $months['2025-02'], 'expense', -100, '2025-02-10', null, $oldTransaction);
        $oldAdjustment = $this->insertMovement($source, $months['2025-02'], 'adjustment', 30, '2025-02-20');
        $this->insertMovement($source, $months['2025-04'], 'adjustment', 50, '2025-04-01');
        $retainedReversal = $this->insertMovement($source, $months['2025-04'], 'reversal', -30, '2025-04-02', null, null, null, $oldAdjustment);
        $this->insertMovement($source, $months['2026-02'], 'expense', -10, '2026-02-10', null, $currentTransaction);

        $transferId = $this->insertTransfer($source, $destination, 25, '2025-02-15');
        $this->insertMovement($source, $months['2025-02'], 'transfer_out', -25, '2025-02-15', null, null, $transferId);
        $this->insertMovement($destination, $months['2025-02'], 'transfer_in', 25, '2025-02-15', null, null, $transferId);
        $crossBoundaryTransferId = $this->insertTransfer($source, $destination, 10, '2025-02-16');
        $this->insertMovement($source, $months['2025-02'], 'transfer_out', -10, '2025-02-16', null, null, $crossBoundaryTransferId);
        $this->insertMovement($destination, $months['2025-02'], 'transfer_in', 10, '2025-02-16', null, null, $crossBoundaryTransferId);
        $this->insertMovement($source, $months['2025-04'], 'transfer_reversal_in', 10, '2025-04-03', null, null, $crossBoundaryTransferId);
        $this->insertMovement($destination, $months['2025-04'], 'transfer_reversal_out', -10, '2025-04-03', null, null, $crossBoundaryTransferId);
        $this->db->prepare("INSERT INTO envelope_recommendation_settings (envelope_id, manual_baseline, manual_baseline_note) VALUES (?, 1200, 'Manual historical review')")->execute([$source]);
        save_recommendation_exclusion($this->db, $source, 2025, 2, true, 'Exceptional month');

        $before = envelope_balance_snapshot($this->db);
        $preview = retention_preview($this->db, 12);
        self::assertTrue($preview['cleanup_required']);
        self::assertSame('2025-03-01', $preview['cutoff']);
        self::assertSame('2025-02-28', $preview['archive_through']);
        self::assertSame(['2025-01', '2025-02'], $preview['month_labels']);
        self::assertSame(2, $preview['months']);
        self::assertSame(1, $preview['entries']);
        self::assertSame(1, $preview['transactions']);
        self::assertSame(1, $preview['transfers']);

        $result = cleanup_database_history($this->db, 12);

        self::assertTrue($result['balances_verified']);
        self::assertSame($before, envelope_balance_snapshot($this->db));
        self::assertSame(12, (int)$this->db->query('SELECT COUNT(*) FROM months')->fetchColumn());
        self::assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM months WHERE year = 2025 AND month IN (1, 2)")->fetchColumn());
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM entries WHERE id = ' . $oldEntry)->fetchColumn());
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM transactions WHERE id = ' . $oldTransaction)->fetchColumn());
        self::assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM transactions WHERE id = ' . $currentTransaction)->fetchColumn());
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM envelope_transfers WHERE id = ' . $transferId)->fetchColumn());
        self::assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM envelope_transfers WHERE id = ' . $crossBoundaryTransferId)->fetchColumn());
        self::assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM recommendation_exclusions WHERE envelope_id = $source AND year = 2025 AND month = 2")->fetchColumn());
        self::assertEqualsWithDelta(1200, (float)$this->db->query("SELECT manual_baseline FROM envelope_recommendation_settings WHERE envelope_id = $source")->fetchColumn(), 0.001);
        self::assertSame(2, (int)$this->db->query("SELECT COUNT(*) FROM envelope_movements WHERE type = 'archive_rollup'")->fetchColumn());
        self::assertSame('2025-03-01', $this->db->query("SELECT movement_date FROM envelope_movements WHERE type = 'archive_rollup' LIMIT 1")->fetchColumn());
        self::assertNull($this->db->query('SELECT reversal_of_movement_id FROM envelope_movements WHERE id = ' . $retainedReversal)->fetchColumn());
        self::assertSame('ok', strtolower((string)$this->db->query('PRAGMA integrity_check')->fetchColumn()));
        self::assertFalse($this->db->query('PRAGMA foreign_key_check')->fetch());
    }

    public function testCleanupPreservesAccountObservationsAndOrdinaryEventBalances(): void
    {
        $months = [];
        for ($offset = 0; $offset < 14; $offset++) {
            $date = (new DateTimeImmutable('2025-01-01'))->modify("+$offset months");
            $months[$date->format('Y-m')] = $this->createMonth((int)$date->format('Y'), (int)$date->format('n'));
        }
        $envelope = $this->createEnvelope('Account retention', 'reserved');
        $asset = save_account_definition($this->db, ['name' => 'Asset A', 'account_number' => 'TEST-ASSET-A', 'currency' => 'CURRENCY']);
        $assetB = save_account_definition($this->db, ['name' => 'Asset B', 'account_number' => 'TEST-ASSET-B', 'currency' => 'CURRENCY']);
        $liability = save_account_definition($this->db, ['name' => 'Liability', 'account_number' => 'TEST-LIABILITY', 'currency' => 'CURRENCY']);
        record_account_balance_observation($this->db, $asset, 10000, '2025-01-01');
        record_account_balance_observation($this->db, $assetB, 1000, '2025-01-01');
        record_account_balance_observation($this->db, $liability, 2000, '2025-01-01');
        $assetExpense = save_transaction($this->db, ['month_id' => $months['2025-01'], 'envelope_id' => $envelope, 'payment_account_id' => $asset, 'amount' => 100, 'spent_date' => '2025-01-10']);
        $liabilityExpense = save_transaction($this->db, ['month_id' => $months['2025-02'], 'envelope_id' => $envelope, 'payment_account_id' => $liability, 'amount' => 200, 'spent_date' => '2025-02-10']);
        // ALL ACCOUNTS ARE ACCOUNTS — liability↔asset Account-to-Account movement uses
        // event_type='transfer'. Previously this required event_type IN ('borrowing','repayment'),
        // which has been retired from the active schema in v2.12.
        record_account_event($this->db, 'transfer', 500, '2025-02-11', $asset, $assetB);
        record_account_event($this->db, 'transfer', 1000, '2025-02-12', $liability, $assetB);
        record_account_event($this->db, 'transfer', 300, '2025-02-13', $asset, $liability);
        record_account_balance_observation($this->db, $asset, 9000, '2025-02-14', 'Verified Balance');
        $positionsBefore = account_position_snapshot($this->db, '2026-02-28');
        $envelopesBefore = envelope_balance_snapshot($this->db);

        $result = cleanup_database_history($this->db, 12);

        self::assertTrue($result['account_positions_verified']);
        self::assertSame($positionsBefore, account_position_snapshot($this->db, '2026-02-28'));
        self::assertSame($envelopesBefore, envelope_balance_snapshot($this->db));
        self::assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM transactions WHERE id IN ($assetExpense,$liabilityExpense)")->fetchColumn());
        self::assertSame(2, (int)$this->db->query("SELECT COUNT(*) FROM account_events WHERE event_type='expense' AND transaction_id IS NULL")->fetchColumn());
        foreach (['expense' => 2, 'transfer' => 3] as $type => $count) {
            self::assertSame($count, (int)$this->db->query("SELECT COUNT(*) FROM account_events WHERE event_type='$type'")->fetchColumn(), $type);
        }
        self::assertSame(4, (int)$this->db->query('SELECT COUNT(*) FROM account_balance_observations')->fetchColumn());
        self::assertSame('ok', strtolower((string)$this->db->query('PRAGMA integrity_check')->fetchColumn()));
        self::assertFalse($this->db->query('PRAGMA foreign_key_check')->fetch());
    }

    public function testCleanupPreservesRefundEnvelopeAndAccountTruthWhileDroppingDisposableProvenance(): void
    {
        $months = [];
        for ($offset = 0; $offset < 14; $offset++) {
            $date = (new DateTimeImmutable('2025-01-01'))->modify("+$offset months");
            $months[$date->format('Y-m')] = $this->createMonth((int)$date->format('Y'), (int)$date->format('n'));
        }
        $envelopeId = $this->createEnvelope('Retained Refund', 'daily');
        $accountId = create_account_with_initial_position($this->db, [
            'name' => 'Refund retention account', 'account_number' => 'TEST-REFUND-RETENTION', 'type' => 'asset', 'currency' => 'CURRENCY',
            'initial_balance' => 1000,
        ]);
        $this->insertMovement($envelopeId, $months['2025-01'], 'opening_balance', 500, '2025-01-01');
        $refundId = save_transaction($this->db, [
            'month_id' => $months['2025-02'], 'envelope_id' => $envelopeId, 'payment_account_id' => $accountId,
            'amount' => 40, 'spent_date' => '2025-02-10', 'kind' => 'refund',
        ]);
        $eventId = (int)$this->db->query("SELECT id FROM account_events WHERE transaction_id=$refundId")->fetchColumn();
        $importId = (int)$this->db->query("INSERT INTO statement_imports (account_id,provider,original_filename,file_checksum,parser_version,row_count,valid_row_count,problem_count) VALUES ($accountId,'test','refund.xlsx','sha256:retention-refund',1,1,1,0) RETURNING id")->fetchColumn();
        $this->db->exec("INSERT INTO statement_rows (import_id,account_id,provider,source_row_number,booking_date,amount,currency,row_fingerprint) VALUES ($importId,$accountId,'test',1,'2025-02-10',40,'DKK','retention-refund-row')");
        $statementRowId = (int)$this->db->lastInsertId();
        statement_reconciliation_link_insert($this->db, $statementRowId, $refundId);
        $envelopeBefore = envelope_balance_snapshot($this->db);
        $accountBefore = account_position_snapshot($this->db, '2026-02-28');

        cleanup_database_history($this->db, 12);

        self::assertSame($envelopeBefore, envelope_balance_snapshot($this->db));
        self::assertSame($accountBefore, account_position_snapshot($this->db, '2026-02-28'));
        self::assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM transactions WHERE id=$refundId")->fetchColumn());
        $event = $this->db->query("SELECT event_type,transaction_id FROM account_events WHERE id=$eventId")->fetch();
        self::assertSame('refund', $event['event_type']);
        self::assertNull($event['transaction_id']);
        self::assertEqualsWithDelta(40, (float)$this->db->query("SELECT amount FROM account_movements WHERE event_id=$eventId")->fetchColumn(), 0.001);
        self::assertEqualsWithDelta(540, (float)$this->db->query("SELECT amount FROM envelope_movements WHERE type='archive_rollup' AND envelope_id=$envelopeId")->fetchColumn(), 0.001);
        self::assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM statement_reconciliations WHERE statement_row_id=$statementRowId")->fetchColumn());
        self::assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM statement_rows WHERE id=$statementRowId")->fetchColumn());
        self::assertCount(1, statement_reconciliation_load_for_account($this->db, $accountId)['unresolved']);
        self::assertFalse($this->db->query('PRAGMA foreign_key_check')->fetch());
    }

    public function testCleanupIsANoOpWhenDatabaseHasTwelveMonths(): void
    {
        for ($month = 1; $month <= 12; $month++) $this->createMonth(2026, $month);

        $preview = retention_preview($this->db, 12);
        self::assertFalse($preview['cleanup_required']);
        self::assertNull($preview['cutoff']);
        self::assertSame(0, $preview['months']);
        self::assertSame($preview, cleanup_database_history($this->db, 12));
    }

    public function testCleanupProtectsAnOldSourceMonthWhosePaymentIsAfterTheCutoff(): void
    {
        $january = $this->createMonth(2026, 1);
        $february = $this->createMonth(2026, 2);
        $this->createMonth(2026, 3);
        $this->createMonth(2026, 4);
        $envelopeId = $this->createEnvelope('Future bill', 'reserved');
        $entryId = $this->insertEntry($february, $envelopeId, 500);
        $transactionId = $this->insertTransaction($february, $entryId, $envelopeId, 500, '2026-03-15');
        $this->insertMovement($envelopeId, $february, 'funding', 500, '2026-02-14', $entryId);
        $expenseId = $this->insertMovement($envelopeId, $february, 'expense', -500, '2026-03-15', null, $transactionId);
        $this->insertMovement($envelopeId, $january, 'opening_balance', 100, '2026-01-01');

        $preview = retention_preview($this->db, 2);
        self::assertSame('2026-03-01', $preview['cutoff']);
        self::assertSame(1, $preview['protected_future_months']);
        self::assertSame(['2026-02'], $preview['protected_month_labels']);
        self::assertSame(['2026-01'], $preview['month_labels']);
        self::assertSame(1, $preview['months']);

        cleanup_database_history($this->db, 2);

        self::assertSame(3, (int)$this->db->query('SELECT COUNT(*) FROM months')->fetchColumn());
        self::assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM months WHERE id = ' . $february)->fetchColumn());
        self::assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM transactions WHERE id = ' . $transactionId)->fetchColumn());
        self::assertSame('2026-03-15', (string)$this->db->query('SELECT movement_date FROM envelope_movements WHERE id = ' . $expenseId)->fetchColumn());
        self::assertEqualsWithDelta(600, envelope_balance_as_of($this->db, $envelopeId, '2026-03-14'), 0.001);
        self::assertEqualsWithDelta(100, envelope_balance_as_of($this->db, $envelopeId, '2026-03-15'), 0.001);
    }

    private function insertEntry(int $monthId, int $envelopeId, float $amount): int
    {
        $stmt = $this->db->prepare("INSERT INTO entries (month_id, envelope_id, type, name, amount, status, due_date) VALUES (?, ?, 'expense', 'Allocation', ?, 'paid', '2025-02-05')");
        $stmt->execute([$monthId, $envelopeId, $amount]);
        return (int)$this->db->lastInsertId();
    }

    private function insertTransaction(int $monthId, ?int $entryId, int $envelopeId, float $amount, string $date): int
    {
        $stmt = $this->db->prepare('INSERT INTO transactions (month_id, entry_id, envelope_id, envelope, amount, note, spent_date) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$monthId, $entryId, $envelopeId, 'Source', $amount, 'Test expense', $date]);
        return (int)$this->db->lastInsertId();
    }

    private function insertMovement(int $envelopeId, ?int $monthId, string $type, float $amount, string $date, ?int $entryId = null, ?int $transactionId = null, ?int $transferId = null, ?int $reversalOf = null): int
    {
        $stmt = $this->db->prepare('INSERT INTO envelope_movements (envelope_id, month_id, entry_id, transaction_id, transfer_id, reversal_of_movement_id, type, amount, note, movement_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$envelopeId, $monthId, $entryId, $transactionId, $transferId, $reversalOf, $type, $amount, 'Test movement', $date]);
        return (int)$this->db->lastInsertId();
    }

    private function insertTransfer(int $sourceId, int $destinationId, float $amount, string $date): int
    {
        $stmt = $this->db->prepare("INSERT INTO envelope_transfers (source_envelope_id, destination_envelope_id, source_envelope, destination_envelope, amount, note, transfer_date) VALUES (?, ?, 'Source', 'Destination', ?, 'Test transfer', ?)");
        $stmt->execute([$sourceId, $destinationId, $amount, $date]);
        return (int)$this->db->lastInsertId();
    }
}
