<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseTestCase.php';

final class StatementRefundReconciliationTest extends DatabaseTestCase
{
    public function testRefundReconciliationCreatesAuthoritativeFullRefund(): void
    {
        $this->createMonth(2026, 8);
        $accountId = $this->createAccount('Refund account');
        $envelopeId = $this->createEnvelope('Envelope A');
        save_transaction($this->db, [
            'envelope_id' => $envelopeId,
            'payment_account_id' => $accountId,
            'amount' => 100,
            'spent_date' => '2026-08-04',
        ]);
        $rowId = $this->insertStatementRow($accountId, '2026-08-06', 100, 'DKK', 'Provider refund');

        $refundId = statement_reconciliation_create_refund($this->db, $rowId, [
            'envelope_id' => $envelopeId,
            'note' => 'Refund for grocery order',
        ]);

        $this->assertRefundEffects($refundId, $envelopeId, $accountId, 100, '2026-08-06');
        self::assertEqualsWithDelta(0, $this->envelopeBalance($envelopeId), 0.001);
        $report = get_report_data($this->db, '2026-08', '2026-08');
        self::assertEqualsWithDelta(0, $report['months'][0]['summary']['actual_spent'], 0.001);
        $state = statement_reconciliation_load_for_account($this->db, $accountId);
        self::assertCount(0, $state['unresolved']);
        self::assertSame('refund', $state['reconciled'][0]['target_kind']);
    }

    public function testPartialAndMultipleRefundsNetToExpectedSpending(): void
    {
        $this->createMonth(2026, 8);
        $accountId = $this->createAccount('Partial Refund account');
        $envelopeId = $this->createEnvelope('Subscriptions');
        save_transaction($this->db, ['envelope_id' => $envelopeId, 'payment_account_id' => $accountId, 'amount' => 100, 'spent_date' => '2026-08-04']);
        foreach ([[30, '2026-08-06'], [20, '2026-08-08']] as [$amount, $date]) {
            $rowId = $this->insertStatementRow($accountId, $date, $amount, 'DKK', 'Partial refund');
            statement_reconciliation_create_refund($this->db, $rowId, ['envelope_id' => $envelopeId]);
        }

        $report = get_report_data($this->db, '2026-08', '2026-08');
        self::assertEqualsWithDelta(50, $report['months'][0]['summary']['actual_spent'], 0.001);
        self::assertEqualsWithDelta(-50, $this->envelopeBalance($envelopeId), 0.001);
    }

    public function testRefundEditPreservesKindAcrossAmountDateEnvelopeAndAccountChanges(): void
    {
        $this->createMonth(2026, 8);
        $accountA = $this->createAccount('Edit Account A');
        $accountB = $this->createAccount('Edit Account B');
        $envelopeA = $this->createEnvelope('Edit Envelope A');
        $envelopeB = $this->createEnvelope('Edit Envelope B');
        $refundId = save_transaction($this->db, ['envelope_id' => $envelopeA, 'payment_account_id' => $accountA, 'amount' => 40, 'spent_date' => '2026-08-05', 'kind' => 'refund']);

        // UI-shaped updates intentionally omit kind. The domain must preserve the stored semantic.
        save_transaction($this->db, $this->editPayload($envelopeA, $accountA, 55, '2026-08-05'), $refundId);
        $this->assertRefundEffects($refundId, $envelopeA, $accountA, 55, '2026-08-05');
        save_transaction($this->db, $this->editPayload($envelopeA, $accountA, 55, '2026-08-09'), $refundId);
        $this->assertRefundEffects($refundId, $envelopeA, $accountA, 55, '2026-08-09');
        save_transaction($this->db, $this->editPayload($envelopeB, $accountA, 55, '2026-08-09'), $refundId);
        $this->assertRefundEffects($refundId, $envelopeB, $accountA, 55, '2026-08-09');
        save_transaction($this->db, $this->editPayload($envelopeB, $accountB, 55, '2026-08-09'), $refundId);
        $this->assertRefundEffects($refundId, $envelopeB, $accountB, 55, '2026-08-09');
        self::assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM envelope_movements WHERE transaction_id=$refundId AND envelope_id=$envelopeA")->fetchColumn());
        self::assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM account_movements WHERE event_id=(SELECT id FROM account_events WHERE transaction_id=$refundId) AND account_id=$accountA")->fetchColumn());
    }

    public function testExplicitExpenseRefundTransitionsUpdateSignsAndAccountSides(): void
    {
        $accountId = $this->createAccount('Transition account');
        $envelopeId = $this->createEnvelope('Transition envelope');
        $transactionId = save_transaction($this->db, $this->editPayload($envelopeId, $accountId, 25, '2026-08-10'));

        $refund = $this->editPayload($envelopeId, $accountId, 25, '2026-08-10') + ['kind' => 'refund'];
        save_transaction($this->db, $refund, $transactionId);
        $this->assertRefundEffects($transactionId, $envelopeId, $accountId, 25, '2026-08-10');

        $expense = $this->editPayload($envelopeId, $accountId, 25, '2026-08-10') + ['kind' => 'expense'];
        save_transaction($this->db, $expense, $transactionId);
        $movement = $this->db->query("SELECT type,amount FROM envelope_movements WHERE transaction_id=$transactionId")->fetch();
        $event = $this->db->query("SELECT event_type,source_account_id,destination_account_id FROM account_events WHERE transaction_id=$transactionId")->fetch();
        self::assertSame('expense', $movement['type']);
        self::assertEqualsWithDelta(-25, (float)$movement['amount'], 0.001);
        self::assertSame('expense', $event['event_type']);
        self::assertSame($accountId, (int)$event['source_account_id']);
        self::assertNull($event['destination_account_id']);
        self::assertEqualsWithDelta(-25, (float)$this->db->query("SELECT amount FROM account_movements WHERE event_id=(SELECT id FROM account_events WHERE transaction_id=$transactionId)")->fetchColumn(), 0.001);
    }

    public function testRefundCreationBindsAmountCurrencyAndAccountToEvidenceAcrossEnvelopePreferences(): void
    {
        save_month($this->db, ['year' => 2026, 'month' => 8, 'exchange_rate' => 7.5]);
        $accountId = $this->createAccount('Evidence account');
        $otherAccountId = $this->createAccount('Unrelated account');
        $helperEnvelope = $this->createEnvelope('Helper-preferred', 'daily', 'HELPER_CURRENCY');
        $currencyEnvelope = $this->createEnvelope('Currency-preferred', 'daily', 'CURRENCY');

        $dkkRow = $this->insertStatementRow($accountId, '2026-08-11', 40, 'DKK', 'DKK refund');
        $dkkRefund = statement_reconciliation_create_refund($this->db, $dkkRow, [
            'envelope_id' => $helperEnvelope,
            'amount' => 999,
            'currency' => 'EUR',
            'payment_account_id' => $otherAccountId,
        ]);
        $dkkTx = $this->db->query("SELECT * FROM transactions WHERE id=$dkkRefund")->fetch();
        self::assertEqualsWithDelta(40, (float)$dkkTx['amount'], 0.001);
        self::assertSame($accountId, (int)$dkkTx['payment_account_id']);

        $eurRow = $this->insertStatementRow($accountId, '2026-08-12', 10, 'EUR', 'EUR refund');
        $eurRefund = statement_reconciliation_create_refund($this->db, $eurRow, ['envelope_id' => $currencyEnvelope]);
        $eurTx = $this->db->query("SELECT * FROM transactions WHERE id=$eurRefund")->fetch();
        self::assertEqualsWithDelta(75, (float)$eurTx['amount'], 0.001);
        self::assertSame($accountId, (int)$eurTx['payment_account_id']);
    }

    public function testRefundCreationRejectsNegativeEvidenceWithoutFinancialEffects(): void
    {
        $accountId = $this->createAccount('Outgoing account');
        $envelopeId = $this->createEnvelope('Outgoing envelope');
        $rowId = $this->insertStatementRow($accountId, '2026-08-13', -25, 'DKK', 'Outgoing payment');

        try {
            statement_reconciliation_create_refund($this->db, $rowId, ['envelope_id' => $envelopeId]);
            self::fail('Negative evidence must not create a Refund');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('incoming Refund', $error->getMessage());
        }
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM transactions')->fetchColumn());
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM statement_reconciliations')->fetchColumn());
    }

    public function testTransactionCandidatesRespectDirectionCurrencyAmountAndDeterministicKinds(): void
    {
        save_month($this->db, ['year' => 2026, 'month' => 8, 'exchange_rate' => 7.5]);
        $accountId = $this->createAccount('Candidate account');
        $otherId = $this->createAccount('Candidate other');
        $envelopeId = $this->createEnvelope('Candidates');
        $expenseId = save_transaction($this->db, ['envelope_id' => $envelopeId, 'payment_account_id' => $accountId, 'amount' => 75, 'spent_date' => '2026-08-14']);
        $refundId = save_transaction($this->db, ['envelope_id' => $envelopeId, 'payment_account_id' => $accountId, 'amount' => 75, 'spent_date' => '2026-08-14', 'kind' => 'refund']);
        $transferId = record_account_transfer($this->db, $otherId, $accountId, 75, '2026-08-14', 'Incoming transfer');

        $outgoingRow = $this->insertStatementRow($accountId, '2026-08-14', -10, 'EUR', 'Outgoing EUR');
        $outgoing = find_reconciliation_candidates($this->db, $outgoingRow)['candidates'];
        $outgoingTransactions = array_values(array_filter($outgoing, static fn(array $candidate): bool => $candidate['kind'] === 'transaction'));
        self::assertSame([$expenseId], array_column($outgoingTransactions, 'transaction_id'));
        self::assertSame('expense', $outgoingTransactions[0]['transaction_kind']);

        $incomingRow = $this->insertStatementRow($accountId, '2026-08-14', 10, 'EUR', 'Incoming EUR');
        $incoming = find_reconciliation_candidates($this->db, $incomingRow)['candidates'];
        self::assertSame(['transaction', 'account_event'], array_column($incoming, 'kind'));
        self::assertSame($refundId, $incoming[0]['transaction_id']);
        self::assertSame('refund', $incoming[0]['transaction_kind']);
        self::assertSame($transferId, $incoming[1]['account_event_id']);
    }

    public function testTransactionConfirmationRevalidatesCandidateRules(): void
    {
        $accountId = $this->createAccount('Confirm account');
        $otherAccountId = $this->createAccount('Confirm other');
        $envelopeId = $this->createEnvelope('Confirm envelope');
        $validRefund = save_transaction($this->db, ['envelope_id' => $envelopeId, 'payment_account_id' => $accountId, 'amount' => 30, 'spent_date' => '2026-08-15', 'kind' => 'refund']);
        $wrongDirection = save_transaction($this->db, ['envelope_id' => $envelopeId, 'payment_account_id' => $accountId, 'amount' => 30, 'spent_date' => '2026-08-15']);
        // The two Refunds are deliberately identical records on different
        // Accounts, so the second one is created with the duplicate
        // confirmation.
        $wrongAccount = save_transaction($this->db, ['envelope_id' => $envelopeId, 'payment_account_id' => $otherAccountId, 'amount' => 30, 'spent_date' => '2026-08-15', 'kind' => 'refund'], null, false, false, true);
        $rowId = $this->insertStatementRow($accountId, '2026-08-15', 30, 'DKK', 'Incoming');

        foreach ([$wrongDirection, $wrongAccount] as $invalidId) {
            try {
                statement_reconciliation_assert_transaction_candidate($this->db, $rowId, $invalidId);
                self::fail('Incompatible target was accepted');
            } catch (InvalidArgumentException $error) {
                self::assertStringContainsString('not a compatible', $error->getMessage());
            }
        }
        statement_reconciliation_assert_transaction_candidate($this->db, $rowId, $validRefund);
        statement_reconciliation_link_insert($this->db, $rowId, $validRefund);
        self::assertSame($validRefund, (int)$this->db->query("SELECT transaction_id FROM statement_reconciliations WHERE statement_row_id=$rowId")->fetchColumn());
    }

    public function testRemoveLinkPreservesRefundAndDeleteRemovesOnlyRefundTruthAndProvenance(): void
    {
        $accountId = $this->createAccount('Delete account');
        $envelopeId = $this->createEnvelope('Delete envelope');
        $unrelatedId = save_transaction($this->db, ['envelope_id' => $envelopeId, 'payment_account_id' => $accountId, 'amount' => 10, 'spent_date' => '2026-08-16']);
        $rowId = $this->insertStatementRow($accountId, '2026-08-17', 25, 'DKK', 'Refund to delete');
        $refundId = statement_reconciliation_create_refund($this->db, $rowId, ['envelope_id' => $envelopeId]);
        $linkId = (int)$this->db->query("SELECT id FROM statement_reconciliations WHERE statement_row_id=$rowId")->fetchColumn();

        statement_reconciliation_remove_link($this->db, $linkId);
        self::assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM transactions WHERE id=$refundId")->fetchColumn());
        statement_reconciliation_link_insert($this->db, $rowId, $refundId);
        delete_transaction($this->db, $refundId);

        self::assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM transactions WHERE id=$refundId")->fetchColumn());
        self::assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM envelope_movements WHERE transaction_id=$refundId")->fetchColumn());
        self::assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM account_events WHERE transaction_id=$refundId")->fetchColumn());
        self::assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM statement_reconciliations WHERE statement_row_id=$rowId")->fetchColumn());
        self::assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM statement_rows WHERE id=$rowId")->fetchColumn());
        self::assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM transactions WHERE id=$unrelatedId")->fetchColumn());
        self::assertCount(1, statement_reconciliation_load_for_account($this->db, $accountId)['unresolved']);
    }

    public function testLiabilityRefundUsesTheSamePositiveReceivingMovement(): void
    {
        $liabilityId = save_account_definition($this->db, [
            'name' => 'Liability account', 'account_number' => 'TEST-LIABILITY-REFUND', 'currency' => 'CURRENCY',
        ]);
        record_account_balance_observation($this->db, $liabilityId, -1000, '2026-01-01');
        $envelopeId = $this->createEnvelope('Liability envelope');
        save_transaction($this->db, ['envelope_id' => $envelopeId, 'payment_account_id' => $liabilityId, 'amount' => 100, 'spent_date' => '2026-08-18']);
        self::assertEqualsWithDelta(-1100, account_balance_as_of($this->db, $liabilityId, '2026-08-18'), 0.001);
        $refundId = save_transaction($this->db, ['envelope_id' => $envelopeId, 'payment_account_id' => $liabilityId, 'amount' => 40, 'spent_date' => '2026-08-19', 'kind' => 'refund']);
        self::assertEqualsWithDelta(-1060, account_balance_as_of($this->db, $liabilityId, '2026-08-19'), 0.001);
        self::assertEqualsWithDelta(40, (float)$this->db->query("SELECT amount FROM account_movements WHERE event_id=(SELECT id FROM account_events WHERE transaction_id=$refundId)")->fetchColumn(), 0.001);
    }

    public function testCrossMonthRefundRemainsInItsOwnMonthAndNetsRange(): void
    {
        $july = $this->createMonth(2026, 7);
        $august = $this->createMonth(2026, 8);
        $accountId = save_account_definition($this->db, [
            'name' => 'Cross-month account', 'account_number' => 'TEST-CROSS-MONTH', 'currency' => 'CURRENCY',
        ]);
        record_account_balance_observation($this->db, $accountId, 1000, '2026-01-01');
        $envelopeId = $this->createEnvelope('Cross-month envelope');
        save_transaction($this->db, ['month_id' => $july, 'envelope_id' => $envelopeId, 'payment_account_id' => $accountId, 'amount' => 100, 'spent_date' => '2026-07-20']);
        save_transaction($this->db, ['month_id' => $august, 'envelope_id' => $envelopeId, 'payment_account_id' => $accountId, 'amount' => 40, 'spent_date' => '2026-08-03', 'kind' => 'refund']);

        $report = get_report_data($this->db, '2026-07', '2026-08');
        self::assertEqualsWithDelta(100, $report['months'][0]['summary']['actual_spent'], 0.001);
        self::assertEqualsWithDelta(-40, $report['months'][1]['summary']['actual_spent'], 0.001);
        self::assertEqualsWithDelta(60, array_sum(array_column(array_column($report['months'], 'summary'), 'actual_spent')), 0.001);
        self::assertEqualsWithDelta(-60, $this->envelopeBalance($envelopeId), 0.001);
        self::assertEqualsWithDelta(940, account_balance_as_of($this->db, $accountId, '2026-08-31'), 0.001);
    }

    public function testReportSummaryPreservesNegativeNetSpending(): void
    {
        $monthId = $this->createMonth(2026, 8);
        $envelopeId = $this->createEnvelope('Net report');
        save_transaction($this->db, ['month_id' => $monthId, 'envelope_id' => $envelopeId, 'amount' => 100, 'spent_date' => '2026-08-01']);
        save_transaction($this->db, ['month_id' => $monthId, 'envelope_id' => $envelopeId, 'amount' => 40, 'spent_date' => '2026-08-02', 'kind' => 'refund']);
        self::assertEqualsWithDelta(60, get_report_data($this->db, '2026-08', '2026-08')['months'][0]['summary']['actual_spent'], 0.001);
        save_transaction($this->db, ['month_id' => $monthId, 'envelope_id' => $envelopeId, 'amount' => 60, 'spent_date' => '2026-08-03', 'kind' => 'refund']);
        self::assertEqualsWithDelta(0, get_report_data($this->db, '2026-08', '2026-08')['months'][0]['summary']['actual_spent'], 0.001);
        save_transaction($this->db, ['month_id' => $monthId, 'envelope_id' => $envelopeId, 'amount' => 50, 'spent_date' => '2026-08-04', 'kind' => 'refund']);
        self::assertEqualsWithDelta(-50, get_report_data($this->db, '2026-08', '2026-08')['months'][0]['summary']['actual_spent'], 0.001);
    }

    public function testReconciliationMutualExclusionAndSchemaChecksRemainEnforced(): void
    {
        $accountId = $this->createAccount('Schema account');
        $envelopeId = $this->createEnvelope('Schema envelope');
        $rowId = $this->insertStatementRow($accountId, '2026-08-20', 50, 'DKK', 'Inflow');
        statement_reconciliation_mark_no_fin_action($this->db, $rowId);
        try {
            statement_reconciliation_create_refund($this->db, $rowId, ['envelope_id' => $envelopeId]);
            self::fail('Existing disposition must reject Refund');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('already has', $error->getMessage());
        }

        $transactionId = save_transaction($this->db, ['envelope_id' => $envelopeId, 'payment_account_id' => $accountId, 'amount' => 30, 'spent_date' => '2026-08-20', 'kind' => 'refund']);
        foreach ([
            "UPDATE transactions SET kind='mixed' WHERE id=$transactionId",
            "INSERT INTO account_events (event_type,event_date,amount,destination_account_id) VALUES ('other','2026-08-20',5,$accountId)",
            "INSERT INTO envelope_movements (envelope_id,transaction_id,type,amount,movement_date) VALUES ($envelopeId,$transactionId,'expense',-30,'2026-08-20')",
        ] as $sql) {
            try {
                $this->db->exec($sql);
                self::fail('Expected CHECK rejection');
            } catch (PDOException $error) {
                self::assertMatchesRegularExpression('/CHECK|UNIQUE/', $error->getMessage());
            }
        }
    }

    private function assertRefundEffects(int $transactionId, int $envelopeId, int $accountId, float $amount, string $date): void
    {
        $transaction = $this->db->query("SELECT kind,envelope_id,payment_account_id,amount,spent_date FROM transactions WHERE id=$transactionId")->fetch();
        $movement = $this->db->query("SELECT type,envelope_id,amount,movement_date FROM envelope_movements WHERE transaction_id=$transactionId")->fetch();
        $event = $this->db->query("SELECT id,event_type,event_date,source_account_id,destination_account_id FROM account_events WHERE transaction_id=$transactionId")->fetch();
        $accountMovement = $this->db->query('SELECT account_id,amount,movement_date FROM account_movements WHERE event_id=' . (int)$event['id'])->fetch();
        self::assertSame('refund', $transaction['kind']);
        self::assertSame($envelopeId, (int)$transaction['envelope_id']);
        self::assertSame($accountId, (int)$transaction['payment_account_id']);
        self::assertEqualsWithDelta($amount, (float)$transaction['amount'], 0.001);
        self::assertSame($date, $transaction['spent_date']);
        self::assertSame('refund', $movement['type']);
        self::assertSame($envelopeId, (int)$movement['envelope_id']);
        self::assertEqualsWithDelta($amount, (float)$movement['amount'], 0.001);
        self::assertSame($date, $movement['movement_date']);
        self::assertSame('refund', $event['event_type']);
        self::assertNull($event['source_account_id']);
        self::assertSame($accountId, (int)$event['destination_account_id']);
        self::assertSame($accountId, (int)$accountMovement['account_id']);
        self::assertEqualsWithDelta($amount, (float)$accountMovement['amount'], 0.001);
        self::assertSame($date, $accountMovement['movement_date']);
    }

    private function editPayload(int $envelopeId, int $accountId, float $amount, string $date): array
    {
        return [
            'envelope_id' => $envelopeId,
            'payment_account_id' => $accountId,
            'amount' => $amount,
            'currency' => 'DKK',
            'spent_date' => $date,
        ];
    }

    private function createAccount(string $name, string $type = 'asset'): int
    {
        return save_account_definition($this->db, [
            'name' => $name,
            'account_number' => 'TEST-' . strtoupper(bin2hex(random_bytes(4))),
            'type' => $type,
            'currency' => 'CURRENCY',
            'active' => 1,
        ]);
    }

    private function insertStatementRow(int $accountId, string $bookingDate, float $amount, string $currency, string $description): int
    {
        $checksum = 'sha256:' . bin2hex(random_bytes(8));
        $import = $this->db->prepare("INSERT INTO statement_imports (account_id,provider,original_filename,file_checksum,statement_from,statement_to,parser_version,row_count,valid_row_count,problem_count) VALUES (?,'test','test.xlsx',?,?,?,1,1,1,0)");
        $import->execute([$accountId, $checksum, $bookingDate, $bookingDate]);
        $importId = (int)$this->db->lastInsertId();
        $stmt = $this->db->prepare("INSERT INTO statement_rows (import_id,account_id,provider,source_row_number,booking_date,value_date,amount,currency,description_original,description_normalized,row_fingerprint) VALUES (?,?,'test',1,?,?,?,?,?,?,?)");
        $stmt->execute([$importId, $accountId, $bookingDate, $bookingDate, $amount, $currency, $description, strtolower($description), hash('sha256', $checksum . $description)]);
        return (int)$this->db->lastInsertId();
    }
}
