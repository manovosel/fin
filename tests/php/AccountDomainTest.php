<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseTestCase.php';

final class AccountDomainTest extends DatabaseTestCase
{
    public function testOnlyOrdinaryFinancialEventTypesAreSupported(): void
    {
        foreach (['opening', 'reconciliation'] as $removedType) {
            try {
                account_event_effects($removedType, 100, null, ['id' => 1]);
                self::fail("$removedType must not remain an Account event type");
            } catch (InvalidArgumentException $error) {
                self::assertSame('Unsupported account event type', $error->getMessage());
            }
        }
        self::assertSame(['income', 'expense', 'refund', 'transfer'], FIN_ACCOUNT_EVENT_TYPES);
    }

    public function testTransferMovesCashWithoutIncomeOrExpense(): void
    {
        $result = account_event_effects('transfer', 5000, ['id' => 1], ['id' => 2]);
        self::assertSame([['account_id' => 1, 'amount' => -5000.0], ['account_id' => 2, 'amount' => 5000.0]], $result['movements']);
        self::assertSame(0.0, $result['income']);
        self::assertSame(0.0, $result['actual_expense']);
        self::assertSame(0.0, $result['net_position_change']);
    }

    public function testAccountActivityIsAllowedWithoutBalanceKnowledgeAtAnyDate(): void
    {
        $account = $this->account('Unknown Account');
        record_account_event($this->db, 'income', 1000, '2024-01-01', null, $account);
        record_account_event($this->db, 'expense', 50, '2023-12-31', $account, null);

        self::assertNull(account_balance_as_of($this->db, $account, '2026-09-11'));
        self::assertSame(2, (int)$this->db->query('SELECT COUNT(*) FROM account_movements')->fetchColumn());
    }

    public function testObservationPlusStrictlyLaterMovementsDeterminesBalance(): void
    {
        $source = $this->account('Source');
        $destination = $this->account('Destination');
        record_account_balance_observation($this->db, $source, 10000, '2026-09-01');
        record_account_balance_observation($this->db, $destination, -8000, '2026-09-01');
        record_account_transfer($this->db, $source, $destination, 5000, '2026-09-02');

        self::assertSame(5000.0, account_balance_as_of($this->db, $source, '2026-09-02'));
        self::assertSame(-3000.0, account_balance_as_of($this->db, $destination, '2026-09-02'));
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM transactions')->fetchColumn());
    }

    public function testSameDayMovementsAreAlreadyIncludedInEndOfDayObservation(): void
    {
        $account = $this->account('Same Day');
        record_account_event($this->db, 'income', 100, '2026-09-01', null, $account);
        record_account_balance_observation($this->db, $account, 750, '2026-09-01');
        record_account_event($this->db, 'expense', 25, '2026-09-01', $account, null);
        record_account_event($this->db, 'income', 40, '2026-09-02', null, $account);

        self::assertSame(750.0, account_balance_as_of($this->db, $account, '2026-09-01'));
        self::assertSame(790.0, account_balance_as_of($this->db, $account, '2026-09-02'));
    }

    public function testDirectPurchaseCreatesNormalEnvelopeAndAccountEffectsWhileBalanceMayRemainUnknown(): void
    {
        $month = $this->createMonth(2026, 9);
        $envelope = $this->createEnvelope('Envelope A', 'daily');
        $account = $this->account('Payment Account');
        $transactionId = save_transaction($this->db, [
            'month_id' => $month,
            'envelope_id' => $envelope,
            'payment_account_id' => $account,
            'amount' => 800,
            'spent_date' => '2025-09-03',
        ]);

        self::assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM account_events WHERE transaction_id=$transactionId")->fetchColumn());
        self::assertEqualsWithDelta(-800, envelope_balance_as_of($this->db, $envelope, '2025-09-03'), 0.001);
        self::assertNull(account_balance_as_of($this->db, $account, '2025-09-03'));
    }

    public function testOlderAndCurrentExpensesUseTheSameSemantics(): void
    {
        $may = $this->createMonth(2026, 5);
        $september = $this->createMonth(2026, 9);
        $envelope = $this->createEnvelope('Envelope A', 'daily');
        $account = $this->account('Payment Account');
        record_account_balance_observation($this->db, $account, 2000, '2026-05-01');
        save_transaction($this->db, ['month_id' => $may, 'envelope_id' => $envelope, 'payment_account_id' => $account, 'amount' => 800, 'spent_date' => '2026-05-12']);
        save_transaction($this->db, ['month_id' => $september, 'envelope_id' => $envelope, 'payment_account_id' => $account, 'amount' => 600, 'spent_date' => '2026-09-10']);

        self::assertSame(2, (int)$this->db->query("SELECT COUNT(*) FROM account_events WHERE event_type='expense'")->fetchColumn());
        self::assertSame(600.0, account_balance_as_of($this->db, $account, '2026-09-11'));
    }

    public function testTransactionOwnsAtMostOneAccountExpenseEffect(): void
    {
        $month = $this->createMonth(2026, 9);
        $envelope = $this->createEnvelope('Envelope A', 'daily');
        $account = $this->account('Payment Account');
        record_account_balance_observation($this->db, $account, 1000, '2026-09-01');
        $id = save_transaction($this->db, ['month_id' => $month, 'envelope_id' => $envelope, 'payment_account_id' => $account, 'amount' => 600, 'spent_date' => '2026-09-03']);
        $before = [$this->db->query("SELECT * FROM account_events WHERE transaction_id=$id")->fetch(), $this->db->query('SELECT * FROM account_movements')->fetch()];

        sync_transaction_account_event($this->db, $id);
        sync_transaction_account_event($this->db, $id);

        self::assertSame($before, [$this->db->query("SELECT * FROM account_events WHERE transaction_id=$id")->fetch(), $this->db->query('SELECT * FROM account_movements')->fetch()]);
        self::assertSame(400.0, account_balance_as_of($this->db, $account, '2026-09-03'));
    }

    public function testStatementIdentityIsDeterministicAndConservativeForRepeatedRows(): void
    {
        $row = ['account_id' => 4, 'provider' => 'evidence:alpha', 'booking_date' => '2026-08-12', 'amount' => -327.45, 'currency' => 'DKK', 'description' => ' GROCERY   SHOP ', 'occurrence' => 0];
        $same = [...$row, 'description' => 'grocery shop'];
        self::assertSame(statement_file_identity(4, 'evidence:alpha', 'file bytes'), statement_file_identity(4, ' EVIDENCE:ALPHA ', 'file bytes'));
        self::assertTrue(statement_rows_are_duplicates($row, $same));
        self::assertFalse(statement_rows_are_duplicates($row, [...$same, 'occurrence' => 1]));
    }

    public function testNullablePaymentAccountAndSourceMonthRemainExplicit(): void
    {
        $envelope = $this->createEnvelope('Envelope A', 'daily');
        $this->createMonth(2026, 9);
        $id = save_transaction($this->db, ['month_id' => null, 'envelope_id' => $envelope, 'payment_account_id' => null, 'amount' => 100, 'spent_date' => '2026-06-01']);
        $row = $this->db->query("SELECT month_id,payment_account_id FROM transactions WHERE id=$id")->fetch();
        self::assertNull($row['month_id']);
        self::assertNull($row['payment_account_id']);
    }

    private function account(string $name): int
    {
        return save_account_definition($this->db, [
            'name' => $name,
            'account_number' => 'TEST-' . strtoupper(str_replace(' ', '-', $name)),
            'currency' => 'CURRENCY',
        ]);
    }
}
