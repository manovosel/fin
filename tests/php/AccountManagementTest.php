<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseTestCase.php';

final class AccountManagementTest extends DatabaseTestCase
{
    public function testAccountCreationBalanceIsOptionalAndExplicitZeroIsKnown(): void
    {
        $unknown = create_account_with_initial_position($this->db, [
            'name' => 'Unknown Account', 'account_number' => 'ACCOUNT-UNKNOWN', 'currency' => 'CURRENCY',
        ]);
        $zero = create_account_with_initial_position($this->db, [
            'name' => 'Zero Account', 'account_number' => 'ACCOUNT-ZERO', 'currency' => 'CURRENCY', 'initial_balance' => 0,
        ]);

        self::assertNull(account_balance_as_of($this->db, $unknown, effective_today()));
        self::assertSame(0.0, account_balance_as_of($this->db, $zero, effective_today()));
        $observation = $this->db->query("SELECT effective_date,balance,source FROM account_balance_observations WHERE account_id=$zero")->fetch();
        self::assertSame(effective_today(), $observation['effective_date']);
        self::assertSame(0.0, (float)$observation['balance']);
        self::assertSame('account_creation', $observation['source']);
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM account_events')->fetchColumn());
    }

    public function testAccountListReturnsExplicitKnownAndUnknownStates(): void
    {
        $known = save_account_definition($this->db, ['name' => 'Known', 'account_number' => 'ACCOUNT-KNOWN', 'currency' => 'CURRENCY']);
        $unknown = save_account_definition($this->db, ['name' => 'Unknown', 'account_number' => 'ACCOUNT-UNKNOWN-LIST', 'currency' => 'CURRENCY']);
        record_account_balance_observation($this->db, $known, -19609.23, '2026-01-10');
        record_account_event($this->db, 'income', 100, '2026-01-11', null, $unknown);
        $accounts = get_accounts($this->db, '2026-01-31');
        $knownRow = array_values(array_filter($accounts, fn(array $row): bool => $row['id'] === $known))[0];
        $unknownRow = array_values(array_filter($accounts, fn(array $row): bool => $row['id'] === $unknown))[0];

        self::assertTrue($knownRow['balance_known']);
        self::assertSame(-19609.23, $knownRow['balance']);
        self::assertFalse($unknownRow['balance_known']);
        self::assertNull($unknownRow['balance']);
        self::assertNull($unknownRow['display_balance']);
        self::assertSame(1, $unknownRow['movement_count']);
    }

    public function testEditArchiveAndRestorePreserveAllFinancialHistoryAndReferences(): void
    {
        $month = $this->createMonth(2026, 8);
        $account = save_account_definition($this->db, ['name' => 'Wallet', 'account_number' => 'ACCOUNT-EDIT', 'currency' => 'CURRENCY']);
        record_account_balance_observation($this->db, $account, 5000, '2026-01-01');
        $envelope = save_envelope($this->db, ['name' => 'Envelope A', 'account' => 'Legacy Wallet', 'role' => 'daily', 'default_account_id' => $account]);
        $transaction = save_transaction($this->db, ['month_id' => $month, 'envelope_id' => $envelope, 'payment_account_id' => $account, 'amount' => 400, 'spent_date' => '2026-08-02']);
        $before = [
            'observations' => $this->db->query('SELECT * FROM account_balance_observations')->fetchAll(),
            'events' => $this->db->query('SELECT * FROM account_events')->fetchAll(),
            'movements' => $this->db->query('SELECT * FROM account_movements')->fetchAll(),
        ];

        update_managed_account($this->db, $account, ['name' => 'Wallet main', 'account_number' => 'ACCOUNT-EDIT', 'currency' => 'CURRENCY']);
        set_account_active($this->db, $account, false);
        self::assertSame($account, (int)$this->db->query("SELECT payment_account_id FROM transactions WHERE id=$transaction")->fetchColumn());
        self::assertSame($before, [
            'observations' => $this->db->query('SELECT * FROM account_balance_observations')->fetchAll(),
            'events' => $this->db->query('SELECT * FROM account_events')->fetchAll(),
            'movements' => $this->db->query('SELECT * FROM account_movements')->fetchAll(),
        ]);
        set_account_active($this->db, $account, true);
        self::assertSame(1, (int)account_by_id($this->db, $account)['active']);
    }

    public function testRecordingSameAccountDateUpdatesOneObservation(): void
    {
        $account = save_account_definition($this->db, ['name' => 'Observed', 'account_number' => 'ACCOUNT-UPSERT', 'currency' => 'CURRENCY']);
        $firstId = record_account_balance_observation($this->db, $account, 1000, '2026-04-20', 'First');
        $secondId = record_account_balance_observation($this->db, $account, 9400, '2026-04-20', 'Corrected');

        self::assertSame($firstId, $secondId);
        self::assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM account_balance_observations WHERE account_id=$account")->fetchColumn());
        self::assertSame(9400.0, account_balance_as_of($this->db, $account, '2026-04-20'));
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM account_movements')->fetchColumn());
    }

    public function testFutureObservationIsRejectedWithoutPartialRows(): void
    {
        $account = save_account_definition($this->db, ['name' => 'Future', 'account_number' => 'ACCOUNT-FUTURE', 'currency' => 'CURRENCY']);
        $futureDate = (new DateTimeImmutable('today'))->modify('+1 day')->format('Y-m-d');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Balance date cannot be in the future');
        record_account_balance_observation($this->db, $account, 100, $futureDate);
    }

    public function testObservationHistoryCanBeDeletedWithoutDeletingFinancialActivity(): void
    {
        $account = save_account_definition($this->db, ['name' => 'History', 'account_number' => 'ACCOUNT-HISTORY', 'currency' => 'CURRENCY']);
        $observation = record_account_balance_observation($this->db, $account, 1000, '2026-01-01', 'Bank Balance');
        $event = record_account_event($this->db, 'expense', 50, '2026-01-02', $account, null);
        $history = get_account_history($this->db, $account)['history'];
        self::assertSame(['movement', 'observation'], array_column($history, 'history_kind'));

        delete_account_balance_observation($this->db, $account, $observation);
        self::assertNull(account_balance_as_of($this->db, $account, '2026-01-31'));
        self::assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM account_events WHERE id=$event")->fetchColumn());
        self::assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM account_movements WHERE event_id=$event")->fetchColumn());
    }
}
