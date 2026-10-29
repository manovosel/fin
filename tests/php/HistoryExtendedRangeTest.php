<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseTestCase.php';

/**
 * The history endpoint also serves the Months that only exist as actual
 * spending, that is the Months the funding-recommendation engine already
 * knows because a Transaction was recorded in them.
 */
final class HistoryExtendedRangeTest extends DatabaseTestCase
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

    public function testMonthsOutsideTheRetainedDataReportActualSpendingOnly(): void
    {
        $retainedMonth = $this->createMonth(2026, 7);
        $daily = $this->createEnvelope('Envelope A', 'daily');
        $reserved = $this->createEnvelope('Envelope B', 'reserved');
        $this->saveEntry($retainedMonth, $daily, 1000, 'paid', '2026-07-01');
        $this->transaction(null, $daily, 'Envelope A', 300, '2026-07-05');

        // January 2025 is outside the retained running data: only a
        // Transaction knows that the Month exists.
        $this->transaction(null, $daily, 'Envelope A', 1200, '2025-01-10');
        $this->namedTransaction('Envelope B', 5000, '2025-01-05');

        $history = build_history_response($this->db, '2025-01', '2026-07');
        self::assertCount(2, $history['months']);

        $extended = $history['months'][0];
        self::assertSame(2025, $extended['year']);
        self::assertSame(1, $extended['month']);
        self::assertSame('January 2025', $extended['label']);
        self::assertSame('past', $extended['period']);
        self::assertSame('2025-01-31', $extended['cutoff']);
        self::assertSame('actual_only', $extended['coverage']);

        // Plan, Funding and Balance do not exist for such a Month.
        self::assertSame(['actual_expenses'], array_keys($extended['overview']));
        self::assertEqualsWithDelta(6200, $extended['overview']['actual_expenses'], 0.001);
        foreach (['daily', 'reserved', 'savings'] as $role) {
            self::assertSame(['actual'], array_keys($extended[$role]['totals']));
        }
        self::assertCount(1, $extended['daily']['rows']);
        self::assertSame('Envelope A', $extended['daily']['rows'][0]['envelope']);
        self::assertEqualsWithDelta(1200, $extended['daily']['rows'][0]['actual'], 0.001);
        foreach (['planned', 'funded', 'difference', 'balance'] as $absent) {
            self::assertArrayNotHasKey($absent, $extended['daily']['rows'][0]);
        }
        self::assertCount(1, $extended['reserved']['rows']);
        self::assertSame('Envelope B', $extended['reserved']['rows'][0]['envelope']);
        self::assertEqualsWithDelta(5000, $extended['reserved']['rows'][0]['actual'], 0.001);
        self::assertSame([], $extended['savings']['rows']);
        self::assertEqualsWithDelta(0, $extended['savings']['totals']['actual'], 0.001);

        // The Month stays free of the detail that does not belong to it.
        $extendedJson = json_encode($extended, JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('planned_allocations', $extendedJson);
        self::assertStringNotContainsString('"funded"', $extendedJson);
        self::assertStringNotContainsString('"balance"', $extendedJson);

        // A retained Month is still the report model, unchanged.
        $retained = $history['months'][1];
        self::assertSame('full', $retained['coverage']);
        $model = build_pdf_report_model($this->db, get_report_data($this->db, '2026-07', '2026-07'));
        self::assertSame($model['months'][0]['daily'], $retained['daily']);
        self::assertSame($model['months'][0]['reserved'], $retained['reserved']);
        self::assertSame($model['months'][0]['savings'], $retained['savings']);
        self::assertSame(['planned_allocations', 'actual_expenses'], array_keys($retained['overview']));
    }

    public function testTheExtendedRangeUsesTheSameActualAsTheFundingRecommendations(): void
    {
        $daily = $this->createEnvelope('Envelope A', 'daily');
        $this->createMonth(2026, 7);
        $this->transaction(null, $daily, 'Envelope A', 1200, '2025-01-10');
        $this->transaction(null, $daily, 'Envelope A', 900, '2025-02-08');
        $this->transaction(null, $daily, 'Envelope A', 150, '2025-03-04');

        $history = build_history_response($this->db, '2025-01', '2025-03');
        self::assertCount(3, $history['months']);
        $actuals = [];
        foreach ($history['months'] as $month) {
            $actuals[sprintf('%04d-%02d', $month['year'], $month['month'])] = $month['daily']['rows'][0]['actual'];
        }
        self::assertSame([1200.0, 900.0, 150.0], array_values($actuals));

        $recommendations = build_funding_recommendations($this->db, 2026, 7, '2026-08-14');
        $observations = [];
        foreach ($recommendations['recommendations'] as $recommendation) {
            if ((int)$recommendation['envelope_id'] !== $daily) continue;
            foreach ($recommendation['observations'] as $observation) $observations[$observation['month']] = (float)$observation['actual'];
        }
        self::assertSame(1200.0, $observations['2025-01']);
        self::assertSame(900.0, $observations['2025-02']);
        self::assertSame(150.0, $observations['2025-03']);
    }

    public function testAMonthOutsideTheKnownRangeHasNothingToReport(): void
    {
        $daily = $this->createEnvelope('Envelope A', 'daily');
        $this->createMonth(2026, 7);
        $this->transaction(null, $daily, 'Envelope A', 300, '2026-07-05');

        self::assertSame([], history_retained_months($this->db, 2024, 1, 2024, 12));
        self::assertSame([], history_actual_only_months($this->db, '2024-01', '2024-12', []));
        self::assertSame(['2026-07'], history_retained_months($this->db, 2026, 1, 2026, 12));
        self::assertSame([], history_actual_only_months($this->db, '2024-01', '2024-12', ['2026-07']));
    }

    public function testSpendingAfterTodayIsNotActual(): void
    {
        $daily = $this->createEnvelope('Envelope A', 'daily');
        $this->transaction(null, $daily, 'Envelope A', 100, '2026-08-10');
        $this->transaction(null, $daily, 'Envelope A', 900, '2026-08-20');

        $history = build_history_response($this->db, '2026-08', '2026-08');
        self::assertCount(1, $history['months']);
        $month = $history['months'][0];
        self::assertSame('actual_only', $month['coverage']);
        self::assertSame('current', $month['period']);
        self::assertSame('2026-08-14', $month['cutoff']);
        self::assertCount(1, $month['daily']['rows']);
        self::assertEqualsWithDelta(100, $month['daily']['rows'][0]['actual'], 0.001);
        self::assertEqualsWithDelta(100, $month['overview']['actual_expenses'], 0.001);
    }

    public function testSavingsGoalsReportTargetAndProgressWithoutADeadline(): void
    {
        $monthId = $this->createMonth(2026, 7);
        $savings = $this->createEnvelope('Envelope C', 'savings');
        save_envelope($this->db, [
            'id' => $savings,
            'name' => 'Envelope C',
            'role' => 'savings',
            'currency' => 'CURRENCY',
            'savings_goal' => 10000,
        ]);
        $this->saveEntry($monthId, $savings, 4000, 'paid', '2026-07-01');
        $this->createEnvelope('Envelope A', 'daily');

        $history = build_history_response($this->db, '2026-07', '2026-07');
        self::assertCount(1, $history['savings_goals']);
        $goal = $history['savings_goals'][0];
        self::assertSame($savings, $goal['envelope_id']);
        self::assertSame('Envelope C', $goal['envelope']);
        self::assertEqualsWithDelta(10000, $goal['goal'], 0.001);
        self::assertEqualsWithDelta(4000, $goal['balance'], 0.001);
        self::assertEqualsWithDelta(40, $goal['progress_percent'], 0.001);
        // FIN stores no goal deadline, so none is invented.
        self::assertArrayNotHasKey('deadline', $goal);
        self::assertStringNotContainsString('deadline', json_encode($history, JSON_UNESCAPED_UNICODE));

        // A Daily envelope never carries a goal.
        self::assertSame(['Envelope C'], array_column($history['savings_goals'], 'envelope'));
    }

    private function saveEntry(int $monthId, int $envelopeId, float $amount, string $status, string $dueDate): int
    {
        return save_entry($this->db, [
            'month_id' => $monthId,
            'envelope_id' => $envelopeId,
            'type' => 'expense',
            'amount' => $amount,
            'status' => $status,
            'due_date' => $dueDate,
        ]);
    }

    private function transaction(?int $monthId, int $envelopeId, string $envelope, float $amount, string $date): int
    {
        return save_transaction($this->db, [
            'month_id' => $monthId,
            'envelope_id' => $envelopeId,
            'envelope' => $envelope,
            'amount' => $amount,
            'spent_date' => $date,
            'note' => '',
        ]);
    }

    /**
     * A Transaction that only carries an Envelope name, as older imported
     * history does.
     */
    private function namedTransaction(string $envelope, float $amount, string $date): int
    {
        $stmt = $this->db->prepare('INSERT INTO transactions (month_id,envelope_id,envelope,amount,note,spent_date,is_automatic) VALUES (NULL,NULL,:envelope,:amount,\'\',:date,0)');
        $stmt->execute([':envelope' => $envelope, ':amount' => $amount, ':date' => $date]);
        return (int)$this->db->lastInsertId();
    }
}
