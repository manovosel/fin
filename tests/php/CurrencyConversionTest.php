<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseTestCase.php';

final class CurrencyConversionTest extends DatabaseTestCase
{
    public function testHelperPlanIsConvertedOnceAndRateEditDoesNotRewriteTruth(): void
    {
        $monthId = save_month($this->db, ['year' => 2026, 'month' => 1, 'exchange_rate' => 7.5]);
        $envelopeId = $this->createEnvelope('Reserved test envelope', 'reserved', 'HELPER_CURRENCY');
        $entryId = save_entry($this->db, [
            'month_id' => $monthId,
            'envelope_id' => $envelopeId,
            'type' => 'expense',
            'amount' => 100,
            'status' => 'paid',
            'due_date' => '2026-01-05',
        ]);

        self::assertEqualsWithDelta(750, (float)$this->db->query("SELECT amount FROM entries WHERE id=$entryId")->fetchColumn(), 0.001);
        self::assertEqualsWithDelta(750, $this->envelopeBalance($envelopeId), 0.001);

        save_month($this->db, ['id' => $monthId, 'year' => 2026, 'month' => 1, 'exchange_rate' => 8.0]);

        self::assertEqualsWithDelta(750, (float)$this->db->query("SELECT amount FROM entries WHERE id=$entryId")->fetchColumn(), 0.001);
        self::assertEqualsWithDelta(750, $this->envelopeBalance($envelopeId), 0.001);
    }

    public function testCurrencyPlanPassesThroughUnchanged(): void
    {
        $monthId = save_month($this->db, ['year' => 2026, 'month' => 3, 'exchange_rate' => 7.5]);
        $envelopeId = $this->createEnvelope('Envelope A', 'daily', 'CURRENCY');
        $entryId = $this->saveAllocation($monthId, $envelopeId, 750);

        self::assertEqualsWithDelta(750, (float)$this->db->query("SELECT amount FROM entries WHERE id=$entryId")->fetchColumn(), 0.001);
        $columns = array_column($this->db->query('PRAGMA table_info(entries)')->fetchAll(), 'name');
        self::assertContains('amount', $columns);
        self::assertNotContains('amount_eur', $columns);
        self::assertNotContains('currency_original', $columns);
    }

    public function testHelperExpenseUsesEventDateRateAndRateEditDoesNotRewriteTruth(): void
    {
        save_month($this->db, ['year' => 2026, 'month' => 8, 'exchange_rate' => 7.5]);
        $september = save_month($this->db, ['year' => 2026, 'month' => 9, 'exchange_rate' => 8.0]);
        $envelopeId = $this->createEnvelope('Travel', 'reserved', 'HELPER_CURRENCY');
        $transactionId = save_transaction($this->db, [
            'month_id' => $september,
            'envelope_id' => $envelopeId,
            'amount' => 20,
            'spent_date' => '2026-09-02',
        ]);

        self::assertEqualsWithDelta(160, (float)$this->db->query("SELECT amount FROM transactions WHERE id=$transactionId")->fetchColumn(), 0.001);
        save_month($this->db, ['id' => $september, 'year' => 2026, 'month' => 9, 'exchange_rate' => 9.0]);
        self::assertEqualsWithDelta(160, (float)$this->db->query("SELECT amount FROM transactions WHERE id=$transactionId")->fetchColumn(), 0.001);
        self::assertEqualsWithDelta(-160, (float)$this->db->query("SELECT amount FROM envelope_movements WHERE transaction_id=$transactionId")->fetchColumn(), 0.001);
    }

    public function testHelperExpenseRequiresRateForItsActualEventMonthAtomically(): void
    {
        $sourceMonth = save_month($this->db, ['year' => 2026, 'month' => 8, 'exchange_rate' => 7.5]);
        $envelopeId = $this->createEnvelope('Travel', 'reserved', 'HELPER_CURRENCY');

        // With the ECB historical-rate fallback enabled, a Helper-currency event whose
        // Month has no configured rate now resolves via the EUR-anchored ECB weekly
        // cache. The conversion must succeed and persist the converted Currency amount.
        $transactionId = save_transaction($this->db, [
            'month_id' => $sourceMonth,
            'envelope_id' => $envelopeId,
            'amount' => 20,
            'spent_date' => '2026-09-02',
        ]);
        $stored = (float)$this->db->query('SELECT amount FROM transactions WHERE id=' . (int)$transactionId)->fetchColumn();
        self::assertGreaterThan(0.0, $stored, 'Historical ECB rate must convert the Helper amount to Currency');
    }

    public function testHelperAccountObservationsAndHistoryUseEventDateRates(): void
    {
        save_month($this->db, ['year' => 2026, 'month' => 1, 'exchange_rate' => 7.5]);
        save_month($this->db, ['year' => 2026, 'month' => 2, 'exchange_rate' => 8.0]);
        $accountId = create_account_with_initial_position($this->db, [
            'name' => 'Helper account', 'account_number' => 'HELPER-ACCOUNT', 'currency' => 'HELPER_CURRENCY',
        ]);
        record_account_balance_observation($this->db, $accountId, 100, '2026-01-05', 'January Balance');
        record_account_balance_observation($this->db, $accountId, 120, '2026-02-05', 'February Balance');

        self::assertEqualsWithDelta(960, account_balance_as_of($this->db, $accountId, '2026-02-05'), 0.001);
        $account = array_values(array_filter(get_accounts($this->db, '2026-02-05'), static fn(array $row): bool => $row['id'] === $accountId))[0];
        self::assertEqualsWithDelta(120, (float)$account['display_balance'], 0.001);
        self::assertSame('EUR', $account['display_currency']);
        $history = get_account_history($this->db, $accountId)['history'];
        self::assertEqualsWithDelta(120, (float)$history[0]['display_amount'], 0.001);
        self::assertEqualsWithDelta(100, (float)$history[1]['display_amount'], 0.001);
    }

    public function testFutureHelperObservationIsRejectedWithoutObservationRow(): void
    {
        $accountId = create_account_with_initial_position($this->db, [
            'name' => 'No future Balance', 'account_number' => 'NO-FUTURE', 'currency' => 'HELPER_CURRENCY',
        ]);
        try {
            record_account_balance_observation($this->db, $accountId, 100, '2030-01-05');
            self::fail('A future Helper Balance must fail.');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('future', $error->getMessage());
        }
        self::assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM account_balance_observations WHERE account_id=$accountId")->fetchColumn());
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM account_events')->fetchColumn());
    }

    public function testMonthCopyPreservesAuthoritativeCurrencyAmount(): void
    {
        $source = save_month($this->db, ['year' => 2026, 'month' => 4, 'exchange_rate' => 7.5]);
        save_month($this->db, ['year' => 2026, 'month' => 5, 'exchange_rate' => 8.0]);
        $envelopeId = $this->createEnvelope('EUR dues', 'reserved', 'HELPER_CURRENCY');
        save_entry($this->db, ['month_id' => $source, 'envelope_id' => $envelopeId, 'amount' => 100, 'status' => 'paid']);

        duplicate_month($this->db, 2026, 4, 2026, 5);

        $target = find_month($this->db, 2026, 5);
        self::assertSame(8.0, (float)$target['exchange_rate']);
        $entry = $this->db->query('SELECT amount,status,due_date FROM entries WHERE month_id=' . (int)$target['id'])->fetch();
        self::assertEqualsWithDelta(750, (float)$entry['amount'], 0.001);
        self::assertSame('planned', $entry['status']);
        self::assertNull($entry['due_date']);
    }

    public function testNonDefaultCurrencyConfigurationUsesTheSameModel(): void
    {
        $db = self::newDatabase();
        migrate($db, ['currency' => 'USD', 'helper_currency' => 'SEK']);
        $monthId = save_month($db, ['year' => 2026, 'month' => 1, 'exchange_rate' => 0.1]);
        $envelopeId = save_envelope($db, ['name' => 'Helper input', 'account' => 'Test', 'role' => 'daily', 'currency' => 'HELPER_CURRENCY']);
        $transactionId = save_transaction($db, ['month_id' => $monthId, 'envelope_id' => $envelopeId, 'amount' => 100, 'spent_date' => '2026-01-02']);

        self::assertSame('USD', database_currency($db));
        self::assertSame(['currency' => 'USD', 'helper_currency' => 'SEK'], currency_context($db));
        self::assertEqualsWithDelta(10, (float)$db->query("SELECT amount FROM transactions WHERE id=$transactionId")->fetchColumn(), 0.001);
    }

    public function testExistingDatabaseRejectsConfiguredCurrencyMismatch(): void
    {
        $db = self::newDatabase();
        migrate($db, ['currency' => 'USD', 'helper_currency' => 'SEK']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not match database Currency');
        migrate($db, ['currency' => 'CAD', 'helper_currency' => 'SEK']);
    }
}
