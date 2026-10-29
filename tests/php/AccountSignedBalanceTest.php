<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseTestCase.php';

final class AccountSignedBalanceTest extends DatabaseTestCase
{
    public function testAccountAcceptsPositiveZeroAndNegativeAbsoluteObservations(): void
    {
        foreach ([2500.0, 0.0, -19609.23] as $index => $balance) {
            $accountId = $this->account("Signed Account $index", "SIGNED-$index");
            $observationId = record_account_balance_observation($this->db, $accountId, $balance, '2026-01-01');
            self::assertGreaterThan(0, $observationId);
            self::assertSame($balance, account_balance_as_of($this->db, $accountId, '2026-01-01'));
        }
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM account_events')->fetchColumn());
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM account_movements')->fetchColumn());
    }

    public function testExactNegativeBalancePersistsThroughStateAndHistory(): void
    {
        $accountId = $this->account('Card', 'SIGNED-CARD');
        record_account_balance_observation($this->db, $accountId, -19609.23, '2026-01-01', 'Exact card Balance');
        $stateAccount = array_values(array_filter(get_state($this->db, 2026)['accounts'], fn(array $account): bool => $account['id'] === $accountId))[0];
        $history = get_account_history($this->db, $accountId)['history'][0];

        self::assertTrue($stateAccount['balance_known']);
        self::assertSame(-19609.23, $stateAccount['balance']);
        self::assertSame('observation', $history['history_kind']);
        self::assertSame(-19609.23, $history['amount']);
        self::assertSame('manual', $history['observation_source']);
    }

    public function testUnknownAccountStaysUnknownDespiteMovementsUntilObservation(): void
    {
        $accountId = $this->account('Unknown', 'SIGNED-UNKNOWN');
        record_account_event($this->db, 'income', 500, '2026-02-01', null, $accountId);
        record_account_event($this->db, 'expense', 100, '2026-02-02', $accountId, null);
        self::assertNull(account_balance_as_of($this->db, $accountId, '2026-02-28'));
        self::assertFalse(account_balance_state_as_of($this->db, $accountId, '2026-02-28')['balance_known']);
    }

    public function testExpenseRefundAndTransfersUseCanonicalSignedMovements(): void
    {
        $envelopeId = $this->createEnvelope('Signed effects');
        $first = $this->account('First', 'SIGNED-FIRST');
        $second = $this->account('Second', 'SIGNED-SECOND');
        record_account_balance_observation($this->db, $first, 1000, '2026-01-31');
        record_account_balance_observation($this->db, $second, 0, '2026-01-31');
        save_transaction($this->db, ['envelope_id' => $envelopeId, 'payment_account_id' => $first, 'amount' => 100, 'spent_date' => '2026-02-01']);
        save_transaction($this->db, ['envelope_id' => $envelopeId, 'payment_account_id' => $first, 'amount' => 40, 'spent_date' => '2026-02-02', 'kind' => 'refund']);
        record_account_transfer($this->db, $first, $second, 25, '2026-02-03');

        self::assertSame(915.0, account_balance_as_of($this->db, $first, '2026-02-03'));
        self::assertSame(25.0, account_balance_as_of($this->db, $second, '2026-02-03'));
        self::assertSame([-100.0, 40.0, -25.0, 25.0], array_map('floatval', $this->db->query('SELECT amount FROM account_movements ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)));
    }

    public function testNewerObservationSupersedesEarlierCalculation(): void
    {
        $accountId = $this->account('Observed', 'SIGNED-OBSERVED');
        record_account_balance_observation($this->db, $accountId, 1000, '2026-03-01');
        record_account_event($this->db, 'expense', 100, '2026-03-02', $accountId, null);
        record_account_balance_observation($this->db, $accountId, 750, '2026-03-05');
        record_account_event($this->db, 'income', 25, '2026-03-06', null, $accountId);

        self::assertSame(900.0, account_balance_as_of($this->db, $accountId, '2026-03-04'));
        self::assertSame(750.0, account_balance_as_of($this->db, $accountId, '2026-03-05'));
        self::assertSame(775.0, account_balance_as_of($this->db, $accountId, '2026-03-06'));
    }

    public function testMetadataEditPreservesObservationsEventsAndMovements(): void
    {
        $accountId = $this->account('Original', 'SIGNED-METADATA');
        record_account_balance_observation($this->db, $accountId, -500, '2026-05-01');
        record_account_event($this->db, 'expense', 100, '2026-05-02', $accountId, null);
        $before = [
            $this->db->query('SELECT * FROM account_balance_observations')->fetchAll(),
            $this->db->query('SELECT * FROM account_events')->fetchAll(),
            $this->db->query('SELECT * FROM account_movements')->fetchAll(),
        ];
        update_managed_account($this->db, $accountId, ['name' => 'Renamed', 'account_number' => 'SIGNED-METADATA', 'currency' => 'CURRENCY']);
        self::assertSame($before, [
            $this->db->query('SELECT * FROM account_balance_observations')->fetchAll(),
            $this->db->query('SELECT * FROM account_events')->fetchAll(),
            $this->db->query('SELECT * FROM account_movements')->fetchAll(),
        ]);
        self::assertSame(-600.0, account_balance_as_of($this->db, $accountId, '2026-05-31'));
    }

    public function testCurrentSchemaContainsNoAccountClassificationOrLegacyBalanceModel(): void
    {
        $accountColumns = array_column($this->db->query('PRAGMA table_info(accounts)')->fetchAll(), 'name');
        self::assertNotContains('type', $accountColumns);
        self::assertNotContains('ledger_start_date', $accountColumns);
        $eventSql = (string)$this->db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='account_events'")->fetchColumn();
        self::assertStringNotContainsString('opening', $eventSql);
        self::assertStringNotContainsString('reconciliation', $eventSql);
        self::assertStringContainsString('account_balance_observations', implode(',', $this->db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN)));
    }

    private function account(string $name, string $number): int
    {
        return save_account_definition($this->db, ['name' => $name, 'account_number' => $number, 'currency' => 'CURRENCY']);
    }
}
