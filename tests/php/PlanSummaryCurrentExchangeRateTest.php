<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseTestCase.php';

final class PlanSummaryCurrentExchangeRateTest extends DatabaseTestCase
{
    public function testStateFieldEqualsStoredRateOfMonthContainingToday(): void
    {
        $todayParts = explode('-', effective_today());
        $year = (int)$todayParts[0];
        $month = (int)$todayParts[1];
        $storedRate = 7.4627;
        save_month($this->db, [
            'year' => $year,
            'month' => $month,
            'exchange_rate' => $storedRate,
            'salary' => 0,
        ]);

        $state = get_state($this->db, $year);

        self::assertArrayHasKey('current_exchange_rate', $state);
        self::assertEqualsWithDelta($storedRate, (float)$state['current_exchange_rate'], 1e-9);
    }

    public function testStateFieldIsNullWhenNoMonthExistsForToday(): void
    {
        // Pick a year that is not the current year so get_state does not
        // call ensure_current_month_exists, leaving the months table
        // empty for the Month containing today.
        $todayParts = explode('-', effective_today());
        $todayYear = (int)$todayParts[0];
        $otherYear = $todayYear + 5;

        $state = get_state($this->db, $otherYear);

        self::assertArrayHasKey('current_exchange_rate', $state);
        self::assertNull($state['current_exchange_rate']);
    }

    public function testStateFieldMatchesRateUsedByDisplayBalance(): void
    {
        // The Plan summary Balance cell must use the same rate
        // display_balance uses, by construction. Both come from the
        // months table for the Month containing effective_today().
        $todayParts = explode('-', effective_today());
        $year = (int)$todayParts[0];
        $month = (int)$todayParts[1];
        $storedRate = 7.4627;
        save_month($this->db, [
            'year' => $year,
            'month' => $month,
            'exchange_rate' => $storedRate,
            'salary' => 0,
        ]);
        $accountId = create_account_with_initial_position($this->db, [
            'name' => 'Helper',
            'account_number' => 'TEST-HELPER-CURRENT',
            'currency' => 'HELPER_CURRENCY',
            'initial_balance' => 1000,
        ]);

        $state = get_state($this->db, $year);
        $account = null;
        foreach ($state['accounts'] as $candidate) {
            if ((int)$candidate['id'] === $accountId) { $account = $candidate; break; }
        }
        self::assertNotNull($account);
        // display_balance is computed by amount_from_currency at
        // effective_today() using the same stored rate.
        self::assertEqualsWithDelta(1000.0, (float)$account['display_balance'], 0.01);
        self::assertEqualsWithDelta($storedRate, (float)$state['current_exchange_rate'], 1e-9);
        // balance / display_balance equals the stored rate exactly.
        self::assertEqualsWithDelta($storedRate, (float)$account['balance'] / (float)$account['display_balance'], 1e-9);
    }
}
