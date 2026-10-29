<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseTestCase.php';

/**
 * The safe-to-spend figure the Today screen shows, as the history action
 * reports it for the current Month.
 *
 * The figure itself is the shared rule (report.php:report_safe_to_spend), a
 * port of Finance.safeToSpend in finance.js: only Daily spend envelopes
 * contribute, their total is spread over the days that remain in the Month
 * (today included) and rounded down to a whole 10.
 */
final class HistorySafeToSpendTest extends DatabaseTestCase
{
    private string|false $previousToday;

    protected function setUp(): void
    {
        $this->previousToday = getenv('FIN_TODAY');
        putenv('FIN_TODAY=2026-08-14');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        if ($this->previousToday === false) putenv('FIN_TODAY');
        else putenv('FIN_TODAY=' . $this->previousToday);
        parent::tearDown();
    }

    public function testTheCurrentMonthCarriesTheFigureWithEveryInputAndIntermediateResult(): void
    {
        $monthId = $this->createMonth(2026, 8);
        $dailyA = $this->createEnvelope('Envelope A', 'daily');
        $dailyB = $this->createEnvelope('Envelope B', 'daily');
        $reserved = $this->createEnvelope('Envelope C', 'reserved');
        $savings = $this->createEnvelope('Envelope D', 'savings');

        $this->fundedAllocation($monthId, $dailyA, 3000, '2026-08-01');
        $this->fundedAllocation($monthId, $dailyB, 500, '2026-08-02');
        // Reserved and Savings money never contributes to safe-to-spend.
        $this->fundedAllocation($monthId, $reserved, 10000, '2026-08-02');
        $this->fundedAllocation($monthId, $savings, 20000, '2026-08-02');

        $history = build_history_response($this->db, '2026-08', '2026-08');
        $month = $history['months'][0];
        self::assertSame('current', $month['period']);
        self::assertArrayHasKey('safe_to_spend', $month);
        $safe = $month['safe_to_spend'];

        self::assertEqualsWithDelta(190.0, $safe['amount'], 0.001);
        self::assertSame('safe to spend today', $safe['label']);
        self::assertSame('2026-08-14', $safe['as_of']);
        self::assertEqualsWithDelta(3500.0, $safe['daily_total'], 0.001);
        self::assertSame([
            ['envelope_id' => $dailyA, 'envelope' => 'Envelope A', 'balance' => 3000.0],
            ['envelope_id' => $dailyB, 'envelope' => 'Envelope B', 'balance' => 500.0],
        ], $safe['daily_envelopes']);
        self::assertSame(31, $safe['days_in_month']);
        self::assertSame(18, $safe['remaining_days']);
        self::assertEqualsWithDelta(3500 / 18, $safe['raw_per_day'], 0.0001);
        self::assertEqualsWithDelta(190.0, $safe['rounded_amount'], 0.001);
        self::assertSame('floor(raw_per_day / 10) * 10, then max(0, rounded_amount)', $safe['rounding']);
        self::assertNull($safe['zero_reason']);
    }

    public function testTheFigureIsTheOneTheTodayScreenAndTheReportModelShow(): void
    {
        $monthId = $this->createMonth(2026, 8);
        $daily = $this->createEnvelope('Envelope A', 'daily');
        $this->fundedAllocation($monthId, $daily, 1234.5, '2026-08-03');

        $history = build_history_response($this->db, '2026-08', '2026-08');
        $safe = $history['months'][0]['safe_to_spend'];

        // The report model carries the same rule for the same Month, so the
        // history figure is the Today figure and not a second calculation.
        $model = build_pdf_report_model($this->db, get_report_data($this->db, '2026-08', '2026-08'));
        $modelSafe = $model['months'][0]['overview']['status']['daily']['safe'];
        self::assertSame($modelSafe['amount'], $safe['amount']);
        self::assertSame($modelSafe['label'], $safe['label']);
        self::assertSame($modelSafe['remainingDays'], $safe['remaining_days']);
        self::assertEqualsWithDelta($modelSafe['rawPerDay'], $safe['raw_per_day'], 0.0001);
    }

    public function testTheFigureCanBeReproducedByHandFromTheListedInputs(): void
    {
        $monthId = $this->createMonth(2026, 8);
        $dailyA = $this->createEnvelope('Envelope A', 'daily');
        $dailyB = $this->createEnvelope('Envelope B', 'daily');
        $this->fundedAllocation($monthId, $dailyA, 700.25, '2026-08-01');
        $this->fundedAllocation($monthId, $dailyB, 99.75, '2026-08-02');
        $this->transaction($monthId, $dailyB, 'Envelope B', 25, '2026-08-06', 'August spending');

        $safe = build_history_response($this->db, '2026-08', '2026-08')['months'][0]['safe_to_spend'];

        $sum = 0.0;
        foreach ($safe['daily_envelopes'] as $envelope) $sum += (float)$envelope['balance'];
        self::assertEqualsWithDelta($safe['daily_total'], $sum, 0.001);
        self::assertEqualsWithDelta($safe['daily_total'] / $safe['remaining_days'], $safe['raw_per_day'], 0.0001);
        $rounded = $safe['raw_per_day'] > 0 ? floor($safe['raw_per_day'] / 10) * 10 : 0.0;
        self::assertEqualsWithDelta($rounded, $safe['rounded_amount'], 0.001);
        self::assertEqualsWithDelta(max(0.0, $rounded), $safe['amount'], 0.001);
    }

    public function testOnlyTheCurrentMonthCarriesTheFigure(): void
    {
        $pastId = $this->createMonth(2026, 7);
        $currentId = $this->createMonth(2026, 8);
        $futureId = $this->createMonth(2026, 9);
        $daily = $this->createEnvelope('Envelope A', 'daily');
        $this->fundedAllocation($pastId, $daily, 1000, '2026-07-01');
        $this->fundedAllocation($currentId, $daily, 2000, '2026-08-01');
        $this->plannedAllocation($futureId, $daily, 3000);

        $history = build_history_response($this->db, '2026-07', '2026-09');
        self::assertCount(3, $history['months']);
        self::assertSame('past', $history['months'][0]['period']);
        self::assertSame('current', $history['months'][1]['period']);
        self::assertSame('future', $history['months'][2]['period']);
        self::assertArrayNotHasKey('safe_to_spend', $history['months'][0]);
        self::assertArrayNotHasKey('safe_to_spend', $history['months'][2]);
        self::assertArrayHasKey('safe_to_spend', $history['months'][1]);
        // The figure uses the persistent Envelope balance as of today, so the
        // July funding is still part of it: (1000 + 2000) over 18 remaining
        // days is 166.67 per day, rounded down to 160.
        self::assertEqualsWithDelta(3000.0, $history['months'][1]['safe_to_spend']['daily_total'], 0.001);
        self::assertEqualsWithDelta(160.0, $history['months'][1]['safe_to_spend']['amount'], 0.001);
    }

    public function testANegativeDailyTotalYieldsZeroAndNamesTheCondition(): void
    {
        $monthId = $this->createMonth(2026, 8);
        $daily = $this->createEnvelope('Envelope A', 'daily');
        $this->fundedAllocation($monthId, $daily, 100, '2026-08-01');
        $this->transaction($monthId, $daily, 'Envelope A', 300, '2026-08-05', 'August spending');

        $safe = build_history_response($this->db, '2026-08', '2026-08')['months'][0]['safe_to_spend'];

        self::assertEqualsWithDelta(-200.0, $safe['daily_total'], 0.001);
        self::assertTrue($safe['raw_per_day'] < 0);
        self::assertEqualsWithDelta(0.0, $safe['rounded_amount'], 0.001);
        self::assertEqualsWithDelta(0.0, $safe['amount'], 0.001);
        self::assertSame('over plan', $safe['label']);
        self::assertSame('negative_daily_total', $safe['zero_reason']);
    }

    public function testAPositiveTotalBelowTheRoundingStepYieldsZeroAndNamesTheCondition(): void
    {
        $monthId = $this->createMonth(2026, 8);
        $daily = $this->createEnvelope('Envelope A', 'daily');
        $this->fundedAllocation($monthId, $daily, 50, '2026-08-01');

        $safe = build_history_response($this->db, '2026-08', '2026-08')['months'][0]['safe_to_spend'];

        // 50 over the 18 remaining days is 2.78 per day: positive, but below
        // the rounding step of 10.
        self::assertEqualsWithDelta(50.0, $safe['daily_total'], 0.001);
        self::assertTrue($safe['raw_per_day'] > 0);
        self::assertTrue($safe['raw_per_day'] < 10);
        self::assertEqualsWithDelta(0.0, $safe['rounded_amount'], 0.001);
        self::assertEqualsWithDelta(0.0, $safe['amount'], 0.001);
        self::assertSame('', $safe['label']);
        self::assertSame('below_rounding_step', $safe['zero_reason']);
    }

    public function testAZeroDailyTotalYieldsZeroAndNamesTheCondition(): void
    {
        $monthId = $this->createMonth(2026, 8);
        $daily = $this->createEnvelope('Envelope A', 'daily');
        $this->fundedAllocation($monthId, $daily, 100, '2026-08-01');
        $this->transaction($monthId, $daily, 'Envelope A', 100, '2026-08-05', 'August spending');

        $safe = build_history_response($this->db, '2026-08', '2026-08')['months'][0]['safe_to_spend'];

        self::assertEqualsWithDelta(0.0, $safe['daily_total'], 0.001);
        self::assertEqualsWithDelta(0.0, $safe['raw_per_day'], 0.001);
        self::assertEqualsWithDelta(0.0, $safe['rounded_amount'], 0.001);
        self::assertEqualsWithDelta(0.0, $safe['amount'], 0.001);
        self::assertSame('', $safe['label']);
        self::assertSame('zero_daily_total', $safe['zero_reason']);
    }

    public function testTheLastDayOfTheMonthLeavesOneRemainingDay(): void
    {
        putenv('FIN_TODAY=2026-08-31');
        $monthId = $this->createMonth(2026, 8);
        $daily = $this->createEnvelope('Envelope A', 'daily');
        $this->fundedAllocation($monthId, $daily, 100, '2026-08-01');

        $safe = build_history_response($this->db, '2026-08', '2026-08')['months'][0]['safe_to_spend'];

        self::assertSame(31, $safe['days_in_month']);
        self::assertSame(1, $safe['remaining_days']);
        self::assertEqualsWithDelta(100.0, $safe['raw_per_day'], 0.001);
        self::assertEqualsWithDelta(100.0, $safe['rounded_amount'], 0.001);
        self::assertEqualsWithDelta(100.0, $safe['amount'], 0.001);
        self::assertSame('safe to spend today', $safe['label']);
        self::assertNull($safe['zero_reason']);
    }

    public function testTheCurrentMonthCarriesTheFigureEvenWhenItOnlyExistsAsRecordedSpending(): void
    {
        $daily = $this->createEnvelope('Envelope A', 'daily');
        $this->transaction(null, $daily, 'Envelope A', 100, '2026-08-05', 'August spending');

        $history = build_history_response($this->db, '2026-08', '2026-08');
        $month = $history['months'][0];

        self::assertSame('actual_only', $month['coverage']);
        self::assertArrayHasKey('safe_to_spend', $month);
        self::assertEqualsWithDelta(-100.0, $month['safe_to_spend']['daily_total'], 0.001);
        self::assertEqualsWithDelta(0.0, $month['safe_to_spend']['amount'], 0.001);
        self::assertSame('negative_daily_total', $month['safe_to_spend']['zero_reason']);
    }

    private function fundedAllocation(int $monthId, int $envelopeId, float $amount, string $date): int
    {
        return save_entry($this->db, [
            'month_id' => $monthId,
            'envelope_id' => $envelopeId,
            'type' => 'expense',
            'amount' => $amount,
            'status' => 'paid',
            'due_date' => $date,
        ]);
    }

    private function plannedAllocation(int $monthId, int $envelopeId, float $amount): int
    {
        return save_entry($this->db, [
            'month_id' => $monthId,
            'envelope_id' => $envelopeId,
            'type' => 'expense',
            'amount' => $amount,
            'status' => 'planned',
            'due_date' => '2026-08-02',
        ]);
    }

    private function transaction(?int $monthId, int $envelopeId, string $envelope, float $amount, string $date, string $note): int
    {
        return save_transaction($this->db, [
            'month_id' => $monthId,
            'envelope_id' => $envelopeId,
            'envelope' => $envelope,
            'amount' => $amount,
            'spent_date' => $date,
            'note' => $note,
        ]);
    }
}
