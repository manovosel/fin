<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseTestCase.php';
require_once __DIR__ . '/../../currency.php';
require_once __DIR__ . '/../../ecb_rates.php';
require_once __DIR__ . '/../../statement_import.php';
require_once __DIR__ . '/../../statement_reconciliation.php';
require_once __DIR__ . '/../../accounting.php';

final class EcbReferenceRateTest extends DatabaseTestCase
{
    /**
     * ECB reference series spanning 2024-12-30 .. 2025-12-31.
     * The sample includes weekend/holiday gaps so weekly selection can be proven.
     * Constructed programmatically to avoid PHP tokenizer issues with `<?xml`
     * inside a class body heredoc/nowdoc on PHP 8.x.
     */
    private static function sampleXml(): string
    {
        $rows = [
            ['2024-12-30', 'DKK', '7.4610'],
            ['2024-12-31', 'DKK', '7.4611'],
            ['2025-01-02', 'DKK', '7.4611'],
            ['2025-01-02', 'USD', '1.0342'],
            ['2025-01-02', 'NOK', '11.7950'],
            ['2025-01-02', 'SEK', '11.4590'],
            ['2025-01-03', 'DKK', '7.4612'],
            ['2025-01-03', 'USD', '1.0330'],
            ['2025-01-03', 'NOK', '11.7800'],
            ['2025-01-03', 'SEK', '11.4500'],
            ['2025-01-06', 'DKK', '7.4613'],
            ['2025-01-06', 'USD', '1.0320'],
            ['2025-01-06', 'NOK', '11.7700'],
            ['2025-01-06', 'SEK', '11.4400'],
            ['2025-01-07', 'DKK', '7.4614'],
            ['2025-01-08', 'DKK', '7.4615'],
            ['2025-01-09', 'DKK', '7.4616'],
            ['2025-01-10', 'DKK', '7.4617'],
            ['2025-01-13', 'DKK', '7.4618'],
            ['2025-01-14', 'DKK', '7.4619'],
            ['2025-01-15', 'DKK', '7.4620'],
            ['2025-01-16', 'DKK', '7.4621'],
            ['2025-01-17', 'DKK', '7.4622'],
            ['2025-04-15', 'DKK', '7.4625'],
            ['2025-04-16', 'DKK', '7.4628'],
            ['2025-04-17', 'DKK', '7.4630'],
            ['2025-04-17', 'USD', '1.0900'],
            ['2025-04-17', 'NOK', '11.6000'],
            ['2025-04-17', 'SEK', '10.9500'],
            ['2025-04-22', 'DKK', '7.4640'],
            ['2025-04-23', 'DKK', '7.4642'],
            ['2025-04-24', 'DKK', '7.4644'],
            ['2025-04-25', 'DKK', '7.4645'],
            ['2025-12-23', 'DKK', '7.4695'],
            ['2025-12-24', 'DKK', '7.4700'],
            ['2025-12-29', 'DKK', '7.4710'],
            ['2025-12-30', 'DKK', '7.4715'],
            ['2025-12-31', 'DKK', '7.4720'],
        ];
        $days = [];
        $byDate = [];
        foreach ($rows as [$date, $cur, $rate]) {
            $byDate[$date][] = '<Cube currency="' . $cur . '" rate="' . $rate . '"/>';
        }
        foreach ($byDate as $date => $rates) {
            $days[] = '<Cube time="' . $date . '">' . implode('', $rates) . '</Cube>';
        }
        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . '<gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01" xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref">'
            . '<Cube>' . implode('', $days) . '</Cube>'
            . '</gesmes:Envelope>';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionNeutralImportProfiles();
        set_currency_config($this->db, ['currency' => 'DKK', 'helper_currency' => 'EUR']);
        // Safety net: install a fetcher that refuses to make outbound HTTP. Individual
        // tests override this with a closure that returns a deterministic ECB XML body.
        ecb_reference_rate_set_fetcher(function (string $url): string {
            throw new RuntimeException('Test must provide its own fetcher in ' . debug_backtrace()[0]['function']);
        });
    }

    protected function tearDown(): void
    {
        ecb_reference_rate_reset_test_fetcher();
        parent::tearDown();
    }

    public function testOneFetchesAndCacheKeyIsWeekKey(): void
    {
        $calls = 0;
        ecb_reference_rate_set_fetcher(function (string $url) use (&$calls): string {
            $calls++;
            return self::sampleXml();
        });
        $first = ecb_reference_rate_for_date($this->db, 'DKK', '2025-01-13');
        self::assertSame(1, $calls, 'First call should perform the HTTP fetch');
        self::assertSame('2025-W03', $first['week_key']);
        // Latest publication in W03 is 2025-01-17 (Friday) -> rate 7.4622.
        self::assertSame('2025-01-17', $first['published_date']);
        self::assertEqualsWithDelta(7.4622, $first['rate'], 1e-9);
    }

    public function testAllDaysInTheSameWeekShareTheSameCachedRate(): void
    {
        $calls = 0;
        ecb_reference_rate_set_fetcher(function (string $url) use (&$calls): string {
            $calls++;
            return self::sampleXml();
        });
        $monday = ecb_reference_rate_for_date($this->db, 'DKK', '2025-01-13');
        // W03 = Mon 2025-01-13..Sun 2025-01-19.
        $tuesday = ecb_reference_rate_for_date($this->db, 'DKK', '2025-01-14');
        $friday = ecb_reference_rate_for_date($this->db, 'DKK', '2025-01-17');
        $saturday = ecb_reference_rate_for_date($this->db, 'DKK', '2025-01-18');
        $sunday = ecb_reference_rate_for_date($this->db, 'DKK', '2025-01-19');
        self::assertSame(1, $calls, 'Only the first lookup for the week should fetch');
        self::assertSame($monday, $tuesday);
        self::assertSame($monday, $friday);
        self::assertSame($monday, $saturday);
        self::assertSame($monday, $sunday);
        self::assertSame('2025-W03', $monday['week_key']);
    }

    public function testDifferentWeeksUseDifferentCachedRows(): void
    {
        $calls = 0;
        ecb_reference_rate_set_fetcher(function (string $url) use (&$calls): string {
            $calls++;
            return self::sampleXml();
        });
        $w03 = ecb_reference_rate_for_date($this->db, 'DKK', '2025-01-13');
        $w04 = ecb_reference_rate_for_date($this->db, 'DKK', '2025-01-20');
        // Population fetches once, then both weeks are served from cache.
        self::assertSame(1, $calls);
        self::assertSame('2025-W03', $w03['week_key']);
        self::assertSame('2025-W04', $w04['week_key']);
        self::assertNotSame($w03, $w04);
    }

    public function testWeekWithNoEcbPublicationFallsBackToPrecedingInsideWeek(): void
    {
        ecb_reference_rate_set_fetcher(fn() => self::sampleXml());
        // 2025-W16 = Mon 2025-04-14 .. Sun 2025-04-20. ECB publishes 04-15,04-16,04-17
        // (Wed-Fri); Good Friday (04-18) is a non-publication. Inside the week the
        // latest publication is 2025-04-17 with rate 7.4630.
        $friday = ecb_reference_rate_for_date($this->db, 'DKK', '2025-04-17');
        self::assertSame('2025-W16', $friday['week_key']);
        self::assertSame('2025-04-17', $friday['published_date']);
        self::assertEqualsWithDelta(7.4630, $friday['rate'], 1e-9);
        $goodFriday = ecb_reference_rate_for_date($this->db, 'DKK', '2025-04-18');
        self::assertSame($friday, $goodFriday);
        $saturday = ecb_reference_rate_for_date($this->db, 'DKK', '2025-04-19');
        self::assertSame($friday, $saturday);
        $sunday = ecb_reference_rate_for_date($this->db, 'DKK', '2025-04-20');
        self::assertSame($friday, $sunday);
    }

    public function testWeekBridgingWhenEcbMissesAllDaysUsesMostRecentPrecedingWeek(): void
    {
        ecb_reference_rate_set_fetcher(fn() => self::sampleXml());
        // W17 = Mon 2025-04-21 .. Sun 2025-04-27. The series skips 04-21 (Easter Monday)
        // and resumes on 04-22; inside the week the latest publication is 04-25.
        $w17 = ecb_reference_rate_for_date($this->db, 'DKK', '2025-04-23');
        self::assertSame('2025-04-25', $w17['published_date']);
        self::assertEqualsWithDelta(7.4645, $w17['rate'], 1e-9);
        // All days in W17 must share the same week_key + rate.
        $monday = ecb_reference_rate_for_date($this->db, 'DKK', '2025-04-21');
        $sunday = ecb_reference_rate_for_date($this->db, 'DKK', '2025-04-27');
        self::assertSame($w17, $monday);
        self::assertSame($w17, $sunday);
    }

    public function testChristmasWeekUsesLastEcbPublicationInsideThatWeek(): void
    {
        ecb_reference_rate_set_fetcher(fn() => self::sampleXml());
        // 2025-W52 = Mon 2025-12-22 .. Sun 2025-12-28.
        // ECB publishes 12-23 (Tue) and 12-24 (Wed); 12-25 Christmas / 12-26 Boxing Day
        // are non-publication. Inside the week the latest is 12-24 with rate 7.4700.
        $w52 = ecb_reference_rate_for_date($this->db, 'DKK', '2025-12-25');
        self::assertSame('2025-12-24', $w52['published_date']);
        self::assertEqualsWithDelta(7.4700, $w52['rate'], 1e-9);
    }

    public function testWeekRateIsImmutableAcrossCallsAndFetches(): void
    {
        $callIndex = 0;
        ecb_reference_rate_set_fetcher(function (string $url) use (&$callIndex): string {
            $callIndex++;
            // First fetch returns canonical sample; the second fetch returns a tampered
            // body that should be ignored because the row is already cached.
            if ($callIndex === 1) return self::sampleXml();
            return str_replace('rate="7.4622"', 'rate="99.9999"', self::sampleXml());
        });
        $first = ecb_reference_rate_for_date($this->db, 'DKK', '2025-01-13');
        // Force another resolution to ensure no second fetch is made.
        $second = ecb_reference_rate_for_date($this->db, 'DKK', '2025-01-17');
        self::assertSame(1, $callIndex, 'Cached weekly row must not trigger a second fetch');
        self::assertSame($first, $second);
        self::assertEqualsWithDelta(7.4622, $first['rate'], 1e-9);
    }

    public function testFetcherFailureReturnsNullForUnknownWeek(): void
    {
        ecb_reference_rate_set_fetcher(function (string $url): string { throw new RuntimeException('network down'); });
        self::assertNull(ecb_reference_rate_for_date($this->db, 'DKK', '2019-01-02'));
    }

    public function testUnknownCurrencyReturnsNull(): void
    {
        ecb_reference_rate_set_fetcher(fn() => self::sampleXml());
        self::assertNull(ecb_reference_rate_for_date($this->db, 'ZZZ', '2025-01-15'));
    }

    public function testMalformedDateReturnsNull(): void
    {
        ecb_reference_rate_set_fetcher(fn() => self::sampleXml());
        self::assertNull(ecb_reference_rate_for_date($this->db, 'DKK', 'not-a-date'));
    }

    public function testExchangeRateForDateUsesWeeklyEcbCache(): void
    {
        ecb_reference_rate_set_fetcher(fn() => self::sampleXml());
        // 2025-01-17 (Fri) inside W03 -> 7.4622.
        self::assertEqualsWithDelta(7.4622, exchange_rate_for_date($this->db, '2025-01-17'), 1e-9);
        // 2025-01-19 (Sun) inside W03 -> 7.4622 (same week).
        self::assertEqualsWithDelta(7.4622, exchange_rate_for_date($this->db, '2025-01-19'), 1e-9);
        // 2025-01-20 (Mon) inside W04 -> 7.4622 (last publication in W04 = 2025-01-17 in
        // sample); the previous-week fallback applies because the sample skips 01-20..24.
        self::assertEqualsWithDelta(7.4622, exchange_rate_for_date($this->db, '2025-01-20'), 1e-9);
    }

    public function testMonthRateStillWinsOverEcbFallback(): void {
        ecb_reference_rate_set_fetcher(fn() => self::sampleXml());
        save_month($this->db, ['year' => 2025, 'month' => 1, 'exchange_rate' => 9.99]);
        self::assertEqualsWithDelta(9.99, exchange_rate_for_date($this->db, '2025-01-15'), 1e-9);
    }

    public function testSameCurrencyRequiresNoEcbLookup(): void
    {
        $calls = 0;
        ecb_reference_rate_set_fetcher(function (string $url) use (&$calls): string {
            $calls++;
            return self::sampleXml();
        });
        // DB Currency = EUR / Helper = DKK, evidence is EUR -> same-currency, no FX needed.
        $this->db->exec("UPDATE database_settings SET currency = 'EUR' WHERE id = 1");
        set_currency_config($this->db, ['currency' => 'EUR', 'helper_currency' => 'DKK']);
        self::assertSame(100.0, evidence_amount_to_currency($this->db, 100.0, 'EUR', '2025-01-03'));
        self::assertSame(0, $calls, 'Same-currency path must not invoke the ECB fetcher');
    }

    public function testNonEurHelperBypassesEcbFallback(): void {
        // With the generalised cross-rate model, Helper != EUR is now supported when
        // both Helper and Currency are published by ECB. The cross-rate for a
        // Currency=SEK / Helper=DKK configuration must be derived from the two
        // EUR-anchored rates. SEK is not in the sample, so this test sets a Currency
        // the sample does publish but a Helper the sample does not, to verify the
        // null path is still deterministic.
        $this->db->exec("UPDATE database_settings SET currency = 'DKK' WHERE id = 1");
        set_currency_config($this->db, ['currency' => 'DKK', 'helper_currency' => 'NOK']);
        ecb_reference_rate_set_fetcher(function (string $url): string { throw new RuntimeException('should not be called'); });
        self::assertNull(exchange_rate_for_date($this->db, '2025-01-03'));
    }

    public function testEvidenceAmountConversionUsesEcbWeeklyFallbackForHistoricalRbaRow(): void {
        ecb_reference_rate_set_fetcher(fn() => self::sampleXml());
        set_currency_config($this->db, ['currency' => 'DKK', 'helper_currency' => 'EUR']);
        // 2025-04-18 (Sat, Good Friday) -> W16 -> latest publication 2025-04-17 -> 7.4630.
        self::assertEqualsWithDelta(74.63, evidence_amount_to_currency($this->db, 10.0, 'EUR', '2025-04-18'), 0.001);
        // 2025-01-03 (Fri) -> W01 -> 7.4612.
        self::assertEqualsWithDelta(74.61, evidence_amount_to_currency($this->db, 10.0, 'EUR', '2025-01-03'), 0.001);
        // 2025-12-25 (Thu, Christmas) -> W52 -> 7.4700.
        self::assertEqualsWithDelta(74.70, evidence_amount_to_currency($this->db, 10.0, 'EUR', '2025-12-25'), 0.001);
    }

    public function testOriginalStatementAmountAndCurrencyArePreserved(): void {
        ecb_reference_rate_set_fetcher(fn() => self::sampleXml());
        require_once __DIR__ . '/Fixtures/SyntheticStatementGenerator.php';
        $accountNumber = bin2hex(random_bytes(8));
        $path = \Tests\Php\Fixtures\SyntheticStatementGenerator::build(
            \Tests\Php\Fixtures\SyntheticStatementGenerator::FORMAT_DEBIT_CREDIT,
            sys_get_temp_dir(),
            'debit-credit-' . bin2hex(random_bytes(3)),
            [
                ['booking_date' => '2025-04-15', 'amount' => -42.5, 'description' => 'Synthetic Test Bank EUR row', 'counterparty' => 'Synthetic Recipient', 'direction' => 'Isplata'],
                ['booking_date' => '2025-04-18', 'amount' => 200.00, 'description' => 'Synthetic Test Bank EUR row 2', 'counterparty' => 'Synthetic Payer', 'direction' => 'Uplata'],
            ],
            ['account_number' => $accountNumber, 'sheet_currency' => 'EUR']
        );
        $contents = (string)file_get_contents($path);
        self::assertNotFalse($contents);
        $accountId = save_account_definition($this->db, [
            'name' => 'Test Bank', 'account_number' => $accountNumber,
            'type' => 'asset', 'currency' => 'CURRENCY',
        ]);
        $preview = preview_statement_import($this->db, $accountId, basename($path), $contents);
        self::assertSame(2, (int)$preview['rows_found']);
        $result = import_statement_evidence($this->db, $accountId, basename($path), $contents);
        self::assertTrue($result['ok']);
        $row = $this->db->query('SELECT currency, amount FROM statement_rows ORDER BY id LIMIT 1')->fetch();
        self::assertSame('EUR', (string)$row['currency']);
        self::assertNotNull($row['amount']);
        self::assertGreaterThan(0, abs((float)$row['amount']));
    }

    public function testSyntheticRbaStatementReconcilesIntoHistoricalEurExpenseWithoutManualMonthRate(): void {
        ecb_reference_rate_set_fetcher(fn() => self::sampleXml());
        set_currency_config($this->db, ['currency' => 'DKK', 'helper_currency' => 'EUR']);
        require_once __DIR__ . '/Fixtures/SyntheticStatementGenerator.php';
        $accountNumber = bin2hex(random_bytes(8));
        $path = \Tests\Php\Fixtures\SyntheticStatementGenerator::build(
            \Tests\Php\Fixtures\SyntheticStatementGenerator::FORMAT_DEBIT_CREDIT,
            sys_get_temp_dir(),
            'debit-credit-history-' . bin2hex(random_bytes(3)),
            [
                ['booking_date' => '2025-04-18', 'amount' => -42.5, 'description' => 'Synthetic Test Bank EUR reconciliation row', 'counterparty' => 'Synthetic Recipient', 'direction' => 'Isplata'],
            ],
            ['account_number' => $accountNumber, 'sheet_currency' => 'EUR']
        );
        $contents = (string)file_get_contents($path);
        self::assertNotFalse($contents);
        $accountId = save_account_definition($this->db, [
            'name' => 'Test Bank', 'account_number' => $accountNumber,
            'type' => 'asset', 'currency' => 'CURRENCY',
        ]);
        $envelopeId = save_envelope($this->db, [
            'name' => 'Daily', 'role' => 'daily', 'currency' => 'CURRENCY',
        ]);
        $result = import_statement_evidence($this->db, $accountId, basename($path), $contents);
        self::assertTrue($result['ok']);
        $rowId = (int)$this->db->query('SELECT id FROM statement_rows ORDER BY id LIMIT 1')->fetchColumn();
        $transactionId = statement_reconciliation_create_expense($this->db, $rowId, [
            'envelope_id' => $envelopeId, 'spent_date' => '2025-04-18',
        ]);
        $tx = $this->db->query('SELECT amount, payment_account_id, kind FROM transactions WHERE id=' . $transactionId)->fetch();
        self::assertSame('expense', (string)$tx['kind']);
        self::assertSame($accountId, (int)$tx['payment_account_id']);
        self::assertGreaterThan(0, (float)$tx['amount']);
    }

    public function testNoDuplicateCurrencyRecordsCreated(): void {
        ecb_reference_rate_set_fetcher(fn() => self::sampleXml());
        ecb_reference_rate_for_date($this->db, 'DKK', '2025-01-13');
        ecb_reference_rate_for_date($this->db, 'DKK', '2025-01-17');
        ecb_reference_rate_for_date($this->db, 'DKK', '2025-04-18');
        // One weekly row per ISO week the user might query, with bridging weeks
        // populated from the nearest preceding published week.
        $count = (int)$this->db->query('SELECT COUNT(*) FROM exchange_rate_cache')->fetchColumn();
        self::assertSame(53, $count, 'One cached row per ISO week the sample covers');
        $rows = $this->db->query('SELECT target_currency, week_key FROM exchange_rate_cache ORDER BY week_key')->fetchAll();
        foreach ($rows as $row) self::assertSame('DKK', $row['target_currency']);
    }

    public function testWeeklyCacheIsReusedForAllDaysInWeekIncludingWeekend(): void {
        ecb_reference_rate_set_fetcher(fn() => self::sampleXml());
        // Trigger W03 first via Tuesday lookup.
        $tuesday = ecb_reference_rate_for_date($this->db, 'DKK', '2025-01-14');
        // Saturday of the same week must return the identical cached row.
        $saturday = ecb_reference_rate_for_date($this->db, 'DKK', '2025-01-18');
        self::assertSame($tuesday, $saturday);
        self::assertSame('2025-W03', $saturday['week_key']);
        self::assertSame('2025-01-17', $saturday['published_date']);
    }
}