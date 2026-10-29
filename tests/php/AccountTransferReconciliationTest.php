<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseTestCase.php';

final class AccountTransferReconciliationTest extends DatabaseTestCase
{
    public function testTransferWorksBetweenAnyAccountClassifications(): void
    {
        $asset = $this->account('Asset A', 'asset');
        $liability = $this->account('Liability B', 'liability');

        // asset -> liability
        $eventId1 = record_account_transfer($this->db, $asset, $liability, 100, '2026-09-05');
        self::assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM account_events WHERE id = $eventId1 AND event_type = 'transfer'")->fetchColumn());
        self::assertEqualsWithDelta(-100, account_balance_as_of($this->db, $asset, '2026-09-05'), 0.001);
        self::assertEqualsWithDelta(100, account_balance_as_of($this->db, $liability, '2026-09-05'), 0.001);

        // liability -> asset
        $eventId2 = record_account_transfer($this->db, $liability, $asset, 50, '2026-09-06');
        self::assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM account_events WHERE id = $eventId2 AND event_type = 'transfer'")->fetchColumn());
        // liability was at +100 (from event 1), now -50, net 50.
        self::assertEqualsWithDelta(50, account_balance_as_of($this->db, $liability, '2026-09-06'), 0.001);
        // asset was at -100 (from event 1), now +50, net -50.
        self::assertEqualsWithDelta(-50, account_balance_as_of($this->db, $asset, '2026-09-06'), 0.001);

        // liability -> liability
        $liability2 = $this->account('Liability C', 'liability');
        $eventId3 = record_account_transfer($this->db, $liability, $liability2, 25, '2026-09-07');
        self::assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM account_events WHERE id = $eventId3 AND event_type = 'transfer'")->fetchColumn());
    }

    public function testTransferRejectsSameSourceAndDestination(): void
    {
        $asset = $this->account('A', 'asset');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Choose two different accounts');
        record_account_transfer($this->db, $asset, $asset, 100, '2026-09-05');
    }

    public function testTransferRejectsInvalidAmountOrDate(): void
    {
        $asset = $this->account('A', 'asset');
        $liability = $this->account('L', 'liability');
        try {
            record_account_transfer($this->db, $asset, $liability, 0, '2026-09-05');
            self::fail('Zero amount should reject');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('Amount', $error->getMessage());
        }
        try {
            record_account_transfer($this->db, $asset, $liability, -50, '2026-09-05');
            self::fail('Negative amount should reject');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('Amount', $error->getMessage());
        }
        try {
            record_account_transfer($this->db, $asset, $liability, 100, 'not-a-date');
            self::fail('Bad date should reject');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('date', $error->getMessage());
        }
    }

    public function testTransferCreatesExactlyOneEventAndTwoMovements(): void
    {
        $asset = $this->account('Asset', 'asset');
        $liability = $this->account('Liability', 'liability');
        $beforeEvents = (int)$this->db->query('SELECT COUNT(*) FROM account_events')->fetchColumn();
        $beforeMovements = (int)$this->db->query('SELECT COUNT(*) FROM account_movements')->fetchColumn();

        $eventId = record_account_transfer($this->db, $asset, $liability, 200, '2026-09-10');

        self::assertSame($beforeEvents + 1, (int)$this->db->query('SELECT COUNT(*) FROM account_events')->fetchColumn());
        self::assertSame($beforeMovements + 2, (int)$this->db->query('SELECT COUNT(*) FROM account_movements')->fetchColumn());
        $row = $this->db->query("SELECT * FROM account_events WHERE id = $eventId")->fetch();
        self::assertSame('transfer', $row['event_type']);
        self::assertEqualsWithDelta(200, (float)$row['amount'], 0.001);
        self::assertSame($asset, (int)$row['source_account_id']);
        self::assertSame($liability, (int)$row['destination_account_id']);
    }

    public function testTransferMovementsAreSignedOppositeAndSumToZero(): void
    {
        $asset = $this->account('Asset', 'asset');
        $liability = $this->account('Liability', 'liability');
        $eventId = record_account_transfer($this->db, $asset, $liability, 150, '2026-09-10');

        $movements = $this->db->query("SELECT account_id, amount FROM account_movements WHERE event_id = $eventId ORDER BY sequence")->fetchAll();
        self::assertCount(2, $movements);
        self::assertEqualsWithDelta(-150, (float)$movements[0]['amount'], 0.001);
        self::assertEqualsWithDelta(150, (float)$movements[1]['amount'], 0.001);
        self::assertEqualsWithDelta(0, (float)$movements[0]['amount'] + (float)$movements[1]['amount'], 0.001);
    }

    public function testTransferEventTypeIsTransferRegardlessOfAccountClassification(): void
    {
        $asset = $this->account('A', 'asset');
        $liability = $this->account('L', 'liability');
        $liability2 = $this->account('L2', 'liability');
        $eventId1 = record_account_transfer($this->db, $asset, $liability, 100, '2026-09-10');
        $eventId2 = record_account_transfer($this->db, $liability, $asset, 50, '2026-09-11');
        $eventId3 = record_account_transfer($this->db, $liability, $liability2, 25, '2026-09-12');
        foreach ([$eventId1, $eventId2, $eventId3] as $eventId) {
            self::assertSame('transfer', (string)$this->db->query("SELECT event_type FROM account_events WHERE id = $eventId")->fetchColumn());
        }
        self::assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM account_events WHERE event_type NOT IN ('income','expense','refund','transfer')")->fetchColumn());
    }

    public function testSchemaRejectsObsoleteAccountToAccountEventTypes(): void
    {
        // DB v2.12 tightened the account_events.event_type CHECK to the canonical set.
        // Borrowing / repayment are no longer permitted labels.
        $this->expectException(PDOException::class);
        $this->db->exec("INSERT INTO account_events (event_type, event_date, amount, note) VALUES ('borrowing', '2026-09-10', 100, 'obsolete')");
    }

    public function testTransferAuthoritativeAmountIsCurrencyRegardlessOfAccountPresentation(): void
    {
        $currencyAccount = $this->account('Currency', 'asset', 'CURRENCY');
        $helperAccount = $this->account('Helper', 'asset', 'HELPER_CURRENCY');
        // Account Transfer input is authoritative Currency regardless of either presentation preference.
        $eventId = record_account_transfer($this->db, $currencyAccount, $helperAccount, 750, '2026-09-10');
        self::assertEqualsWithDelta(750, (float)$this->db->query("SELECT amount FROM account_events WHERE id = $eventId")->fetchColumn(), 0.001);
        self::assertEqualsWithDelta(-750, account_balance_as_of($this->db, $currencyAccount, '2026-09-10'), 0.001);
        self::assertEqualsWithDelta(750, account_balance_as_of($this->db, $helperAccount, '2026-09-10'), 0.001);
    }

    public function testTransferIsAllowedAtAnyDateWithoutBalanceKnowledge(): void
    {
        $a = save_account_definition($this->db, ['name' => 'A', 'account_number' => 'A1', 'currency' => 'CURRENCY']);
        $b = save_account_definition($this->db, ['name' => 'B', 'account_number' => 'B1', 'currency' => 'CURRENCY']);
        $eventId = record_account_transfer($this->db, $a, $b, 100, '2020-09-09');
        self::assertGreaterThan(0, $eventId);
        self::assertNull(account_balance_as_of($this->db, $a, effective_today()));
        self::assertNull(account_balance_as_of($this->db, $b, effective_today()));
    }

    public function testCandidateMatchingReturnsTransferForSameAccountSide(): void
    {
        $source = $this->account('Source', 'asset');
        $destination = $this->account('Destination', 'asset');
        $eventId = record_account_transfer($this->db, $source, $destination, 250, '2026-09-05');

        $rowId = $this->insertStatementRow($source, '2026-09-05', -250.0, 'DKK', 'Transfer to destination');

        $candidates = find_reconciliation_candidates($this->db, $rowId)['candidates'];
        $transferCandidates = array_values(array_filter($candidates, fn(array $c): bool => ($c['kind'] ?? null) === 'account_event'));
        self::assertCount(1, $transferCandidates);
        self::assertSame($eventId, (int)$transferCandidates[0]['account_event_id']);
        self::assertSame($source, (int)$transferCandidates[0]['source_account_id']);
        self::assertSame($destination, (int)$transferCandidates[0]['destination_account_id']);
    }

    public function testCandidateMatchingReturnsTransferForOppositeStatementSide(): void
    {
        $source = $this->account('Source', 'asset');
        $destination = $this->account('Destination', 'asset');
        $eventId = record_account_transfer($this->db, $source, $destination, 250, '2026-09-05');

        // Positive statement on destination side.
        $rowId = $this->insertStatementRow($destination, '2026-09-05', 250.0, 'DKK', 'Transfer from source');
        $candidates = find_reconciliation_candidates($this->db, $rowId)['candidates'];
        $transferCandidates = array_values(array_filter($candidates, fn(array $c): bool => ($c['kind'] ?? null) === 'account_event'));
        self::assertCount(1, $transferCandidates);
        self::assertSame($eventId, (int)$transferCandidates[0]['account_event_id']);
    }

    public function testCandidateMatchingRejectsWrongAccountIdentity(): void
    {
        $source = $this->account('Source', 'asset');
        $destination = $this->account('Destination', 'asset');
        $unrelated = $this->account('Unrelated', 'asset');
        record_account_transfer($this->db, $source, $destination, 250, '2026-09-05');

        $rowId = $this->insertStatementRow($unrelated, '2026-09-05', -250.0, 'DKK', 'Unrelated');
        $candidates = find_reconciliation_candidates($this->db, $rowId)['candidates'];
        $transferCandidates = array_values(array_filter($candidates, fn(array $c): bool => ($c['kind'] ?? null) === 'account_event'));
        self::assertCount(0, $transferCandidates);
    }

    public function testCandidateMatchingRejectsWrongAmount(): void
    {
        $source = $this->account('Source', 'asset');
        $destination = $this->account('Destination', 'asset');
        record_account_transfer($this->db, $source, $destination, 250, '2026-09-05');

        $rowId = $this->insertStatementRow($source, '2026-09-05', -300.0, 'DKK', 'Wrong amount');
        $candidates = find_reconciliation_candidates($this->db, $rowId)['candidates'];
        $transferCandidates = array_values(array_filter($candidates, fn(array $c): bool => ($c['kind'] ?? null) === 'account_event'));
        self::assertCount(0, $transferCandidates);
    }

    public function testCandidateMatchingRejectsOutsideDateWindow(): void
    {
        $source = $this->account('Source', 'asset');
        $destination = $this->account('Destination', 'asset');
        record_account_transfer($this->db, $source, $destination, 250, '2026-09-05');

        $rowId = $this->insertStatementRow($source, '2026-09-15', -250.0, 'DKK', 'Too late');
        $candidates = find_reconciliation_candidates($this->db, $rowId)['candidates'];
        $transferCandidates = array_values(array_filter($candidates, fn(array $c): bool => ($c['kind'] ?? null) === 'account_event'));
        self::assertCount(0, $transferCandidates);
    }

    public function testCandidateMatchingConvertsHelperStatementToCurrencyBeforeComparison(): void
    {
        $source = $this->account('Source', 'asset', 'CURRENCY');
        $destination = $this->account('Destination', 'asset', 'HELPER_CURRENCY');
        $this->createMonthWithRate(2026, 9, 7.50); // rate 7.5
        // Transfer authoritative amount is 375 (Currency). Destination Account happens to be EUR; this
        // represents an EUR-denominated transfer stored as 375 Currency units.
        $eventId = record_account_transfer($this->db, $source, $destination, 375, '2026-09-05');

        // Statement on source side in EUR (Helper), 50 EUR * 7.5 = 375 Currency.
        $rowId = $this->insertStatementRow($source, '2026-09-05', -50.0, 'EUR', 'EUR transfer');

        $candidates = find_reconciliation_candidates($this->db, $rowId)['candidates'];
        $transferCandidates = array_values(array_filter($candidates, fn(array $c): bool => ($c['kind'] ?? null) === 'account_event'));
        self::assertCount(1, $transferCandidates);
        self::assertSame($eventId, (int)$transferCandidates[0]['account_event_id']);
    }

    public function testCandidateMatchingIsIndependentOfOtherAccountCurrency(): void
    {
        $source = $this->account('Source', 'asset', 'CURRENCY');
        $destination = $this->account('Destination', 'asset', 'HELPER_CURRENCY');
        $eventId = record_account_transfer($this->db, $source, $destination, 250, '2026-09-05');
        // statement on source side in DKK (Currency), 250. The other side's EUR currency must NOT
        // exclude the candidate.
        $rowId = $this->insertStatementRow($source, '2026-09-05', -250.0, 'DKK', 'Mixed');
        $candidates = find_reconciliation_candidates($this->db, $rowId)['candidates'];
        $transferCandidates = array_values(array_filter($candidates, fn(array $c): bool => ($c['kind'] ?? null) === 'account_event'));
        self::assertCount(1, $transferCandidates);
        self::assertSame($eventId, (int)$transferCandidates[0]['account_event_id']);
    }

    public function testCandidateMatchingRejectsOppositeSignedDirection(): void
    {
        $source = $this->account('Source', 'asset');
        $destination = $this->account('Destination', 'asset');
        record_account_transfer($this->db, $source, $destination, 250, '2026-09-05');
        // Statement is positive (inflow) on source side. That cannot match a Transfer whose source-side
        // movement is negative.
        $rowId = $this->insertStatementRow($source, '2026-09-05', 250.0, 'DKK', 'Wrong sign');
        $candidates = find_reconciliation_candidates($this->db, $rowId)['candidates'];
        $transferCandidates = array_values(array_filter($candidates, fn(array $c): bool => ($c['kind'] ?? null) === 'account_event'));
        self::assertCount(0, $transferCandidates);
    }

    public function testLinkInsertEnforcesExactlyOneOfTransactionOrAccountEvent(): void
    {
        $source = $this->account('Source', 'asset');
        $destination = $this->account('Destination', 'asset');
        $eventId = record_account_transfer($this->db, $source, $destination, 100, '2026-09-05');
        $rowId = $this->insertStatementRow($source, '2026-09-05', -100.0, 'DKK', 'Match');

        // Valid: account_event only
        $linkId1 = statement_reconciliation_link_insert($this->db, $rowId, null, $eventId);
        self::assertGreaterThan(0, $linkId1);
        $row1 = $this->db->query("SELECT * FROM statement_reconciliations WHERE id = $linkId1")->fetch();
        self::assertNull($row1['transaction_id']);
        self::assertSame($eventId, (int)$row1['target_account_event_id']);

        // Insert both → throws
        $rowId2 = $this->insertStatementRow($source, '2026-09-05', -100.0, 'DKK', 'Both');
        try {
            statement_reconciliation_link_insert($this->db, $rowId2, 1, $eventId);
            self::fail('Both targets should reject');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('never both', $error->getMessage());
        }

        // Insert neither → throws
        try {
            statement_reconciliation_link_insert($this->db, $rowId2, null, null);
            self::fail('Neither target should reject');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('requires', $error->getMessage());
        }
    }

    public function testLinkInsertRejectsUnknownTargets(): void
    {
        $rowId = $this->insertStatementRow($this->account('Source', 'asset'), '2026-09-05', -100.0, 'DKK', 'X');
        try {
            statement_reconciliation_link_insert($this->db, $rowId, 999999, null);
            self::fail('Unknown transaction should reject');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('Transaction', $error->getMessage());
        }
        try {
            statement_reconciliation_link_insert($this->db, $rowId, null, 999999);
            self::fail('Unknown account event should reject');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('Account event', $error->getMessage());
        }
    }

    public function testBothStatementSidesCanLinkToSameTransfer(): void
    {
        $source = $this->account('Source', 'asset');
        $destination = $this->account('Destination', 'asset');
        $eventId = record_account_transfer($this->db, $source, $destination, 250, '2026-09-05');

        $rowSourceId = $this->insertStatementRow($source, '2026-09-05', -250.0, 'DKK', 'Out');
        $rowDestId = $this->insertStatementRow($destination, '2026-09-05', 250.0, 'DKK', 'In');

        $link1 = statement_reconciliation_link_insert($this->db, $rowSourceId, null, $eventId);
        $link2 = statement_reconciliation_link_insert($this->db, $rowDestId, null, $eventId);

        self::assertGreaterThan(0, $link1);
        self::assertGreaterThan(0, $link2);
        $count = (int)$this->db->query("SELECT COUNT(*) FROM statement_reconciliations WHERE target_account_event_id = $eventId")->fetchColumn();
        self::assertSame(2, $count);
        // Same Transfer is unchanged.
        self::assertEqualsWithDelta(-250, account_balance_as_of($this->db, $source, '2026-09-05'), 0.001);
        self::assertEqualsWithDelta(250, account_balance_as_of($this->db, $destination, '2026-09-05'), 0.001);
    }

    public function testRemovingTransferLinkLeavesTransferIntact(): void
    {
        $source = $this->account('Source', 'asset');
        $destination = $this->account('Destination', 'asset');
        $eventId = record_account_transfer($this->db, $source, $destination, 250, '2026-09-05');
        $rowId = $this->insertStatementRow($source, '2026-09-05', -250.0, 'DKK', 'Out');
        $linkId = statement_reconciliation_link_insert($this->db, $rowId, null, $eventId);

        statement_reconciliation_remove_link($this->db, $linkId);

        self::assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM statement_reconciliations WHERE id = $linkId")->fetchColumn());
        self::assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM account_events WHERE id = $eventId AND event_type='transfer'")->fetchColumn());
        self::assertSame(2, (int)$this->db->query("SELECT COUNT(*) FROM account_movements WHERE event_id = $eventId")->fetchColumn());
        self::assertEqualsWithDelta(-250, account_balance_as_of($this->db, $source, '2026-09-05'), 0.001);
        self::assertEqualsWithDelta(250, account_balance_as_of($this->db, $destination, '2026-09-05'), 0.001);
    }

    public function testStatementRowCannotSimultaneouslyTargetTransactionAndTransfer(): void
    {
        $source = $this->account('Source', 'asset');
        $destination = $this->account('Destination', 'asset');
        $eventId = record_account_transfer($this->db, $source, $destination, 100, '2026-09-05');
        $rowId = $this->insertStatementRow($source, '2026-09-05', -100.0, 'DKK', 'One');
        // Existing transaction-side link must NOT coexist with a Transfer-side link.
        $envelope = $this->createEnvelope('Envelope A');
        $transactionId = save_transaction($this->db, ['month_id' => null, 'envelope_id' => $envelope, 'payment_account_id' => $source, 'amount' => 100, 'spent_date' => '2026-09-05']);
        statement_reconciliation_link_insert($this->db, $rowId, $transactionId, null);
        try {
            statement_reconciliation_link_insert($this->db, $rowId, null, $eventId);
            self::fail('Same row cannot link to both');
        } catch (Throwable $error) {
            // Either the UNIQUE(statement_row_id) constraint or our application-layer guard rejects.
            self::assertNotEmpty($error->getMessage());
        }
    }

    public function testReconciliationLoadReturnsTransferAndTransactionShapedReconciledRows(): void
    {
        $source = $this->account('Source', 'asset');
        $destination = $this->account('Destination', 'asset');
        $eventId = record_account_transfer($this->db, $source, $destination, 250, '2026-09-05');
        $rowTransferId = $this->insertStatementRow($source, '2026-09-05', -250.0, 'DKK', 'Transfer row');
        statement_reconciliation_link_insert($this->db, $rowTransferId, null, $eventId);

        $rowTxnId = $this->insertStatementRow($source, '2026-09-06', -100.0, 'DKK', 'Expense row');
        $envelope = $this->createEnvelope('Envelope A');
        $txId = save_transaction($this->db, ['month_id' => null, 'envelope_id' => $envelope, 'payment_account_id' => $source, 'amount' => 100, 'spent_date' => '2026-09-06']);
        statement_reconciliation_link_insert($this->db, $rowTxnId, $txId, null);

        $state = statement_reconciliation_load_for_account($this->db, $source);
        self::assertCount(2, $state['reconciled']);
        $byKind = [];
        foreach ($state['reconciled'] as $row) {
            $byKind[$row['target_kind']] = $row;
        }
        self::assertArrayHasKey('account_event', $byKind);
        self::assertArrayHasKey('transaction', $byKind);
        self::assertSame($eventId, (int)$byKind['account_event']['target_id']);
        self::assertSame($txId, (int)$byKind['transaction']['target_id']);
        self::assertSame($source, (int)$byKind['account_event']['event_source_account_id']);
        self::assertSame($destination, (int)$byKind['account_event']['event_destination_account_id']);
    }

    public function testReconciledStateKeepsEvidenceAmountDistinctFromTargetAmount(): void
    {
        $source = $this->account('Evidence source', 'asset');
        $envelope = $this->createEnvelope('Evidence envelope');
        $this->createMonthWithRate(2026, 9, 7.5);
        $transactionId = save_transaction($this->db, [
            'envelope_id' => $envelope,
            'payment_account_id' => $source,
            'amount' => 75,
            'spent_date' => '2026-09-05',
        ]);
        $rowId = $this->insertStatementRow($source, '2026-09-05', -10, 'EUR', 'Distinct evidence amount');
        statement_reconciliation_link_insert($this->db, $rowId, $transactionId);

        $row = statement_reconciliation_load_for_account($this->db, $source)['reconciled'][0];
        self::assertArrayNotHasKey('amount', $row);
        self::assertSame(-10.0, (float)$row['evidence_amount'], 'The evidence amount must not be overwritten by the linked Currency amount.');
        self::assertSame(75.0, (float)$row['target_amount']);
        self::assertSame('EUR', $row['currency']);
    }

    public function testCreateTransferFromHelperStatementConvertsToCurrency(): void
    {
        $source = $this->account('Source', 'asset', 'CURRENCY');
        $destination = $this->account('Destination', 'asset', 'HELPER_CURRENCY');
        $this->createMonthWithRate(2026, 9, 7.50);
        $rowId = $this->insertStatementRow($source, '2026-09-05', -50.0, 'EUR', 'EUR transfer');

        // Simulate the server-side createReconciliationTransfer logic: convert statement amount
        // using Month exchange rate and call record_account_transfer.
        $currencyAmount = statement_reconciliation_amount_in_currency($this->db, 50.0, 'EUR', '2026-09-05');
        self::assertEqualsWithDelta(375.0, $currencyAmount, 0.001);
        $eventId = record_account_transfer($this->db, $source, $destination, $currencyAmount, '2026-09-05', 'EUR transfer');
        statement_reconciliation_link_insert($this->db, $rowId, null, $eventId);

        $row = $this->db->query("SELECT * FROM account_events WHERE id = $eventId")->fetch();
        self::assertSame('transfer', $row['event_type']);
        self::assertEqualsWithDelta(375, (float)$row['amount'], 0.001);
        self::assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM statement_reconciliations WHERE target_account_event_id = $eventId")->fetchColumn());
    }

    public function testExistingOrdinaryExpenseReconciliationStillWorksAfterSchemaExtension(): void
    {
        $source = $this->account('Source', 'asset');
        $envelope = $this->createEnvelope('Envelope A');
        $this->createMonth(2026, 9);
        $txId = save_transaction($this->db, ['month_id' => $this->db->query('SELECT id FROM months LIMIT 1')->fetchColumn(), 'envelope_id' => $envelope, 'payment_account_id' => $source, 'amount' => 100, 'spent_date' => '2026-09-05']);
        $rowId = $this->insertStatementRow($source, '2026-09-05', -100.0, 'DKK', 'Expense');

        $linkId = statement_reconciliation_link_insert($this->db, $rowId, $txId, null);
        self::assertGreaterThan(0, $linkId);
        $row = $this->db->query("SELECT * FROM statement_reconciliations WHERE id = $linkId")->fetch();
        self::assertSame($txId, (int)$row['transaction_id']);
        self::assertNull($row['target_account_event_id']);

        // Removing the link leaves the transaction untouched.
        statement_reconciliation_remove_link($this->db, $linkId);
        self::assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM transactions WHERE id = $txId")->fetchColumn());
    }

    public function testStatementImportRemainsFinanciallyInertAfterTransferSchemaExtension(): void
    {
        $source = $this->account('Source', 'asset');
        // Import path: insert a row, but do not call any save_transaction or record_account_*. Counts must not change.
        $rowId = $this->insertStatementRow($source, '2026-09-05', -100.0, 'DKK', 'Inert');
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM transactions')->fetchColumn());
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM account_events')->fetchColumn());
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM account_movements')->fetchColumn());
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM statement_reconciliations')->fetchColumn());
        self::assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM statement_rows WHERE id = $rowId")->fetchColumn());
    }

    private function account(string $name, string $type, string $currency = 'CURRENCY'): int
    {
        $accountId = save_account_definition($this->db, [
            'name' => $name,
            'account_number' => 'TEST-' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $name)),
            'currency' => $currency,
        ]);
        record_account_balance_observation($this->db, $accountId, 0, '2026-01-01', 'Synthetic zero Balance');
        return $accountId;
    }

    protected function createMonthWithRate(int $year, int $month, float $rate = 7.46): int
    {
        return save_month($this->db, ['year' => $year, 'month' => $month, 'exchange_rate' => $rate, 'salary' => 30000]);
    }

    private function insertStatementRow(int $accountId, string $bookingDate, float $amount, string $currency, string $description): int
    {
        $checksum = 'sha256:' . bin2hex(random_bytes(16));
        $importId = (int)$this->db->query("INSERT INTO statement_imports (account_id, provider, original_filename, file_checksum, statement_from, statement_to, parser_version, row_count, valid_row_count, problem_count) VALUES ($accountId, 'evidence:alpha', 'test.xlsx', '$checksum', '$bookingDate', '$bookingDate', 1, 1, 1, 0) RETURNING id")->fetchColumn();
        $stmt = $this->db->prepare('INSERT INTO statement_rows (import_id, account_id, provider, source_row_number, booking_date, value_date, amount, currency, description_original, description_normalized, row_fingerprint) VALUES (:import_id, :account_id, :provider, :source_row_number, :booking_date, :value_date, :amount, :currency, :description_original, :description_normalized, :row_fingerprint)');
        $stmt->execute([
            ':import_id' => $importId,
            ':account_id' => $accountId,
            ':provider' => 'evidence:alpha',
            ':source_row_number' => 1,
            ':booking_date' => $bookingDate,
            ':value_date' => $bookingDate,
            ':amount' => $amount,
            ':currency' => $currency,
            ':description_original' => $description,
            ':description_normalized' => strtolower($description),
            ':row_fingerprint' => hash('sha256', $importId . '|' . $accountId . '|' . $bookingDate . '|' . $amount . '|' . $currency . '|' . $description),
        ]);
        return (int)$this->db->lastInsertId();
    }
}
