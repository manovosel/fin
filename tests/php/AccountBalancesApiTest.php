<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseTestCase.php';

final class AccountBalancesApiTest extends DatabaseTestCase
{
    public function testTheResponseCarriesTheAccountsScreenBalanceAndNoAccountNumber(): void
    {
        $currency = currency_context($this->db)['currency'];
        $accountId = save_account_definition($this->db, [
            'name' => 'Account A',
            'account_number' => 'ACCOUNT-A-1',
            'currency' => 'CURRENCY',
        ]);
        record_account_balance_observation($this->db, $accountId, 1000.0, $this->daysAgo(20), '', 'manual');
        record_account_event($this->db, 'income', 500.0, $this->daysAgo(10), null, $accountId, null, 'Income');

        $response = build_account_balances_response($this->db);

        self::assertSame(effective_today(), $response['as_of']);
        self::assertSame($currency, $response['currency']);
        self::assertCount(1, $response['accounts']);

        $reported = $response['accounts'][0];
        self::assertSame($accountId, $reported['id']);
        self::assertSame('Account A', $reported['name']);
        self::assertSame($currency, $reported['currency']);
        self::assertTrue($reported['balance_known']);
        self::assertEqualsWithDelta(1500.0, $reported['balance'], 0.001);
        self::assertSame('active', $reported['status']);
        self::assertSame($this->daysAgo(20), $reported['last_observation_date']);

        // The reported balance is the number the Accounts screen shows.
        $screen = get_accounts($this->db);
        self::assertCount(1, $screen);
        self::assertEqualsWithDelta((float)$screen[0]['display_balance'], $reported['balance'], 0.001);
        self::assertSame((string)$screen[0]['display_currency'], $reported['currency']);

        // The Account number is never reported.
        self::assertArrayNotHasKey('account_number', $reported);
        self::assertStringNotContainsString('ACCOUNT-A-1', (string)json_encode($response));
    }

    public function testArchivedAccountsAreIncludedWithTheirStatus(): void
    {
        $archivedId = save_account_definition($this->db, [
            'name' => 'Account A',
            'account_number' => 'ACCOUNT-A-1',
            'currency' => 'CURRENCY',
        ]);
        record_account_balance_observation($this->db, $archivedId, 100.0, $this->daysAgo(20), '', 'manual');
        set_account_active($this->db, $archivedId, false);
        $activeId = save_account_definition($this->db, [
            'name' => 'Account B',
            'account_number' => 'ACCOUNT-B-2',
            'currency' => 'CURRENCY',
        ]);
        record_account_balance_observation($this->db, $activeId, 200.0, $this->daysAgo(20), '', 'manual');

        $response = build_account_balances_response($this->db);

        self::assertCount(2, $response['accounts']);
        $byId = [];
        foreach ($response['accounts'] as $account) $byId[$account['id']] = $account;

        self::assertArrayHasKey($archivedId, $byId);
        self::assertSame('archived', $byId[$archivedId]['status']);
        self::assertEqualsWithDelta(100.0, $byId[$archivedId]['balance'], 0.001);
        self::assertSame('Account A', $byId[$archivedId]['name']);

        self::assertArrayHasKey($activeId, $byId);
        self::assertSame('active', $byId[$activeId]['status']);
        self::assertEqualsWithDelta(200.0, $byId[$activeId]['balance'], 0.001);
    }

    public function testAnAccountWithoutAnObservationHasNoBalanceAndNoObservationDate(): void
    {
        $accountId = save_account_definition($this->db, [
            'name' => 'Account A',
            'account_number' => 'ACCOUNT-A-1',
            'currency' => 'CURRENCY',
        ]);
        record_account_event($this->db, 'income', 500.0, $this->daysAgo(10), null, $accountId, null, 'Income');

        $response = build_account_balances_response($this->db);

        self::assertCount(1, $response['accounts']);
        $reported = $response['accounts'][0];
        self::assertFalse($reported['balance_known']);
        self::assertNull($reported['balance']);
        self::assertNull($reported['last_observation_date']);
        self::assertSame('active', $reported['status']);
    }

    public function testTheLatestObservationAndLaterMovementsDecideTheBalance(): void
    {
        $accountId = save_account_definition($this->db, [
            'name' => 'Account A',
            'account_number' => 'ACCOUNT-A-1',
            'currency' => 'CURRENCY',
        ]);
        record_account_event($this->db, 'income', 100.0, $this->daysAgo(30), null, $accountId, null, 'Before');
        record_account_balance_observation($this->db, $accountId, 1000.0, $this->daysAgo(20), '', 'manual');
        record_account_balance_observation($this->db, $accountId, 2000.0, $this->daysAgo(10), '', 'manual');
        record_account_event($this->db, 'income', 300.0, $this->daysAgo(5), null, $accountId, null, 'After');

        $response = build_account_balances_response($this->db);

        $reported = $response['accounts'][0];
        self::assertEqualsWithDelta(2300.0, $reported['balance'], 0.001);
        self::assertSame($this->daysAgo(10), $reported['last_observation_date']);
    }

    public function testAnAccountInTheHelperCurrencyReportsItsOwnCurrency(): void
    {
        $today = effective_today();
        save_month($this->db, [
            'year' => (int)substr($today, 0, 4),
            'month' => (int)substr($today, 5, 2),
            'exchange_rate' => 7.5,
        ]);
        $helper = currency_context($this->db)['helper_currency'];
        $accountId = save_account_definition($this->db, [
            'name' => 'Account A',
            'account_number' => 'ACCOUNT-A-1',
            'currency' => 'HELPER_CURRENCY',
        ]);
        record_account_balance_observation($this->db, $accountId, 100.0, $this->daysAgo(20), '', 'manual');

        $response = build_account_balances_response($this->db);

        $reported = $response['accounts'][0];
        self::assertSame($helper, $reported['currency']);
        self::assertEqualsWithDelta(100.0, $reported['balance'], 0.001);

        // The Accounts screen shows the same amount in the same currency.
        $screen = get_accounts($this->db);
        self::assertEqualsWithDelta((float)$screen[0]['display_balance'], $reported['balance'], 0.001);
        self::assertSame((string)$screen[0]['display_currency'], $reported['currency']);
    }

    public function testTheResponseChangesNoData(): void
    {
        $accountId = save_account_definition($this->db, [
            'name' => 'Account A',
            'account_number' => 'ACCOUNT-A-1',
            'currency' => 'CURRENCY',
        ]);
        record_account_balance_observation($this->db, $accountId, 1000.0, $this->daysAgo(20), '', 'manual');
        record_account_event($this->db, 'income', 500.0, $this->daysAgo(10), null, $accountId, null, 'Income');

        $before = $this->databaseSnapshot();
        build_account_balances_response($this->db);
        $after = $this->databaseSnapshot();

        self::assertSame($before, $after);
    }

    /** A past date, so no observation and no movement can be in the future. */
    private function daysAgo(int $days): string
    {
        return date('Y-m-d', strtotime(effective_today() . ' -' . $days . ' days'));
    }

    /**
     * Every table of the test database, with its rows, as one comparable array.
     *
     * @return array<string,string>
     */
    private function databaseSnapshot(): array
    {
        $tables = $this->db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll();
        $snapshot = [];
        foreach ($tables as $table) {
            $rows = $this->db->query('SELECT * FROM "' . $table['name'] . '"')->fetchAll(PDO::FETCH_ASSOC);
            $snapshot[(string)$table['name']] = count($rows) . ':' . md5((string)json_encode($rows));
        }
        return $snapshot;
    }
}
