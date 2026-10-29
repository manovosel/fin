<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseTestCase.php';

final class MonthSettingsTest extends DatabaseTestCase
{
    public function testExistingMonthSalaryCanBeUpdated(): void
    {
        $id = $this->createMonth(2026, 7);

        $savedId = save_month($this->db, [
            'id' => $id,
            'year' => 2026,
            'month' => 7,
            'exchange_rate' => 7.46003,
            'salary' => 31234.56,
            'notes' => 'Updated month',
        ]);

        self::assertSame($id, $savedId);
        $month = find_month($this->db, 2026, 7);
        self::assertEqualsWithDelta(31234.56, (float)$month['salary'], 0.001);
        self::assertEqualsWithDelta(7.46003, (float)$month['exchange_rate'], 0.000001);
        self::assertSame('Updated month', $month['notes']);
    }

    public function testMissingExchangeRateRemainsUnavailableWithoutAnInventedDefault(): void
    {
        $id = save_month($this->db, [
            'year' => 2026,
            'month' => 2,
            'salary' => 1000,
        ]);

        self::assertNull(find_month($this->db, 2026, 2)['exchange_rate']);
        self::assertNull(normalize_month(find_month($this->db, 2026, 2))['exchange_rate']);

        // Use a synthetic reference month instead of Today/current month.
        // The Month service must NOT manufacture a default exchange rate;
        // it stays NULL until the user explicitly configures one.
        save_month($this->db, ['year' => 2099, 'month' => 1, 'salary' => 0]);
        $synthetic = find_month($this->db, 2099, 1);
        self::assertNotNull($synthetic);
        self::assertNull($synthetic['exchange_rate']);

        save_month($this->db, ['id' => $id, 'year' => 2026, 'month' => 2, 'exchange_rate' => null]);
        self::assertNull(find_month($this->db, 2026, 2)['exchange_rate']);
    }

    public function testTargetSavingsIsNotPartOfTheMonthProductModel(): void
    {
        $id = save_month($this->db, [
            'year' => 2026,
            'month' => 1,
            'exchange_rate' => 7.46,
            'salary' => 30000,
            'target_savings_percent' => 0.42,
        ]);

        $month = normalize_month(find_month($this->db, 2026, 1));
        self::assertArrayNotHasKey('target_savings_percent', $month);
        self::assertEqualsWithDelta(0.11, (float)$this->db->query("SELECT target_savings_percent FROM months WHERE id = $id")->fetchColumn(), 0.0001);

        $summary = report_month_summary([
            ...$month,
            'entries' => [],
            'transactions' => [],
        ]);
        self::assertArrayNotHasKey('target_savings', $summary);
    }
}
