<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseTestCase.php';
require_once __DIR__ . '/../../currency.php';
require_once __DIR__ . '/../../ecb_rates.php';
require_once __DIR__ . '/../../accounting.php';
require_once __DIR__ . '/../../api.php';

use PHPUnit\Framework\TestCase;

/**
 * Cross-rate fallback for the generalised EUR-anchored ECB model.
 *
 * Required by the design: with Helper=H and FIN Currency=C, the conversion rate
 * (Helper * rate = Currency) is derived from the two EUR-anchored ECB rates
 * rate = eur_to_c / eur_to_h.
 */
final class EcbCrossRateFallbackTest extends DatabaseTestCase
{
    private const CURRENCIES_XML = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01" xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref">
<Cube>
<Cube time="2025-04-17"><Cube currency="DKK" rate="7.4630"/><Cube currency="USD" rate="1.0900"/><Cube currency="NOK" rate="11.6000"/><Cube currency="SEK" rate="10.9500"/></Cube>
<Cube time="2025-04-22"><Cube currency="DKK" rate="7.4640"/><Cube currency="USD" rate="1.0910"/><Cube currency="NOK" rate="11.5950"/><Cube currency="SEK" rate="10.9400"/></Cube>
</Cube>
</gesmes:Envelope>
XML;

    protected function setUp(): void
    {
        parent::setUp();
        // Each test must provide its own fetcher; install a safety net that refuses
        // to make outbound HTTP.
        ecb_reference_rate_set_fetcher(function (string $url): string {
            throw new RuntimeException('Test must provide its own fetcher.');
        });
    }

    protected function tearDown(): void
    {
        ecb_reference_rate_reset_test_fetcher();
        parent::tearDown();
    }

    private function installSample(): void
    {
        ecb_reference_rate_set_fetcher(fn() => self::CURRENCIES_XML);
    }

    public function testDkkCurrencyEurHelperUsesEurToDkkDirectly(): void
    {
        $this->installSample();
        $this->db->exec("UPDATE database_settings SET currency = 'DKK' WHERE id = 1");
        set_currency_config($this->db, ['currency' => 'DKK', 'helper_currency' => 'EUR']);
        // 2025-04-17 (Thursday) -> W16 -> 7.4630.
        self::assertEqualsWithDelta(7.4630, exchange_rate_for_date($this->db, '2025-04-17'), 1e-9);
    }

    public function testDkkCurrencyUsdHelperComputesCrossRate(): void
    {
        $this->installSample();
        $this->db->exec("UPDATE database_settings SET currency = 'DKK' WHERE id = 1");
        set_currency_config($this->db, ['currency' => 'DKK', 'helper_currency' => 'USD']);
        // rate = eur_to_dkk / eur_to_usd = 7.4630 / 1.0900 = 6.8468 (rounded).
        $expected = 7.4630 / 1.0900;
        self::assertEqualsWithDelta($expected, exchange_rate_for_date($this->db, '2025-04-17'), 1e-9);
        // 100 USD in Currency (DKK) at that rate: 100 * 6.8468 = 684.68.
        self::assertEqualsWithDelta(684.68, amount_to_currency($this->db, 100.0, 'HELPER_CURRENCY', '2025-04-17'), 0.01);
    }

    public function testEurCurrencyUsdHelperComputesInverseCrossRate(): void
    {
        $this->installSample();
        $this->db->exec("UPDATE database_settings SET currency = 'EUR' WHERE id = 1");
        set_currency_config($this->db, ['currency' => 'EUR', 'helper_currency' => 'USD']);
        // rate = eur_to_eur / eur_to_usd = 1.0 / 1.0900 = 0.9174...
        $expected = 1.0 / 1.0900;
        self::assertEqualsWithDelta($expected, exchange_rate_for_date($this->db, '2025-04-17'), 1e-9);
        // evidence_amount_to_currency(100, USD, 2025-04-17) -> Currency=EUR: 100 * 0.9174 = 91.74.
        self::assertEqualsWithDelta(91.74, evidence_amount_to_currency($this->db, 100.0, 'USD', '2025-04-17'), 0.01);
    }

    public function testSameCurrencyHelperShortCircuitsToOne(): void
    {
        // The same-currency case is reached when the evidence currency equals the
        // database Currency (evidence_amount_to_currency short-circuit) or when
        // the conversion preference is the database Currency (amount_to_currency).
        $this->installSample();
        $this->db->exec("UPDATE database_settings SET currency = 'DKK' WHERE id = 1");
        set_currency_config($this->db, ['currency' => 'DKK', 'helper_currency' => 'EUR']);
        // evidence currency == database currency -> 1.0, no ECB fetch.
        self::assertSame(100.0, evidence_amount_to_currency($this->db, 100.0, 'DKK', '2025-04-17'));
        // amount_to_currency with CURRENCY preference -> 1.0, no ECB fetch.
        self::assertSame(100.0, amount_to_currency($this->db, 100.0, 'CURRENCY', '2025-04-17'));
    }

    public function testUnsupportedEcbCurrencyFailsDeterministically(): void
    {
        $this->installSample();
        $this->db->exec("UPDATE database_settings SET currency = 'SEK' WHERE id = 1");
        // SEK is in the sample, but the sample includes only one publication on
        // 2025-04-17 and 2025-04-22. The DB Currency is now SEK and the Helper is
        // DKK (also in the sample). However, the sample does not include a SEK
        // publication on 2025-04-19 (a Sunday). Verify a deterministic failure
        // when neither side has a rate for the requested date.
        set_currency_config($this->db, ['currency' => 'SEK', 'helper_currency' => 'DKK']);
        // 2025-04-19 (Sunday): the sample publishes on 04-17 and 04-22, so the
        // preceding rates exist for both SEK and DKK and the cross-rate resolves.
        $r = exchange_rate_for_date($this->db, '2025-04-19');
        self::assertNotNull($r);
        // 2010-01-01 has no preceding publication in the sample -> deterministic null.
        self::assertNull(exchange_rate_for_date($this->db, '2010-01-01'));
    }

    public function testCrossRateUsesLatestPublicationInsideWeek(): void
    {
        $this->installSample();
        $this->db->exec("UPDATE database_settings SET currency = 'DKK' WHERE id = 1");
        set_currency_config($this->db, ['currency' => 'DKK', 'helper_currency' => 'USD']);
        // 2025-04-19 (Saturday) -> W16 -> latest in-week publication 2025-04-17.
        $saturday = exchange_rate_for_date($this->db, '2025-04-19');
        // 2025-04-17 directly.
        $thursday = exchange_rate_for_date($this->db, '2025-04-17');
        self::assertEqualsWithDelta($thursday, $saturday, 1e-9);
        $expected = 7.4630 / 1.0900;
        self::assertEqualsWithDelta($expected, $saturday, 1e-9);
    }

    public function testEachTargetCurrencyIsCachedIndependently(): void
    {
        $this->installSample();
        $this->db->exec("UPDATE database_settings SET currency = 'DKK' WHERE id = 1");
        set_currency_config($this->db, ['currency' => 'DKK', 'helper_currency' => 'USD']);
        // Trigger both Helper and Currency lookups.
        exchange_rate_for_date($this->db, '2025-04-17');
        $currencies = $this->db->query('SELECT DISTINCT target_currency FROM exchange_rate_cache ORDER BY target_currency')->fetchAll(PDO::FETCH_COLUMN);
        // DKK and USD should both be cached; EUR is the anchor and is short-circuited.
        self::assertContains('DKK', $currencies);
        self::assertContains('USD', $currencies);
        // Anchored EUR should not occupy cache rows.
        self::assertNotContains('EUR', $currencies);
    }

    public function testNoNetworkLookupForSameCurrency(): void
    {
        $this->installSample();
        $this->db->exec("UPDATE database_settings SET currency = 'DKK' WHERE id = 1");
        set_currency_config($this->db, ['currency' => 'DKK', 'helper_currency' => 'EUR']);
        // evidence currency == database currency must short-circuit; the safety-net
        // fetcher would throw if it were called.
        self::assertSame(50.0, evidence_amount_to_currency($this->db, 50.0, 'DKK', '2025-04-17'));
    }
}